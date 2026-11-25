<?php

date_default_timezone_set('America/Sao_Paulo');

$host = '127.0.0.1';
$port = 2000;

$storageDir   = __DIR__ . '/storage/app';
$stateFile    = $storageDir . '/ltr701_state.json';
$readingsFile = $storageDir . '/ltr701_readings.json';
$devicesFile  = $storageDir . '/ltr701_devices.json';

if (!is_dir($storageDir)) {
    mkdir($storageDir, 0777, true);
}

$defaultState = [
    'enable_temp'      => false,
    'setpoint_temp'    => 40.0,
    'kp_temp'          => 0.800,
    'ki_temp'          => 0.300,
    'calib_temp'       => '10.4 * x + 0.0795',
    'pending_commands' => 0,
];

$server = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "[ERRO] Nao foi possivel abrir {$host}:{$port} — {$errstr} ({$errno})\n");
    exit(1);
}

echo "[TCP] Ponte LTR701 ouvindo em {$host}:{$port}\n";
echo "[TCP] Aguardando conexao do MATLAB...\n";

$lastValidState = $defaultState;

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }

    $peer = stream_socket_get_name($conn, true) ?: '127.0.0.1';
    echo "[TCP] MATLAB conectado ({$peer})!\n";

    $lastSave   = 0.0;
    $lastRxTime = microtime(true);
    $lastFlag   = null;

    while (($line = fgets($conn)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $nowFloat   = microtime(true);
        $rttMs      = round(($nowFloat - $lastRxTime) * 1000.0, 2);
        $lastRxTime = $nowFloat;

        parse_str($line, $data);
        $temp     = isset($data['temperature']) ? (float) $data['temperature'] : 22.0;
        $tempV    = isset($data['temp_v']) ? (float) $data['temp_v'] : 0.0;
        $uTemp    = isset($data['u_temp']) ? (float) $data['u_temp'] : 0.0;
        $uFan     = isset($data['u_fan']) ? (float) $data['u_fan'] : 0.0;
        $flagCtrl = isset($data['flag_ctrl']) ? (int) $data['flag_ctrl'] : 0;
        $flagFan  = isset($data['flag_fan']) ? (int) $data['flag_fan'] : 0;

        // 1. Le o estado mais recente gravado pelo site Laravel (somente leitura para evitar conflito)
        if (is_file($stateFile)) {
            $rawState = @file_get_contents($stateFile);
            $decoded  = is_string($rawState) ? json_decode($rawState, true) : null;
            if (is_array($decoded)) {
                $lastValidState = array_merge($defaultState, $decoded);
            }
        }

        // 2. Devolve flags e parametros imediatamente via TCP para o MATLAB
        $targetFlag = !empty($lastValidState['enable_temp']) ? 1 : 0;
        $statusStr  = $targetFlag ? 'ON' : 'OFF';
        $spTemp     = (float) $lastValidState['setpoint_temp'];
        $kpTemp     = (float) $lastValidState['kp_temp'];
        $kiTemp     = (float) $lastValidState['ki_temp'];

        if ($lastFlag !== $targetFlag) {
            echo sprintf(
                "[%s] SITE -> MATLAB: flag_controlador=%d (%s) | SP=%.1f C | Kp=%.3f | Ki=%.3f\n",
                date('H:i:s'),
                $targetFlag,
                $statusStr,
                $spTemp,
                $kpTemp,
                $kiTemp
            );
            $lastFlag = $targetFlag;
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

        // 3. Atualiza os arquivos lidos pelo dashboard a cada 0,5 s (2 Hz)
        if (($nowFloat - $lastSave) >= 0.5) {
            $lastSave = $nowFloat;

            $readings = [];
            if (is_file($readingsFile)) {
                $dec = json_decode((string) @file_get_contents($readingsFile), true);
                if (is_array($dec)) {
                    $readings = $dec;
                }
            }

            $nextId     = empty($readings) ? 1 : ((int) end($readings)['id'] + 1);
            $readings[] = [
                'id'        => $nextId,
                'device_id' => 'MATLAB-NI6003',
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
                'ts'        => date('H:i:s'),
                'ts_full'   => date('d/m/Y H:i:s'),
            ];

            if (count($readings) > 80) {
                $readings = array_slice($readings, -80);
            }

            @file_put_contents($readingsFile, json_encode($readings, JSON_UNESCAPED_UNICODE), LOCK_EX);

            $devices = [
                'MATLAB-NI6003' => [
                    'device_id' => 'MATLAB-NI6003',
                    'ip'        => $host,
                    'last_seen' => time(),
                    'last_iso'  => date('H:i:s'),
                    'endpoint'  => "TCP {$host}:{$port}",
                ],
            ];
            @file_put_contents($devicesFile, json_encode($devices, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
    }

    fclose($conn);
    echo "[TCP] MATLAB desconectado. Aguardando nova conexao...\n";
}