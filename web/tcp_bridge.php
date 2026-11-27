<?php

use App\Models\Command;
use App\Models\Reading;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Inicializa o Laravel (Eloquent, .env, APP_KEY e Crypt AES-256-CBC)
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

date_default_timezone_set('America/Sao_Paulo');

$host = '127.0.0.1';
$port = 2000;

$storageDir  = storage_path('app');
$stateFile   = $storageDir . '/ltr701_state.json';
$devicesFile = $storageDir . '/ltr701_devices.json';

if (!is_dir($storageDir)) {
    mkdir($storageDir, 0777, true);
}

$defaultState = [
    'enable_temp'      => false,
    'setpoint_temp'    => 40.0,
    'kp_temp'          => 0.800,
    'ki_temp'          => 0.300,
    'calib_temp'       => '10.4 * x + 0.0795',
    'last_user_id'     => null,
    'pending_commands' => 0,
];

$server = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "[ERRO] Nao foi possivel abrir {$host}:{$port} — {$errstr} ({$errno})\n");
    exit(1);
}

echo "[TCP] Ponte LTR701 (Banco Criptografado AES-256) ouvindo em {$host}:{$port}\n";
echo "[TCP] Banco ativo: " . config('database.default') . " (" . config('database.connections.' . config('database.default') . '.database') . ")\n";
echo "[TCP] Aguardando conexao do MATLAB (real ou simulador)...\n";

$lastValidState = $defaultState;

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }

    $peer = stream_socket_get_name($conn, true) ?: '127.0.0.1';
    echo "[TCP] MATLAB conectado ({$peer})!\n";

    $lastSave      = 0.0;
    $lastRxTime    = microtime(true);
    $lastSignature = null;

    while (($line = fgets($conn)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $nowFloat   = microtime(true);
        $rttMs      = round(($nowFloat - $lastRxTime) * 1000.0, 2);
        $lastRxTime = $nowFloat;

        parse_str($line, $data);
        $deviceId = !empty($data['device_id'])
            ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $data['device_id'])
            : 'MATLAB-NI6003';
        $temp     = isset($data['temperature']) ? (float) $data['temperature'] : 22.0;
        $tempV    = isset($data['temp_v']) ? (float) $data['temp_v'] : 0.0;
        $uTemp    = isset($data['u_temp']) ? (float) $data['u_temp'] : 0.0;
        $uFan     = isset($data['u_fan']) ? (float) $data['u_fan'] : 0.0;
        $flagCtrl = isset($data['flag_ctrl']) ? (int) $data['flag_ctrl'] : 0;
        $flagFan  = isset($data['flag_fan']) ? (int) $data['flag_fan'] : 0;

        // 1. Le o estado mais recente salvo pelo site (sem consultar o banco a 20 Hz)
        if (is_file($stateFile)) {
            $rawState = @file_get_contents($stateFile);
            $decoded  = is_string($rawState) ? json_decode($rawState, true) : null;
            if (is_array($decoded)) {
                $lastValidState = array_merge($defaultState, $decoded);
            }
        }

        // 2. Responde ao MATLAB imediatamente via TCP e registra qualquer alteracao no console
        $targetFlag = !empty($lastValidState['enable_temp']) ? 1 : 0;
        $statusStr  = $targetFlag ? 'ON' : 'OFF';
        $spTemp     = (float) $lastValidState['setpoint_temp'];
        $kpTemp     = (float) $lastValidState['kp_temp'];
        $kiTemp     = (float) $lastValidState['ki_temp'];

        $signature = sprintf('%d|%.2f|%.4f|%.4f', $targetFlag, $spTemp, $kpTemp, $kiTemp);
        if ($lastSignature !== $signature) {
            echo sprintf(
                "[%s] SITE -> %s: flag=%d (%s) | SP=%.1f C | Kp=%.3f | Ki=%.3f\n",
                date('H:i:s'),
                $deviceId,
                $targetFlag,
                $statusStr,
                $spTemp,
                $kpTemp,
                $kiTemp
            );
            $lastSignature = $signature;
        }

        $reply = sprintf(
            "flag_controlador=%d&status=%s&setpoint=%.2f&kp=%.4f&ki=%.4f\n",
            $targetFlag,
            $statusStr,
            $spTemp,
            $kpTemp,
            $kiTemp
        );

        if (@fwrite($conn, $reply) === false) {
            break;
        }

        // 3. Persiste leitura criptografada no banco a cada 0,5 s (2 Hz) e confirma comandos
        if (($nowFloat - $lastSave) >= 0.5) {
            $lastSave = $nowFloat;

            try {
                $reading = new Reading();
                $reading->device_id = $deviceId;
                $reading->user_id   = $lastValidState['last_user_id'] ?? null;
                $reading->payload   = [
                    'temp'      => round($temp, 3),
                    'temp_v'    => round($tempV, 3),
                    'u_temp'    => round($uTemp, 3),
                    'u_fan'     => round($uFan, 3),
                    'flag_ctrl' => $flagCtrl,
                    'flag_fan'  => $flagFan,
                    'sp_temp'   => $spTemp,
                    'error'     => round($spTemp - $temp, 3),
                    'tau_temp'  => 5.157,
                    'rtt_ms'    => $rttMs,
                ];
                $reading->ts = now();
                $reading->save();

                Command::where('acked', false)->update(['acked' => true]);
            } catch (\Throwable $e) {
                fwrite(STDERR, "[DB WARN] " . $e->getMessage() . "\n");
                try {
                    DB::reconnect();
                } catch (\Throwable) {
                }
            }

            // Atualiza heartbeat de dispositivos online
            $devices = [];
            if (is_file($devicesFile)) {
                $dec = json_decode((string) @file_get_contents($devicesFile), true);
                if (is_array($dec)) {
                    $devices = $dec;
                }
            }
            $devices[$deviceId] = [
                'device_id' => $deviceId,
                'ip'        => $host,
                'last_seen' => time(),
                'last_iso'  => date('H:i:s'),
                'endpoint'  => "TCP {$host}:{$port}",
            ];
            @file_put_contents($devicesFile, json_encode($devices, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
    }

    fclose($conn);
    echo "[TCP] MATLAB desconectado. Aguardando nova conexao...\n";
}