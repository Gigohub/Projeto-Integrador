<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Command;
use App\Models\Reading;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class TelemetryController extends Controller
{
    private const DEFAULT_CALIB_FLOW = '3.94 * exp(0.617 * x)';
    private const DEFAULT_CALIB_TEMP = '10.4 * x + 0.0795';

    // Constantes físicas do duto LTR701 (diâmetro interno ~50 mm)
    private const AMBIENT_TEMP  = 22.0;      // °C
    private const P_ATM_PA      = 101325.0;  // Pa (1 atm)
    private const R_AIR         = 287.05;    // J/(kg·K)
    private const DUCT_AREA_M2  = 0.0019635; // pi * (0.025 m)^2
    private const K_DUCT_LOSS   = 3.2;       // Perda de carga do conjunto
    private const TAU_FLOW_BASE = 0.964;     // 1 / 1.037 s (Ativ. 10)
    private const C_TH_BASE     = 2.500;     // Constante térmica base (s) em 25 kg/h
    private const Q_REF_KGH     = 25.0;      // Vazão de referência (~25% do ventilador)

    private const DEVICES_CACHE_KEY = 'ltr701_known_devices_v1';
    private const ONLINE_TTL_SEC    = 15;

    /**
     * POST /api/readings (ESP32 / MATLAB / cliente.py envia leituras físicas via Wi-Fi)
     */
    public function storeReading(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => ['nullable', 'string', 'max:40'],
            'variables' => ['nullable', 'array'],
            'temp'      => ['nullable', 'numeric'],
            'flow'      => ['nullable', 'numeric'],
            'temp_v'    => ['nullable', 'numeric'],
            'flow_v'    => ['nullable', 'numeric'],
        ]);

        $deviceId = trim((string) $request->input('device_id', 'ESP32-LTR701')) ?: 'ESP32-LTR701';
        $this->touchDevice($deviceId, $request, 'POST /api/readings');

        $vars  = $request->input('variables', []);
        $state = $this->currentControlState();

        foreach (['temp', 'flow', 'temp_v', 'flow_v', 'u_temp', 'u_flow', 'pressure_pa'] as $key) {
            if (!isset($vars[$key]) && $request->has($key)) {
                $vars[$key] = (float) $request->input($key);
            }
        }

        if (!isset($vars['flow']) && isset($vars['flow_v']) && is_numeric($vars['flow_v'])) {
            $vars['flow'] = round($this->safeEvalMath($state['calib_flow'], (float) $vars['flow_v']), 3);
        }

        if (!isset($vars['temp']) && isset($vars['temp_v']) && is_numeric($vars['temp_v'])) {
            $vars['temp'] = round($this->safeEvalMath($state['calib_temp'], (float) $vars['temp_v']), 3);
        }

        if (!isset($vars['temp']) && !isset($vars['flow'])) {
            return response()->json([
                'message' => 'Envie ao menos uma variável numérica (temp, flow, temp_v ou flow_v).',
            ], 422);
        }

        $flow = isset($vars['flow']) ? (float) $vars['flow'] : 0.0;
        $temp = isset($vars['temp']) ? (float) $vars['temp'] : self::AMBIENT_TEMP;
        $phys = $this->computePhysics($flow, $temp);

        $vars = array_merge($phys, $vars, [
            'sp_flow' => $state['setpoint_flow'],
            'sp_temp' => $state['setpoint_temp'],
        ]);

        foreach ($vars as $k => $v) {
            if (is_numeric($v)) {
                $vars[$k] = round((float) $v, 4);
            }
        }

        $reading = new Reading();
        $reading->device_id = $deviceId;
        $reading->user_id   = null;
        $reading->payload   = $vars;
        $reading->ts        = now();
        $reading->save();

        return response()->json([
            'status'    => 'ok',
            'id'        => $reading->id,
            'device_id' => $deviceId,
            'saved'     => $vars,
            'ts'        => $reading->ts->toIso8601String(),
        ], 201);
    }

    /**
     * GET /api/commands (ESP32 / MATLAB consulta comandos pendentes)
     */
    public function pendingCommands(Request $request): JsonResponse
    {
        $deviceId = $request->query('device_id') ?: $request->header('X-Device-Id');
        if ($deviceId) {
            $this->touchDevice((string) $deviceId, $request, 'GET /api/commands');
        }

        $commands = Command::where('acked', false)
            ->orderBy('id')
            ->get()
            ->map(fn (Command $cmd) => [
                'id'    => $cmd->id,
                'name'  => $cmd->name,
                'value' => $cmd->decoded_value,
                'ts'    => $cmd->ts?->toIso8601String(),
            ])
            ->values();

        return response()->json($commands);
    }

    /**
     * POST /api/commands/ack (ESP32 / MATLAB confirma recebimento)
     */
    public function ackCommands(Request $request): JsonResponse
    {
        $deviceId = $request->input('device_id') ?: $request->header('X-Device-Id');
        if ($deviceId) {
            $this->touchDevice((string) $deviceId, $request, 'POST /api/commands/ack');
        }

        $ids   = $request->input('ids');
        $query = Command::where('acked', false);

        if (is_array($ids) && count($ids) > 0) {
            $query->whereIn('id', $ids);
        }

        return response()->json([
            'status' => 'ok',
            'acked'  => $query->update(['acked' => true]),
        ]);
    }

    /**
     * GET /panel/history (Retorna histórico real + estado + lista de placas detectadas)
     */
    public function history(Request $request): JsonResponse
    {
        $limit    = min(max((int) $request->query('limit', 80), 1), 500);
        $deviceId = $request->query('device_id');

        $query = Reading::orderByDesc('id')->limit($limit);

        if ($deviceId && $deviceId !== 'ALL') {
            $query->where('device_id', $deviceId);
        } elseif ($request->boolean('real_only', true)) {
            $query->where('device_id', 'not like', 'SIM-%');
        }

        $readings = $query->get()
            ->reverse()
            ->values()
            ->map(fn (Reading $r) => $this->formatReading($r));

        return response()->json([
            'readings' => $readings,
            'state'    => $this->currentControlState(),
            'devices'  => $this->listKnownDevices(),
        ]);
    }

    /**
     * POST /panel/send (Comandos da Aba Real para hardware ESP32 / MATLAB)
     */
    public function sendCommand(Request $request): JsonResponse
    {
        $allowed = [
            'enable_flow', 'setpoint_flow', 'kp_flow', 'ki_flow', 'calib_flow',
            'enable_temp', 'setpoint_temp', 'kp_temp', 'ki_temp', 'calib_temp',
            'enable', 'setpoint',
        ];

        $validated = $request->validate([
            'name'      => ['required', 'string', 'in:' . implode(',', $allowed)],
            'value'     => ['required'],
            'device_id' => ['nullable', 'string', 'max:40'],
        ]);

        $name  = $validated['name'];
        $value = $validated['value'];

        if (in_array($name, ['calib_flow', 'calib_temp'], true)) {
            $expr = trim((string) $value);
            try {
                $this->safeEvalMath($expr, 1.0);
                $this->safeEvalMath($expr, 5.0);
            } catch (RuntimeException $e) {
                return response()->json(['message' => 'Expressão inválida: ' . $e->getMessage()], 422);
            }
            $value = $expr;
        } else {
            if (!is_numeric($value)) {
                return response()->json(['message' => 'O valor deve ser numérico.'], 422);
            }

            if (in_array($name, ['enable_flow', 'enable_temp', 'enable'], true)) {
                $value = ((int) $value) === 1 ? 1 : 0;
            } elseif ($name === 'setpoint_flow') {
                $value = round((float) $value, 2);
                if ($value < 0 || $value > 150) {
                    return response()->json(['message' => 'Setpoint de vazão deve estar entre 0 e 150 kg/h.'], 422);
                }
            } elseif (in_array($name, ['setpoint_temp', 'setpoint'], true)) {
                $value = round((float) $value, 2);
                if ($value < 15 || $value > 100) {
                    return response()->json(['message' => 'Setpoint de temperatura deve estar entre 15 e 100 °C.'], 422);
                }
            } else {
                $value = round((float) $value, 4);
                if ($value < 0 || $value > 10) {
                    return response()->json(['message' => 'Ganho fora da faixa permitida (0 a 10).'], 422);
                }
            }
        }

        $cmd = new Command();
        $cmd->name          = $name;
        $cmd->decoded_value = $value;
        $cmd->user_id       = $request->user()->id;
        $cmd->acked         = false;
        $cmd->ts            = now();
        $cmd->save();

        $target = $validated['device_id'] ?? 'BROADCAST';
        AuditLog::record("Comando real [{$target}]: {$name} = {$value}", $request->user()->id);

        return response()->json([
            'status' => 'ok',
            'state'  => $this->currentControlState(),
        ], 201);
    }

    /**
     * POST /panel/simulate
     * Reaproveitado para gravar leituras reais recebidas pela porta USB Serial (Web Serial API)
     * com criptografia no MySQL quando o ESP32 está plugado no cabo USB.
     */
    public function simulate(Request $request): JsonResponse
    {
        if ($request->input('source') !== 'usb') {
            return response()->json([
                'message' => 'A Sombra Digital roda isolada no cliente; este endpoint persiste apenas telemetria USB autenticada.',
            ], 400);
        }

        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:40'],
            'flow'      => ['nullable', 'numeric'],
            'temp'      => ['nullable', 'numeric'],
            'flow_v'    => ['nullable', 'numeric'],
            'temp_v'    => ['nullable', 'numeric'],
            'u_flow'    => ['nullable', 'numeric'],
            'u_temp'    => ['nullable', 'numeric'],
        ]);

        $state = $this->currentControlState();
        $flow  = $validated['flow'] ?? null;
        $temp  = $validated['temp'] ?? null;

        if ($flow === null && isset($validated['flow_v'])) {
            $flow = round($this->safeEvalMath($state['calib_flow'], (float) $validated['flow_v']), 3);
        }
        if ($temp === null && isset($validated['temp_v'])) {
            $temp = round($this->safeEvalMath($state['calib_temp'], (float) $validated['temp_v']), 3);
        }

        $phys = $this->computePhysics((float) ($flow ?? 0.0), (float) ($temp ?? self::AMBIENT_TEMP));

        $payload = array_merge($phys, [
            'flow'    => $flow !== null ? round((float) $flow, 3) : null,
            'temp'    => $temp !== null ? round((float) $temp, 3) : null,
            'u_flow'  => isset($validated['u_flow']) ? round((float) $validated['u_flow'], 3) : 0.0,
            'u_temp'  => isset($validated['u_temp']) ? round((float) $validated['u_temp'], 3) : 0.0,
            'sp_flow' => $state['setpoint_flow'],
            'sp_temp' => $state['setpoint_temp'],
        ]);

        $reading = new Reading();
        $reading->device_id = $validated['device_id'];
        $reading->user_id   = $request->user()->id;
        $reading->payload   = $payload;
        $reading->ts        = now();
        $reading->save();

        return response()->json([
            'status'  => 'ok',
            'reading' => $this->formatReading($reading),
        ], 201);
    }

    /**
     * GET /panel/logs (Exclusivo admin)
     */
    public function logs(): JsonResponse
    {
        $logs    = AuditLog::orderByDesc('id')->limit(100)->get();
        $userIds = $logs->pluck('user_id')->filter()->unique()->values();
        $emails  = User::whereIn('id', $userIds)->pluck('email', 'id');

        return response()->json([
            'logs' => $logs->map(fn (AuditLog $log) => [
                'id'     => $log->id,
                'user'   => $log->user_id ? ($emails[$log->user_id] ?? "ID #{$log->user_id}") : 'Sistema / API',
                'ip'     => $log->ip ?? '-',
                'action' => $log->action,
                'ts'     => $log->ts?->format('d/m/Y H:i:s'),
            ]),
        ]);
    }

    private function touchDevice(string $deviceId, Request $request, string $endpoint): void
    {
        $devices = Cache::get(self::DEVICES_CACHE_KEY, []);
        $devices[$deviceId] = [
            'device_id' => $deviceId,
            'ip'        => $request->ip(),
            'last_seen' => now()->timestamp,
            'last_iso'  => now()->format('H:i:s'),
            'endpoint'  => $endpoint,
            'transport' => 'wifi',
        ];
        Cache::put(self::DEVICES_CACHE_KEY, $devices, now()->addHours(12));
    }

    private function listKnownDevices(): array
    {
        $now    = now()->timestamp;
        $cached = Cache::get(self::DEVICES_CACHE_KEY, []);
        $result = [];

        foreach ($cached as $id => $info) {
            $age = max(0, $now - (int) ($info['last_seen'] ?? 0));
            $result[$id] = [
                'device_id' => $id,
                'ip'        => $info['ip'] ?? '-',
                'transport' => 'wifi',
                'online'    => $age <= self::ONLINE_TTL_SEC,
                'age_sec'   => $age,
                'last_iso'  => $info['last_iso'] ?? '-',
                'endpoint'  => $info['endpoint'] ?? 'API REST',
            ];
        }

        // Complementa com placas reais já vistas no histórico do MySQL
        $dbDevices = Reading::where('device_id', 'not like', 'SIM-%')
            ->selectRaw('device_id, MAX(ts) as last_ts')
            ->groupBy('device_id')
            ->orderByDesc('last_ts')
            ->limit(15)
            ->get();

        foreach ($dbDevices as $row) {
            $id = (string) $row->device_id;
            if (!isset($result[$id])) {
                $ts  = $row->last_ts ? strtotime((string) $row->last_ts) : 0;
                $age = $ts > 0 ? max(0, $now - $ts) : 9999;
                $result[$id] = [
                    'device_id' => $id,
                    'ip'        => str_starts_with($id, 'USB-') ? 'Serial Local' : 'Histórico DB',
                    'transport' => str_starts_with($id, 'USB-') ? 'usb-history' : 'wifi',
                    'online'    => $age <= self::ONLINE_TTL_SEC,
                    'age_sec'   => $age,
                    'last_iso'  => $ts > 0 ? date('H:i:s', $ts) : '-',
                    'endpoint'  => 'MySQL',
                ];
            }
        }

        usort($result, fn ($a, $b) => ($b['online'] <=> $a['online']) ?: ($a['age_sec'] <=> $b['age_sec']));

        return array_values($result);
    }

    private function computePhysics(float $flowKgh, float $tempC): array
    {
        $tempK       = max(250.0, $tempC + 273.15);
        $density     = self::P_ATM_PA / (self::R_AIR * $tempK);
        $massFlowS   = max(0.0, $flowKgh) / 3600.0;
        $velocity    = ($massFlowS / $density) / self::DUCT_AREA_M2;
        $pressurePa  = 0.5 * $density * ($velocity ** 2) * (1.0 + self::K_DUCT_LOSS);
        $pressureAtm = (self::P_ATM_PA + $pressurePa) / self::P_ATM_PA;

        $hEff    = 0.30 + 0.70 * (max(0.0, $flowKgh) / self::Q_REF_KGH);
        $tauTemp = self::C_TH_BASE / $hEff;

        return [
            'density'      => round($density, 3),
            'velocity'     => round($velocity, 3),
            'pressure_pa'  => round($pressurePa, 3),
            'pressure_atm' => round($pressureAtm, 4),
            'tau_flow'     => round(self::TAU_FLOW_BASE, 3),
            'tau_temp'     => round($tauTemp, 3),
        ];
    }

    private function formatReading(Reading $r): array
    {
        $p    = $r->payload;
        $flow = isset($p['flow']) ? (float) $p['flow'] : null;
        $temp = isset($p['temp']) ? (float) $p['temp'] : null;
        $phys = $this->computePhysics($flow ?? 0.0, $temp ?? self::AMBIENT_TEMP);

        return [
            'id'           => $r->id,
            'device_id'    => $r->device_id,
            'flow'         => $flow,
            'temp'         => $temp,
            'u_flow'       => isset($p['u_flow']) ? (float) $p['u_flow'] : null,
            'u_temp'       => isset($p['u_temp']) ? (float) $p['u_temp'] : null,
            'sp_flow'      => isset($p['sp_flow']) ? (float) $p['sp_flow'] : null,
            'sp_temp'      => isset($p['sp_temp']) ? (float) $p['sp_temp'] : null,
            'velocity'     => isset($p['velocity']) ? (float) $p['velocity'] : $phys['velocity'],
            'pressure_pa'  => isset($p['pressure_pa']) ? (float) $p['pressure_pa'] : $phys['pressure_pa'],
            'pressure_atm' => isset($p['pressure_atm']) ? (float) $p['pressure_atm'] : $phys['pressure_atm'],
            'density'      => isset($p['density']) ? (float) $p['density'] : $phys['density'],
            'tau_flow'     => isset($p['tau_flow']) ? (float) $p['tau_flow'] : $phys['tau_flow'],
            'tau_temp'     => isset($p['tau_temp']) ? (float) $p['tau_temp'] : $phys['tau_temp'],
            'ts'           => $r->ts?->format('H:i:s'),
            'ts_full'      => $r->ts?->format('d/m/Y H:i:s'),
        ];
    }

    private function currentControlState(): array
    {
        $latest = fn (string $name, $default) => optional(
            Command::where('name', $name)->orderByDesc('id')->first()
        )->decoded_value ?? $default;

        return [
            'enable_flow'      => ((int) $latest('enable_flow', 0)) === 1,
            'setpoint_flow'    => (float) $latest('setpoint_flow', 50.0),
            'kp_flow'          => (float) $latest('kp_flow', 0.166),
            'ki_flow'          => (float) $latest('ki_flow', 0.276),
            'calib_flow'       => (string) $latest('calib_flow', self::DEFAULT_CALIB_FLOW),

            'enable_temp'      => ((int) $latest('enable_temp', $latest('enable', 0))) === 1,
            'setpoint_temp'    => (float) $latest('setpoint_temp', $latest('setpoint', 40.0)),
            'kp_temp'          => (float) $latest('kp_temp', 0.800),
            'ki_temp'          => (float) $latest('ki_temp', 0.300),
            'calib_temp'       => (string) $latest('calib_temp', self::DEFAULT_CALIB_TEMP),

            'pending_commands' => Command::where('acked', false)->count(),
        ];
    }

    private function safeEvalMath(string $expression, float $x): float
    {
        $expr = strtolower(str_replace([' ', ','], ['', '.'], $expression));

        if ($expr === '' || !preg_match('/^[0-9a-z\.\+\-\*\/\^\(\)]+$/', $expr)) {
            throw new RuntimeException('Caracteres não permitidos na fórmula.');
        }

        preg_match_all('/\d+(?:\.\d+)?|[a-z]+|[\+\-\*\/\^\(\)]/', $expr, $matches);
        $tokens = $matches[0];
        $pos    = 0;

        $parseExpr = $parseTerm = $parseUnary = $parsePower = $parsePrimary = null;

        $parseExpr = function () use (&$tokens, &$pos, &$parseTerm): float {
            $val = $parseTerm();
            while (isset($tokens[$pos]) && ($tokens[$pos] === '+' || $tokens[$pos] === '-')) {
                $op  = $tokens[$pos++];
                $rhs = $parseTerm();
                $val = ($op === '+') ? ($val + $rhs) : ($val - $rhs);
            }
            return $val;
        };

        $parseTerm = function () use (&$tokens, &$pos, &$parseUnary): float {
            $val = $parseUnary();
            while (isset($tokens[$pos]) && ($tokens[$pos] === '*' || $tokens[$pos] === '/')) {
                $op  = $tokens[$pos++];
                $rhs = $parseUnary();
                if ($op === '/' && abs($rhs) < 1e-12) {
                    throw new RuntimeException('Divisão por zero.');
                }
                $val = ($op === '*') ? ($val * $rhs) : ($val / $rhs);
            }
            return $val;
        };

        $parseUnary = function () use (&$tokens, &$pos, &$parseUnary, &$parsePower): float {
            if (isset($tokens[$pos]) && $tokens[$pos] === '+') {
                $pos++;
                return $parseUnary();
            }
            if (isset($tokens[$pos]) && $tokens[$pos] === '-') {
                $pos++;
                return -$parseUnary();
            }
            return $parsePower();
        };

        $parsePower = function () use (&$tokens, &$pos, &$parsePrimary, &$parseUnary): float {
            $base = $parsePrimary();
            if (isset($tokens[$pos]) && $tokens[$pos] === '^') {
                $pos++;
                return pow($base, $parseUnary());
            }
            return $base;
        };

        $parsePrimary = function () use (&$tokens, &$pos, &$parseExpr, $x): float {
            if (!isset($tokens[$pos])) {
                throw new RuntimeException('Expressão incompleta.');
            }
            $tok = $tokens[$pos++];

            if (is_numeric($tok)) {
                return (float) $tok;
            }
            if ($tok === 'x') {
                return $x;
            }
            if ($tok === 'pi') {
                return M_PI;
            }
            if ($tok === 'e') {
                return M_E;
            }
            if ($tok === '(') {
                $val = $parseExpr();
                if (($tokens[$pos] ?? null) !== ')') {
                    throw new RuntimeException('Parêntese não fechado.');
                }
                $pos++;
                return $val;
            }

            $funcs = ['exp', 'ln', 'log', 'sqrt', 'abs', 'sin', 'cos'];
            if (in_array($tok, $funcs, true)) {
                if (($tokens[$pos] ?? null) !== '(') {
                    throw new RuntimeException("Faltou '(' após {$tok}.");
                }
                $pos++;
                $arg = $parseExpr();
                if (($tokens[$pos] ?? null) !== ')') {
                    throw new RuntimeException("Faltou ')' em {$tok}().");
                }
                $pos++;

                return match ($tok) {
                    'exp'  => exp($arg),
                    'ln'   => $arg > 0 ? log($arg) : throw new RuntimeException('ln() exige argumento positivo.'),
                    'log'  => $arg > 0 ? log10($arg) : throw new RuntimeException('log() exige argumento positivo.'),
                    'sqrt' => $arg >= 0 ? sqrt($arg) : throw new RuntimeException('sqrt() exige argumento não negativo.'),
                    'abs'  => abs($arg),
                    'sin'  => sin($arg),
                    'cos'  => cos($arg),
                };
            }

            throw new RuntimeException("Token desconhecido: '{$tok}'.");
        };

        $result = $parseExpr();
        if ($pos !== count($tokens) || !is_finite($result)) {
            throw new RuntimeException('Sintaxe inválida ou resultado infinito.');
        }

        return $result;
    }
}