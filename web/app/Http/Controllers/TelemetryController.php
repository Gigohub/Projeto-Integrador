<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class TelemetryController extends Controller
{
    private const DEFAULT_CALIB_FLOW = '3.94 * exp(0.617 * x)';
    private const DEFAULT_CALIB_TEMP = '10.4 * x + 0.0795';

    // Constantes físicas do duto LTR701 (Kit 001)
    private const AMBIENT_TEMP  = 22.0;      // °C
    private const P_ATM_PA      = 101325.0;  // Pa (1 atm)
    private const R_AIR         = 287.05;    // J/(kg·K)
    private const DUCT_AREA_M2  = 0.0019635; // pi * (0.025 m)^2
    private const K_DUCT_LOSS   = 3.2;       // Perda de carga do conjunto
    private const TAU_FLOW_BASE = 0.964;     // 1 / 1.037 s (Ativ. 10)
    private const C_TH_BASE     = 5.157;     // 1 / 0.1939 s (Ativ. 07 - Kit 001)
    private const Q_REF_KGH     = 25.0;      // Vazão de referência (~25% do ventilador)

    private const DEVICES_CACHE_KEY  = 'ltr701_known_devices_v1';
    private const READINGS_CACHE_KEY = 'ltr701_live_readings_v1';
    private const COMMANDS_CACHE_KEY = 'ltr701_live_commands_v1';
    private const STATE_CACHE_KEY    = 'ltr701_live_state_v1';
    private const LOGS_CACHE_KEY     = 'ltr701_live_logs_v1';

    private const MAX_LIVE_READINGS = 120;
    private const MAX_LIVE_LOGS     = 100;
    private const ONLINE_TTL_SEC    = 15;

    /**
     * POST /api/readings (MATLAB / ESP32 envia leituras em tempo real)
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

        $deviceId = trim((string) $request->input('device_id', 'MATLAB-NI6003')) ?: 'MATLAB-NI6003';
        $this->touchDevice($deviceId, $request, 'POST /api/readings');

        $vars  = $request->input('variables', []);
        $state = $this->currentControlState();

        foreach (['temp', 'flow', 'temp_v', 'flow_v', 'u_temp', 'u_flow', 'pressure_pa', 'rtt_ms'] as $key) {
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

        $now   = now();
        $entry = $this->pushLiveReading($deviceId, $vars, $now);

        return response()->json([
            'status'    => 'ok',
            'id'        => $entry['id'],
            'device_id' => $deviceId,
            'saved'     => $vars,
            'ts'        => $now->toIso8601String(),
        ], 201);
    }

    /**
     * GET /api/commands (MATLAB / ESP32 consulta comandos pendentes)
     */
    public function pendingCommands(Request $request): JsonResponse
    {
        $deviceId = $request->query('device_id') ?: $request->header('X-Device-Id');
        if ($deviceId) {
            $this->touchDevice((string) $deviceId, $request, 'GET /api/commands');
        }

        $commands = collect(Cache::get(self::COMMANDS_CACHE_KEY, []))
            ->where('acked', false)
            ->map(fn (array $cmd) => [
                'id'    => $cmd['id'],
                'name'  => $cmd['name'],
                'value' => $cmd['value'],
                'ts'    => $cmd['ts'],
            ])
            ->values();

        return response()->json($commands);
    }

    /**
     * POST /api/commands/ack (MATLAB / ESP32 confirma execução dos comandos)
     */
    public function ackCommands(Request $request): JsonResponse
    {
        $deviceId = $request->input('device_id') ?: $request->header('X-Device-Id');
        if ($deviceId) {
            $this->touchDevice((string) $deviceId, $request, 'POST /api/commands/ack');
        }

        $ids = $request->input('ids');
        if (is_numeric($ids)) {
            $ids = [(int) $ids];
        } elseif (is_array($ids)) {
            $ids = array_map('intval', $ids);
        } else {
            $ids = [];
        }

        $ackedCount = 0;

        Cache::lock('ltr701_cmd_lock', 2)->block(2, function () use ($ids, &$ackedCount): void {
            $commands = Cache::get(self::COMMANDS_CACHE_KEY, []);

            foreach ($commands as &$cmd) {
                if (!($cmd['acked'] ?? false)) {
                    if (empty($ids) || in_array((int) $cmd['id'], $ids, true)) {
                        $cmd['acked'] = true;
                        $ackedCount++;
                    }
                }
            }
            unset($cmd);

            // Mantém apenas comandos ainda pendentes + últimos 20 confirmados
            $commands = array_values(array_filter(
                $commands,
                fn (array $c, int $idx) => !($c['acked'] ?? false) || $idx >= count($commands) - 20,
                ARRAY_FILTER_USE_BOTH
            ));

            Cache::put(self::COMMANDS_CACHE_KEY, $commands, now()->addHours(12));
        });

        return response()->json([
            'status' => 'ok',
            'acked'  => $ackedCount,
        ]);
    }

    /**
     * GET /panel/history (Retorna buffer ao vivo + estado + lista de placas online)
     */
    public function history(Request $request): JsonResponse
    {
        $limit    = min(max((int) $request->query('limit', 80), 1), self::MAX_LIVE_READINGS);
        $deviceId = $request->query('device_id');

        $readings = collect(Cache::get(self::READINGS_CACHE_KEY, []));

        if ($deviceId && $deviceId !== 'ALL') {
            $readings = $readings->where('device_id', $deviceId);
        } elseif ($request->boolean('real_only', true)) {
            $readings = $readings->reject(fn (array $r) => str_starts_with((string) ($r['device_id'] ?? ''), 'SIM-'));
        }

        $readings = $readings->slice(-$limit)->values();

        return response()->json([
            'readings' => $readings,
            'state'    => $this->currentControlState(),
            'devices'  => $this->listKnownDevices(),
        ]);
    }

    /**
     * POST /panel/send (Envia comando em tempo real da interface para o MATLAB / ESP32)
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

        Cache::lock('ltr701_cmd_lock', 2)->block(2, function () use ($name, $value): void {
            $commands = Cache::get(self::COMMANDS_CACHE_KEY, []);
            $nextId   = empty($commands) ? 1 : (max(array_column($commands, 'id')) + 1);

            $commands[] = [
                'id'    => $nextId,
                'name'  => $name,
                'value' => $value,
                'acked' => false,
                'ts'    => now()->toIso8601String(),
            ];

            Cache::put(self::COMMANDS_CACHE_KEY, $commands, now()->addHours(12));

            $state = Cache::get(self::STATE_CACHE_KEY, $this->defaultState());
            if ($name === 'enable') {
                $state['enable_temp'] = ($value === 1);
            } elseif ($name === 'setpoint') {
                $state['setpoint_temp'] = (float) $value;
            } elseif (in_array($name, ['enable_flow', 'enable_temp'], true)) {
                $state[$name] = ($value === 1);
            } else {
                $state[$name] = $value;
            }

            Cache::put(self::STATE_CACHE_KEY, $state, now()->addHours(12));
        });

        $target = $validated['device_id'] ?? 'BROADCAST';
        $this->recordVolatileLog("Comando tempo real [{$target}]: {$name} = {$value}", $request->ip());

        return response()->json([
            'status' => 'ok',
            'state'  => $this->currentControlState(),
        ], 201);
    }

    /**
     * POST /panel/simulate (Recebe telemetria USB Serial sem gravar em banco)
     */
    public function simulate(Request $request): JsonResponse
    {
        if ($request->input('source') !== 'usb') {
            return response()->json([
                'message' => 'A Sombra Digital roda isolada no cliente; este endpoint recebe apenas telemetria USB.',
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

        $entry = $this->pushLiveReading($validated['device_id'], $payload, now());

        return response()->json([
            'status'  => 'ok',
            'reading' => $entry,
        ], 201);
    }

    /**
     * GET /panel/logs (Logs voláteis em memória/cache)
     */
    public function logs(): JsonResponse
    {
        return response()->json([
            'logs' => array_reverse(Cache::get(self::LOGS_CACHE_KEY, [])),
        ]);
    }

    private function pushLiveReading(string $deviceId, array $p, \Illuminate\Support\Carbon $now): array
    {
        $flow = isset($p['flow']) ? (float) $p['flow'] : null;
        $temp = isset($p['temp']) ? (float) $p['temp'] : null;
        $phys = $this->computePhysics($flow ?? 0.0, $temp ?? self::AMBIENT_TEMP);

        $entry = [];

        Cache::lock('ltr701_read_lock', 2)->block(2, function () use ($deviceId, $p, $flow, $temp, $phys, $now, &$entry): void {
            $readings = Cache::get(self::READINGS_CACHE_KEY, []);
            $nextId   = empty($readings) ? 1 : (max(array_column($readings, 'id')) + 1);

            $entry = [
                'id'           => $nextId,
                'device_id'    => $deviceId,
                'flow'         => $flow,
                'temp'         => $temp,
                'u_flow'       => isset($p['u_flow']) ? (float) $p['u_flow'] : null,
                'u_temp'       => isset($p['u_temp']) ? (float) $p['u_temp'] : null,
                'sp_flow'      => isset($p['sp_flow']) ? (float) $p['sp_flow'] : null,
                'sp_temp'      => isset($p['sp_temp']) ? (float) $p['sp_temp'] : null,
                'rtt_ms'       => isset($p['rtt_ms']) ? (float) $p['rtt_ms'] : null,
                'velocity'     => isset($p['velocity']) ? (float) $p['velocity'] : $phys['velocity'],
                'pressure_pa'  => isset($p['pressure_pa']) ? (float) $p['pressure_pa'] : $phys['pressure_pa'],
                'pressure_atm' => isset($p['pressure_atm']) ? (float) $p['pressure_atm'] : $phys['pressure_atm'],
                'density'      => isset($p['density']) ? (float) $p['density'] : $phys['density'],
                'tau_flow'     => isset($p['tau_flow']) ? (float) $p['tau_flow'] : $phys['tau_flow'],
                'tau_temp'     => isset($p['tau_temp']) ? (float) $p['tau_temp'] : $phys['tau_temp'],
                'ts'           => $now->format('H:i:s'),
                'ts_full'      => $now->format('d/m/Y H:i:s'),
            ];

            $readings[] = $entry;
            if (count($readings) > self::MAX_LIVE_READINGS) {
                $readings = array_slice($readings, -self::MAX_LIVE_READINGS);
            }

            Cache::put(self::READINGS_CACHE_KEY, $readings, now()->addHours(12));
        });

        return $entry;
    }

    private function recordVolatileLog(string $action, ?string $ip): void
    {
        $logs   = Cache::get(self::LOGS_CACHE_KEY, []);
        $nextId = empty($logs) ? 1 : (max(array_column($logs, 'id')) + 1);

        $logs[] = [
            'id'     => $nextId,
            'user'   => 'Operador Local (Sem DB)',
            'ip'     => $ip ?? '-',
            'action' => $action,
            'ts'     => now()->format('d/m/Y H:i:s'),
        ];

        if (count($logs) > self::MAX_LIVE_LOGS) {
            $logs = array_slice($logs, -self::MAX_LIVE_LOGS);
        }

        Cache::put(self::LOGS_CACHE_KEY, $logs, now()->addHours(12));
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
            $result[] = [
                'device_id' => $id,
                'ip'        => $info['ip'] ?? '-',
                'transport' => 'wifi',
                'online'    => $age <= self::ONLINE_TTL_SEC,
                'age_sec'   => $age,
                'last_iso'  => $info['last_iso'] ?? '-',
                'endpoint'  => $info['endpoint'] ?? 'API REST',
            ];
        }

        usort($result, fn ($a, $b) => ($b['online'] <=> $a['online']) ?: ($a['age_sec'] <=> $b['age_sec']));

        return $result;
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

    private function defaultState(): array
    {
        return [
            'enable_flow'   => false,
            'setpoint_flow' => 50.0,
            'kp_flow'       => 0.166,
            'ki_flow'       => 0.276,
            'calib_flow'    => self::DEFAULT_CALIB_FLOW,

            'enable_temp'   => false,
            'setpoint_temp' => 40.0,
            'kp_temp'       => 0.800,
            'ki_temp'       => 0.300,
            'calib_temp'    => self::DEFAULT_CALIB_TEMP,
        ];
    }

    private function currentControlState(): array
    {
        $state   = array_merge($this->defaultState(), Cache::get(self::STATE_CACHE_KEY, []));
        $pending = collect(Cache::get(self::COMMANDS_CACHE_KEY, []))->where('acked', false)->count();

        $state['pending_commands'] = $pending;

        return $state;
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