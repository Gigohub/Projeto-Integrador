function temperature_simulator_node()
% =========================================================================
% PROJETO INTEGRADOR LCON-I / SCOM - SIMULADOR EXTERNO DO KIT ELWE LTR701
% Substitui a placa NI-USB-6003 fora do laboratorio mantendo 100% da logica:
%   - Modelo termico Kit 001: d(T - Tamb)/dt = 0.8896 * u_temp - a_eff * (T - Tamb)
%   - Acoplamento do soprador: a_eff = 0.1939 * (0.50 + 0.20 * u_fan)
%   - Controlador PI (20 Hz) + resfriamento rapido em 3.0 V apos 5 s
% Rede: TCP 127.0.0.1:2000 (web/tcp_bridge.php)
% =========================================================================

clc;

%% --- 1. CONEXÃO TCP COM A PONTE DO SITE (127.0.0.1:2000) ---
node_ip   = "127.0.0.1";
node_port = 2000;
device_id = "MATLAB-SIM-LTR701";

fprintf("[SIM] Conectando a ponte TCP do site em %s:%d...\n", node_ip, node_port);
client = tcpclient(node_ip, node_port);
cleanupTcp = onCleanup(@() limparConexao(client)); %#ok<NASGU>
fprintf("[SIM] Conexao TCP estabelecida como %s!\n", device_id);

%% --- 2. PARÂMETROS DA PLANTA SIMULADA (KIT 001) E RESFRIAMENTO ---
dt              = 0.05;   % 20 Hz (50 ms) — igual ao script real
tAmb            = 22.0;   % Temperatura ambiente (°C)
b0              = 0.8896; % Ganho termico identificado (Ativ. 07)
a0Base          = 0.1939; % Polo base (com soprador a 2.5 V -> tau = 5.157 s)
noiseSigma      = 0.08;   % Ruido gaussiano do termopar (°C)

% Buffer circular para atraso de transporte termico (~0.8 s = 16 amostras a 20 Hz)
delaySteps      = 16;
uDelayBuf       = zeros(1, delaySteps);

% Parametros do soprador (identicos ao script real)
uFanConst       = 1.5;    % Tensao normal do soprador (AO1) quando ligado
uFanCool        = 3.0;    % Tensao de resfriamento rapido (AO1)
tempoEsperaCool = 5.0;    % Segundos com aquecedor em 0V antes de subir para 3.0V
margemSetpoint  = 0.5;    % Folga (°C) acima do setpoint para ativar resfriamento

% Calibracao inversa para gerar tensao simulada coerente do SENSOR 2
calibA = 10.4;
calibB = 0.0795;

%% --- 3. FLAGS DE ESTADO, FILTRO PASSA-BAIXAS E VARIÁVEIS PI ---
flag_controlador     = false;
flag_controlador_ant = false;
tempoAquecedorOff    = 0.0;

tempReal = tAmb; % Estado fisico interno da planta simulada (°C)

% Filtro passa-baixas digital (fc = 4 Hz)
tauLpf = 1.0 / (2.0 * pi * 4.0);
a0Lpf  = 1.0 + (2.0 * tauLpf / dt);
a1Lpf  = 1.0 - (2.0 * tauLpf / dt);
xf0    = tAmb;
yf0    = tAmb;

% Parametros sincronizados pelo site
setpoint     = 40.0;
kp           = 0.800;
ki           = 0.300;
integralTemp = 0.0;
loopCount    = 0;

fprintf("[SIM] Simulador 20 Hz rodando (T0=%.1f C). Controle tudo pelo site (Ctrl+C encerra).\n\n", tempReal);

