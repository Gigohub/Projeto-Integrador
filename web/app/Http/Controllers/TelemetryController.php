<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelemetryController extends Controller
{
    private const DEFAULT_CALIB_TEMP = '10.4 * x + 0.0795';
    private const AMBIENT_TEMP       = 22.0;   // °C
    private const TAU_TEMP_KIT001    = 5.157;  // 1 / 0.1939 s (Ativ. 07 - Kit 001)

    private const MAX_READINGS   = 80;
    private const MAX_LOGS       = 60;
    private const ONLINE_TTL_SEC = 10;

    /**
     * POST /api/sync
     * Recebe telemetria de temperatura do MATLAB e devolve o estado atual dos controles.
     */
    public function sync(Request $request): JsonResponse
    {
        $deviceId = trim((string) $request->input('device_id', 'MATLAB-NI6003')) ?: 'MATLAB-NI6003';
        $vars     = $request->input('variables', []);

        foreach (['temp', 'temp_v', 'u_temp', 'rtt_ms'] as $k) {
            if (!isset($vars[$k]) && $request->has($k)) {
                $vars[$k] = (float) $request->input($k);
            }
        }

        $state  = $this->readState();
        $temp   = isset($vars['temp']) ? (float) $vars['temp'] : self::AMBIENT_TEMP;
        $spTemp = (float) $state['setpoint_temp'];
        $now    = now();

        $readings = $this->readJson('ltr701_readings.json', []);
        $nextId   = empty($readings) ? 1 : (end($readings)['id'] + 1);

        $readings[] = [
            'id'        => $nextId,
            'device_id' => $deviceId,
            'temp'      => round($temp, 3),
            'temp_v'    => round((float) ($vars['temp_v'] ?? 0.0), 3),
            'u_temp'    => round((float) ($vars['u_temp'] ?? 0.0), 3),
            'sp_temp'   => $spTemp,
            'error'     => round($spTemp - $temp, 3),
            'tau_temp'  => self::TAU_TEMP_KIT001,
            'rtt_ms'    => round((float) ($vars['rtt_ms'] ?? 0.0), 2),
            'ts'        => $now->format('H:i:s'),
            'ts_full'   => $now->format('d/m/Y H:i:s'),
        ];

        if (count($readings) > self::MAX_READINGS) {
            $readings = array_slice($readings, -self::MAX_READINGS);
        }
        $this->writeJson('ltr701_readings.json', $readings);

        // Registra MATLAB como ONLINE
        $devices = $this->readJson('ltr701_devices.json', []);
        $devices[$deviceId] = [
            'device_id' => $deviceId,
            'ip'        => $request->ip(),
            'last_seen' => $now->timestamp,
            'last_iso'  => $now->format('H:i:s'),
            'endpoint'  => 'POST /api/sync',
        ];
        $this->writeJson('ltr701_devices.json', $devices);

        if (($state['pending_commands'] ?? 0) > 0) {
            $state['pending_commands'] = 0;
            $this->writeJson('ltr701_state.json', $state);
        }

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
     * GET /panel/history
     */
    public function history(Request $request): JsonResponse
    {
        $limit    = min(max((int) $request->query('limit', 80), 1), self::MAX_READINGS);
        $deviceId = $request->query('device_id');

        $readings = $this->readJson('ltr701_readings.json', []);
        if ($deviceId && $deviceId !== 'ALL') {
            $readings = array_values(array_filter($readings, fn ($r) => ($r['device_id'] ?? '') === $deviceId));
        }

        return response()->json([
            'readings' => array_slice($readings, -$limit),
            'state'    => $this->readState(),
            'devices'  => $this->listDevices(),
        ]);
    }

    /**
     * POST /panel/send
     */
    public function sendCommand(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'  => ['required', 'string', 'in:enable_temp,setpoint_temp,kp_temp,ki_temp,calib_temp'],
            'value' => ['required'],
        ]);

        $name  = $validated['name'];
        $value = $validated['value'];
        $state = $this->readState();

        if ($name === 'enable_temp') {
            $state['enable_temp'] = ((int) $value) === 1;
            $logVal               = $state['enable_temp'] ? 'ON' : 'OFF';
        } elseif ($name === 'calib_temp') {
            $state['calib_temp'] = trim((string) $value);
            $logVal              = $state['calib_temp'];
        } else {
            $num = round((float) $value, 4);
            $num = $name === 'setpoint_temp'
                ? max(15.0, min(100.0, $num))
                : max(0.0, min(10.0, $num));
            $state[$name] = $num;
            $logVal       = (string) $num;
        }

        $state['pending_commands'] = (int) ($state['pending_commands'] ?? 0) + 1;
        $this->writeJson('ltr701_state.json', $state);
        $this->appendLog("{$name} = {$logVal}", $request->ip());

        return response()->json([
            'status' => 'ok',
            'state'  => $state,
        ]);
    }

    /**
     * GET /panel/logs
     */
    public function logs(): JsonResponse
    {
        return response()->json([
            'logs' => array_reverse($this->readJson('ltr701_logs.json', [])),
        ]);
    }

    private function defaultState(): array
    {
        return [
            'enable_temp'      => false,
            'setpoint_temp'    => 40.0,
            'kp_temp'          => 0.800,
            'ki_temp'          => 0.300,
            'calib_temp'       => self::DEFAULT_CALIB_TEMP,
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
                'endpoint'  => $info['endpoint'] ?? 'POST /api/sync',
            ];
        }

        return $result;
    }

    private function appendLog(string $action, ?string $ip): void
    {
        $logs   = $this->readJson('ltr701_logs.json', []);
        $nextId = empty($logs) ? 1 : (end($logs)['id'] + 1);

        $logs[] = [
            'id'     => $nextId,
            'user'   => 'Operador Local',
            'ip'     => $ip ?? '127.0.0.1',
            'action' => $action,
            'ts'     => now()->format('d/m/Y H:i:s'),
        ];

        if (count($logs) > self::MAX_LOGS) {
            $logs = array_slice($logs, -self::MAX_LOGS);
        }
        $this->writeJson('ltr701_logs.json', $logs);
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