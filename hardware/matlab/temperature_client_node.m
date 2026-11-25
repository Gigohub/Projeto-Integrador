function temperature_client_node()
% =========================================================================
% PROJETO INTEGRADOR LCON-I / SCOM - TEMPERATURA (PI) + SOPRADOR POR FLAGS
% Hardware: NI-USB-6003 (Dev1) + Kit ELWE LTR701
%   - AI0 -> SENSOR 2 (Temperatura, 0..10 V)
%   - AO0 -> ACTUATOR 2 (Aquecedor, controlado pelo PI: 0..9.5 V)
%   - AO1 -> ACTUATOR 1 (Soprador, sinal constante no ON e 0 V no OFF)
% Rede: TCP 127.0.0.1:2000 (web/tcp_bridge.php)
% =========================================================================

clc;

%% --- 1. CONEXÃO TCP COM A PONTE DO SITE (127.0.0.1:2000) ---
node_ip   = "127.0.0.1";
node_port = 2000;

fprintf("[INIT] Conectando a ponte TCP do site em %s:%d...\n", node_ip, node_port);
client = tcpclient(node_ip, node_port);
cleanupTcp = onCleanup(@() limparConexao(client));
fprintf("[INIT] Conexao TCP estabelecida!\n");

%% --- 2. CONFIGURAÇÃO DA PLACA NI-USB-6003 ---
dev_name  = "Dev1"; % Confirmado no seu PC
uFanConst = 1.5;    % Tensao constante do soprador (AO1) quando ligado

fprintf("[INIT] Inicializando NI-USB-6003 (%s)...\n", dev_name);
d = daq("ni");

% Entrada: AI0 -> SENSOR 2 (Temperatura)
chTemp = addinput(d, dev_name, "ai0", "Voltage");
chTemp.TerminalConfig = "SingleEnded";

% Saidas: Coluna 1 = AO0 (Aquecedor) | Coluna 2 = AO1 (Soprador)
addoutput(d, dev_name, "ao0", "Voltage");
addoutput(d, dev_name, "ao1", "Voltage");

% Inicia com aquecedor e soprador zerados
write(d, [0.0, 0.0]);
cleanupDaq = onCleanup(@() limparDaq(d)); %#ok<NASGU>
fprintf("[INIT] NI-USB-6003 pronta (AI0=Temp | AO0=Aquecedor | AO1=Soprador).\n");

%% --- 3. FLAGS DE ESTADO, CALIBRAÇÃO E FILTRO PASSA-BAIXAS (20 Hz) ---
dt = 0.05; % 20 Hz (50 ms)

% Flags que definem o estado sem precisar encerrar o MATLAB
flag_controlador     = false; % true (1) = ligado | false (0) = desligado
flag_controlador_ant = false; % detecta transicao (ON <-> OFF)

% Calibracao SENSOR 2 (Kit 001): T(C) = 10.4 * V + 0.0795
calibA = 10.4;
calibB = 0.0795;

% Filtro passa-baixas digital (fc = 4 Hz) inicializado na temperatura atual
tauLpf = 1.0 / (2.0 * pi * 4.0);
a0Lpf  = 1.0 + (2.0 * tauLpf / dt);
a1Lpf  = 1.0 - (2.0 * tauLpf / dt);

scanInit = read(d, "OutputFormat", "Matrix");
tempInit = (calibA * max(0.0, min(10.0, scanInit(1)))) + calibB;
xf0 = tempInit;
yf0 = tempInit;

% Parametros de controle sincronizados pelo site
setpoint     = 40.0;
kp           = 0.800;
ki           = 0.300;
integralTemp = 0.0;

fprintf("[RUN] Loop 20 Hz ativo (T0=%.2f C). Use LIGAR/DESLIGAR no site (Ctrl+C encerra).\n\n", tempInit);

%% --- 4. LOOP PRINCIPAL CONTÍNUO (20 Hz) ---
while true
    tLoop = tic;

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
        if flag_controlador
            fprintf("\n[FLAG] Controlador LIGADO -> Soprador (AO1=%.2fV) + PI Aquecedor (AO0) ativos.\n", uFanConst);
        else
            fprintf("\n[FLAG] Controlador DESLIGADO -> Aquecedor (AO0=0V) e Soprador (AO1=0V) zerados.\n");
            integralTemp = 0.0;
            write(d, [0.0, 0.0]);
        end
        flag_controlador_ant = flag_controlador;
    end

    % 4.3. Leitura continua do SENSOR 2 (AI0) + filtro passa-baixas
    scan    = read(d, "OutputFormat", "Matrix");
    tempV   = max(0.0, min(10.0, scan(1)));
    tempRaw = (calibA * tempV) + calibB;

    xf1 = xf0;
    xf0 = tempRaw;
    yf1 = yf0;
    yf0 = (xf0 + xf1 - (a1Lpf * yf1)) / a0Lpf;
    tempFilt = yf0;

    % 4.4. Logica de atuacao controlada pelas flags
    flag_soprador = flag_controlador;

    if flag_controlador
        uFan = max(0.0, min(9.5, uFanConst));

        errTemp = setpoint - tempFilt;
        iCand   = integralTemp + (errTemp * ki * dt);
        uRaw    = (kp * errTemp) + iCand;
        uTemp   = max(0.0, min(9.5, uRaw));

        if (uRaw == uTemp) || (uTemp >= 9.5 && errTemp < 0) || (uTemp <= 0.0 && errTemp > 0)
            integralTemp = iCand;
        end
    else
        integralTemp = 0.0;
        uTemp        = 0.0;
        uFan         = 0.0;
    end

    % 4.5. Aplica tensoes na NI-USB-6003: [AO0 = Aquecedor, AO1 = Soprador]
    write(d, [uTemp, uFan]);

    % 4.6. Envia telemetria e flags para a ponte TCP
    payload = sprintf("temperature=%.3f&temp_v=%.3f&u_temp=%.3f&u_fan=%.3f&flag_ctrl=%d&flag_fan=%d\n", ...
        tempFilt, tempV, uTemp, uFan, flag_controlador, flag_soprador);
    write(client, uint8(char(payload)));

    fprintf("FLAG_CTRL=%d | FLAG_FAN=%d | SP=%.1f C | Temp=%.2f C (%.2fV) | Aq(AO0)=%.2fV | Fan(AO1)=%.2fV\n", ...
        flag_controlador, flag_soprador, setpoint, tempFilt, tempV, uTemp, uFan);

    % 4.7. Mantem os 20 Hz cravados
    tempoGasto = toc(tLoop);
    if tempoGasto < dt
        pause(dt - tempoGasto);
    end
end
end

%% --- 5. FUNÇÕES DE LIMPEZA SEGURA (APENAS NO CTRL+C) ---
function limparDaq(daqObj)
fprintf('\n[CLEANUP] Zerando aquecedor (AO0) e soprador (AO1)...\n');
try
    write(daqObj, [0.0, 0.0]);
    delete(daqObj);
    fprintf('[CLEANUP] NI-USB-6003 zerada e liberada!\n');
catch
    fprintf('[CLEANUP] DAQ ja estava finalizada.\n');
end
end

function limparConexao(tcpObj)
fprintf('[CLEANUP] Fechando conexao TCP...\n');
try
    delete(tcpObj);
    fprintf('[CLEANUP] Socket TCP liberado com sucesso!\n');
catch
    fprintf('[CLEANUP] Conexao TCP ja estava fechada.\n');
end
end