%% --- 4. LOOP PRINCIPAL CONTÍNUO (20 Hz) ---
while true
    tLoop     = tic;
    loopCount = loopCount + 1;

    % 4.1. Leitura nao-bloqueante das flags e parametros vindos do site
    if client.NumBytesAvailable > 0
        response = read(client, client.NumBytesAvailable, "string");

        flagTokens = regexp(response, 'flag_controlador=([01])', 'tokens');
        if ~isempty(flagTokens)
            flag_controlador = (str2double(flagTokens{end}{1}) == 1);
        else
            statusTokens = regexp(response, 'status=(ON|OFF)', 'tokens');
            if ~isempty(statusTokens)
                flag_controlador = strcmp(statusTokens{end}{1}, "ON");
            end
        end

        spTokens = regexp(response, 'setpoint=([\d.]+)', 'tokens');
        if ~isempty(spTokens)
            setpoint = max(15.0, min(100.0, str2double(spTokens{end}{1})));
        end

        kpTokens = regexp(response, 'kp=([\d.]+)', 'tokens');
        if ~isempty(kpTokens)
            kp = max(0.0, min(10.0, str2double(kpTokens{end}{1})));
        end

        kiTokens = regexp(response, 'ki=([\d.]+)', 'tokens');
        if ~isempty(kiTokens)
            ki = max(0.0, min(10.0, str2double(kiTokens{end}{1})));
        end
    end

    % 4.2. Detecta transicao de estado (borda ON <-> OFF)
    if flag_controlador ~= flag_controlador_ant
        tempoAquecedorOff = 0.0;
        if flag_controlador
            fprintf("\n[SIM-FLAG] Controlador LIGADO pelo site -> Soprador %.2fV (Cool %.2fV) + PI ativos.\n", uFanConst, uFanCool);
        else
            fprintf("\n[SIM-FLAG] Controlador DESLIGADO pelo site -> Aquecedor=0V e Soprador=0V.\n");
            integralTemp = 0.0;
        end
        flag_controlador_ant = flag_controlador;
    end

    % 4.3. Leitura simulada do SENSOR 2 (planta + ruido + filtro passa-baixas)
    tempRaw = tempReal + (noiseSigma * randn());
    tempV   = max(0.0, min(10.0, (tempRaw - calibB) / calibA));

    xf1 = xf0;
    xf0 = tempRaw;
    yf1 = yf0;
    yf0 = (xf0 + xf1 - (a1Lpf * yf1)) / a0Lpf;
    tempFilt = yf0;

    % 4.4. Logica de atuacao identica ao script da bancada fisica
    flag_soprador = flag_controlador;

    if flag_controlador
        errTemp = setpoint - tempFilt;
        iCand   = integralTemp + (errTemp * ki * dt);
        uRaw    = (kp * errTemp) + iCand;
        uTemp   = max(0.0, min(9.5, uRaw));

        if (uRaw == uTemp) || (uTemp >= 9.5 && errTemp < 0) || (uTemp <= 0.0 && errTemp > 0)
            integralTemp = iCand;
        end

        if (uTemp <= 0.01) && (tempFilt > (setpoint + margemSetpoint))
            tempoAquecedorOff = tempoAquecedorOff + dt;
        else
            tempoAquecedorOff = 0.0;
        end

        if tempoAquecedorOff >= tempoEsperaCool
            uFan = uFanCool;
        else
            uFan = max(0.0, min(9.5, uFanConst));
        end
    else
        tempoAquecedorOff = 0.0;
        integralTemp      = 0.0;
        uTemp             = 0.0;
        uFan              = 0.0;
    end

    % 4.5. Avanca a fisica simulada do duto termico (Euler sub-amostrado)
    uDelayed  = uDelayBuf(1);
    uDelayBuf = [uDelayBuf(2:end), uTemp];

    % Maior tensao no soprador aumenta a troca convectiva (resfria mais rapido)
    aEff = a0Base * (0.50 + 0.20 * uFan);
    dTdt = (b0 * uDelayed) - (aEff * (tempReal - tAmb));
    tempReal = max(15.0, min(110.0, tempReal + dTdt * dt));

    % 4.6. Envia telemetria e flags para a ponte TCP
    payload = sprintf("device_id=%s&temperature=%.3f&temp_v=%.3f&u_temp=%.3f&u_fan=%.3f&flag_ctrl=%d&flag_fan=%d\n", ...
        device_id, tempFilt, tempV, uTemp, uFan, flag_controlador, flag_soprador);
    write(client, uint8(char(payload)));

    % Imprime no console a 4 Hz para manter a leitura limpa
    if mod(loopCount, 5) == 0
        fprintf("SIM | CTRL=%d | FAN=%d (tCool=%.1fs) | SP=%.1f C | Temp=%.2f C (%.2fV) | Aq=%.2fV | Fan=%.2fV\n", ...
            flag_controlador, flag_soprador, tempoAquecedorOff, setpoint, tempFilt, tempV, uTemp, uFan);
    end

    % 4.7. Mantem os 20 Hz cravados
    tempoGasto = toc(tLoop);
    if tempoGasto < dt
        pause(dt - tempoGasto);
    end
end
end

function limparConexao(tcpObj)
fprintf('\n[CLEANUP] Encerrando simulador e fechando socket TCP...\n');
try
    delete(tcpObj);
    fprintf('[CLEANUP] Socket TCP liberado com sucesso!\n');
catch
    fprintf('[CLEANUP] Conexao TCP ja estava fechada.\n');
end
end 