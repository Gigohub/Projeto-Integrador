<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Command;
use App\Models\Reading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TelemetryController extends Controller
{
    private const DEFAULT_CALIB_TEMP = '10.4 * x + 0.0795';
    private const AMBIENT_TEMP       = 22.0;   // °C
    private const TAU_TEMP_KIT001    = 5.157;  // 1 / 0.1939 s (Ativ. 07 - Kit 001)

    private const MAX_READINGS   = 80;
    private const MAX_LOGS       = 60;
    private const ONLINE_TTL_SEC = 10;

    /**
     * POST /api/sync (Fallback HTTP autenticado por DEVICE_API_TOKEN)
     */
    public function sync(Request $request): JsonResponse
    {
        $deviceId = trim((string) $request->input('device_id', 'MATLAB-NI6003')) ?: 'MATLAB-NI6003';
        $vars     = $request->input('variables', []);

        foreach (['temp', 'temp_v', 'u_temp', 'u_fan', 'rtt_ms'] as $k) {
            if (!isset($vars[$k]) && $request->has($k)) {
                $vars[$k] = (float) $request->input($k);
            }
        }

        $state  = $this->readState();
        $temp   = isset($vars['temp']) ? (float) $vars['temp'] : self::AMBIENT_TEMP;
        $spTemp = (float) $state['setpoint_temp'];
        $now    = now();

        $reading = new Reading();
        $reading->device_id = $deviceId;
        $reading->user_id   = $state['last_user_id'] ?? null;
        $reading->payload   = [
            'temp'      => round($temp, 3),
            'temp_v'    => round((float) ($vars['temp_v'] ?? 0.0), 3),
            'u_temp'    => round((float) ($vars['u_temp'] ?? 0.0), 3),
            'u_fan'     => round((float) ($vars['u_fan'] ?? 0.0), 3),
            'sp_temp'   => $spTemp,
            'error'     => round($spTemp - $temp, 3),
            'tau_temp'  => self::TAU_TEMP_KIT001,
            'rtt_ms'    => round((float) ($vars['rtt_ms'] ?? 0.0), 2),
        ];
        $reading->ts = $now;
        $reading->save();

        Command::where('acked', false)->update(['acked' => true]);

        $devices = $this->readJson('ltr701_devices.json', []);
        $devices[$deviceId] = [
            'device_id' => $deviceId,
            'ip'        => $request->ip(),
            'last_seen' => $now->timestamp,
            'last_iso'  => $now->timezone('America/Sao_Paulo')->format('H:i:s'),
            'endpoint'  => 'POST /api/sync',
        ];
        $this->writeJson('ltr701_devices.json', $devices);

        return response()->json([
            'status' => 'ok',
            'state'  => [
                'enable_temp'   => (bool) $state['enable_temp'],
                'setpoint_temp' => (float) $state['setpoint_temp'],
                'kp_temp'       => (float) $state['kp_temp'],
                'ki_temp'       => (float) $state['ki_temp'],
            ],
        ]);
    }

    /**
     * GET /panel/history (Janela móvel dos últimos 80 pontos para o gráfico em tempo real)
     */
    public function history(Request $request): JsonResponse
    {
        $limit    = min(max((int) $request->query('limit', 80), 1), self::MAX_READINGS);
        $deviceId = $request->query('device_id');

        $rows = Reading::query()
            ->when($deviceId && $deviceId !== 'ALL', fn ($q) => $q->where('device_id', $deviceId))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $readings = $rows->map(function (Reading $row) {
            $p      = $row->payload; // Descriptografia automática AES-256-CBC
            $ts     = ($row->ts ?? now())->timezone('America/Sao_Paulo');
            $temp   = isset($p['temp']) ? (float) $p['temp'] : self::AMBIENT_TEMP;
            $spTemp = isset($p['sp_temp']) ? (float) $p['sp_temp'] : 40.0;

            return [
                'id'        => $row->id,
                'device_id' => $row->device_id,
                'temp'      => round($temp, 3),
                'temp_v'    => round((float) ($p['temp_v'] ?? 0.0), 3),
                'u_temp'    => round((float) ($p['u_temp'] ?? 0.0), 3),
                'u_fan'     => round((float) ($p['u_fan'] ?? 0.0), 3),
                'flag_ctrl' => (int) ($p['flag_ctrl'] ?? 0),
                'flag_fan'  => (int) ($p['flag_fan'] ?? 0),
                'sp_temp'   => $spTemp,
                'error'     => round((float) ($p['error'] ?? ($spTemp - $temp)), 3),
                'tau_temp'  => (float) ($p['tau_temp'] ?? self::TAU_TEMP_KIT001),
                'rtt_ms'    => round((float) ($p['rtt_ms'] ?? 0.0), 2),
                'ts'        => $ts->format('H:i:s'),
                'ts_full'   => $ts->format('d/m/Y H:i:s'),
            ];
        })->all();

        $state                     = $this->readState();
        $state['pending_commands'] = Command::where('acked', false)->count();

        return response()->json([
            'readings' => $readings,
            'state'    => $state,
            'devices'  => $this->listDevices(),
        ]);
    }

    /**
     * GET /panel/export-csv (Exporta TODAS as leituras descriptografadas do banco de dados)
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $deviceId = $request->query('device_id');
        $filename = 'temperatura_completa_ltr701_' . now()->timezone('America/Sao_Paulo')->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($deviceId) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'id', 'horario', 'dispositivo', 'temp_c', 'temp_v',
                'sp_temp_c', 'erro_c', 'u_temp_v', 'u_fan_v',
                'flag_ctrl', 'flag_fan', 'rtt_ms',
            ]);

            $query = Reading::query()
                ->when($deviceId && $deviceId !== 'ALL', fn ($q) => $q->where('device_id', $deviceId))
                ->orderBy('id');

            foreach ($query->lazyById(500) as $row) {
                $p    = $row->payload;
                $ts   = ($row->ts ?? now())->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s');
                $temp = isset($p['temp']) ? (float) $p['temp'] : self::AMBIENT_TEMP;
                $sp   = isset($p['sp_temp']) ? (float) $p['sp_temp'] : 40.0;

                fputcsv($out, [
                    $row->id,
                    $ts,
                    $row->device_id,
                    round($temp, 3),
                    round((float) ($p['temp_v'] ?? 0.0), 3),
                    round($sp, 2),
                    round((float) ($p['error'] ?? ($sp - $temp)), 3),
                    round((float) ($p['u_temp'] ?? 0.0), 3),
                    round((float) ($p['u_fan'] ?? 0.0), 3),
                    (int) ($p['flag_ctrl'] ?? 0),
                    (int) ($p['flag_fan'] ?? 0),
                    round((float) ($p['rtt_ms'] ?? 0.0), 2),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * POST /panel/send (Grava comando + log criptografados no banco e atualiza o estado TCP)
     */
    public function sendCommand(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'  => ['required', 'string', 'in:enable_temp,setpoint_temp,kp_temp,ki_temp,calib_temp'],
            'value' => ['required'],
        ]);

        $user  = $request->user();
        $name  = $validated['name'];
        $value = $validated['value'];
        $state = $this->readState();

        if ($name === 'enable_temp') {
            $storedValue          = ((int) $value) === 1;
            $state['enable_temp'] = $storedValue;
            $logVal               = $storedValue ? 'ON' : 'OFF';
        } elseif ($name === 'calib_temp') {
            $storedValue         = trim((string) $value);
            $state['calib_temp'] = $storedValue;
            $logVal              = $storedValue;
        } else {
            $num = round((float) $value, 4);
            $num = $name === 'setpoint_temp'
                ? max(15.0, min(100.0, $num))
                : max(0.0, min(10.0, $num));
            $storedValue  = $num;
            $state[$name] = $num;
            $logVal       = (string) $num;
        }

        // 1. Registra comando criptografado na tabela commands
        $cmd = new Command();
        $cmd->name          = $name;
        $cmd->decoded_value = $storedValue;
        $cmd->user_id       = $user?->id;
        $cmd->acked         = false;
        $cmd->ts            = now();
        $cmd->save();

        // 2. Registra auditoria criptografada na tabela logs
        AuditLog::record("Comando enviado: {$name} = {$logVal}", $user?->id);

        // 3. Atualiza arquivo de estado lido em 20 Hz pelo tcp_bridge.php
        $state['last_user_id']     = $user?->id;
        $state['pending_commands'] = Command::where('acked', false)->count();
        $this->writeJson('ltr701_state.json', $state);

        return response()->json([
            'status' => 'ok',
            'state'  => $state,
        ]);
    }

    /**
     * GET /panel/logs (Restrito a admin via EnsureAdmin)
     */
    public function logs(): JsonResponse
    {
        $rows = AuditLog::with('user')
            ->orderByDesc('id')
            ->limit(self::MAX_LOGS)
            ->get();

        $logs = $rows->map(function (AuditLog $log) {
            $ts        = ($log->ts ?? now())->timezone('America/Sao_Paulo');
            $userLabel = $log->user
                ? "{$log->user->name} ({$log->user->role})"
                : 'Visitante / Sistema';

            return [
                'id'     => $log->id,
                'user'   => $userLabel,
                'ip'     => $log->ip ?? '127.0.0.1',
                'action' => $log->action, // Descriptografia automática AES-256-CBC
                'ts'     => $ts->format('d/m/Y H:i:s'),
            ];
        })->all();

        return response()->json(['logs' => $logs]);
    }

    private function defaultState(): array
    {
        return [
            'enable_temp'      => false,
            'setpoint_temp'    => 40.0,
            'kp_temp'          => 0.800,
            'ki_temp'          => 0.300,
            'calib_temp'       => self::DEFAULT_CALIB_TEMP,
            'last_user_id'     => null,
            'pending_commands' => 0,
        ];
    }

    private function readState(): array
    {
        return array_merge($this->defaultState(), $this->readJson('ltr701_state.json', []));
    }

    private function listDevices(): array
    {
        $now    = now()->timestamp;
        $raw    = $this->readJson('ltr701_devices.json', []);
        $result = [];

        foreach ($raw as $id => $info) {
            $age = max(0, $now - (int) ($info['last_seen'] ?? 0));
            $result[] = [
                'device_id' => $id,
                'ip'        => $info['ip'] ?? '127.0.0.1',
                'online'    => $age <= self::ONLINE_TTL_SEC,
                'age_sec'   => $age,
                'last_iso'  => $info['last_iso'] ?? '-',
                'endpoint'  => $info['endpoint'] ?? 'TCP 127.0.0.1:2000',
            ];
        }

        return $result;
    }

    private function readJson(string $file, array $default): array
    {
        $path = storage_path('app/' . $file);
        if (!is_file($path)) {
            return $default;
        }
        $content = @file_get_contents($path);
        $decoded = is_string($content) ? json_decode($content, true) : null;

        return is_array($decoded) ? $decoded : $default;
    }

    private function writeJson(string $file, array $data): void
    {
        $path = storage_path('app/' . $file);
        @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}