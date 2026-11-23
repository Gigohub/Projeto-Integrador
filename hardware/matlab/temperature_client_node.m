%% =========================================================================
%% PROJETO INTEGRADOR LCON-I / SCOM - NÓ DUAL (VAZÃO + TEMPERATURA)
%% Hardware: NI-USB-6003 + Kit ELWE LTR701 (0..10 V nativo)
%% Backend:  Laravel HTTP REST (POST /readings, GET /commands, POST /commands/ack)
%% =========================================================================

clear;
clc;

%% --- 1. CONFIGURAÇÕES DE REDE E SERVIDOR LARAVEL ---
server_url = "http://127.0.0.1:8000"; % Troque pelo IPv4 do servidor se rodar em outro PC
device_id  = "MATLAB-NI6003";
api_token  = "token_do_dispositivo";      % DEVE bater com DEVICE_API_TOKEN no web/.env

% Formato N x 2 exigido por weboptions()
headers = [
    "Content-Type",  "application/json";
    "Accept",        "application/json";
    "Authorization", "Bearer " + api_token;
    "X-Device-Id",   device_id
];

http_options = weboptions( ...
    "HeaderFields", headers, ...
    "Timeout", 1.0, ...
    "MediaType", "application/json" ...
);

%% --- 2. CONFIGURAÇÃO DA PLACA NI-USB-6003 ---
% Rode daqlist("ni") no Command Window se não tiver certeza do ID ("Dev1", "Dev2"...)
dev_name = "Dev3";
fprintf("[INIT] Inicializando NI-USB-6003 (%s)...\n", dev_name);

try
    d = daq("ni");

    % Entradas Analógicas (0..10 V):
    % Coluna 1 -> AI0: SENSOR 2 (Temperatura)
    % Coluna 2 -> AI1: SENSOR 5 (Vazão Mássica)
    ch_temp = addinput(d, dev_name, "ai0", "Voltage");
    ch_temp.TerminalConfig = "SingleEnded";

    ch_flow = addinput(d, dev_name, "ai1", "Voltage");
    ch_flow.TerminalConfig = "SingleEnded";

    % Saídas Analógicas (0..9.5 V):
    % Coluna 1 -> AO0: ACTUATOR 2 (Aquecedor)
    % Coluna 2 -> AO1: ACTUATOR 1 (Ventilador)
    addoutput(d, dev_name, "ao0", "Voltage");
    addoutput(d, dev_name, "ao1", "Voltage");

    write(d, [0.0, 0.0]); % Garante atuadores zerados no início
    fprintf("[INIT] NI-USB-6003 pronta (AI0=Temp, AI1=Vazao | AO0=Aquecedor, AO1=Ventilador).\n");
catch ME
    warning("Erro ao abrir NI-USB-6003 (%s): %s", dev_name, ME.message);
    rethrow(ME);
end

% Fechamento seguro: zera aquecedor e ventilador ao encerrar com Ctrl+C
cleanupObj = onCleanup(@() encerrarDaq(d));

%% --- 3. CONSTANTES DE CONTROLE (100 Hz) E FILTRO PASSA-BAIXAS (12.5 Hz) ---
Ts      = 0.01;                      % 100 Hz (10 ms)
tau_lpf = 1.0 / (2.0 * pi * 12.5);   % Corte em 12,5 Hz (Tustin / bilinear)
a0_lpf  = 1.0 + (2.0 * tau_lpf / Ts);
a1_lpf  = 1.0 - (2.0 * tau_lpf / Ts);

% Estados do filtro passa-baixas (Temperatura e Vazão)
xf_temp0 = 22.0; xf_temp1 = 22.0; yf_temp0 = 22.0; yf_temp1 = 22.0;
xf_flow0 = 0.0;  xf_flow1 = 0.0;  yf_flow0 = 0.0;  yf_flow1 = 0.0;

% Calibração dos sensores do grupo (LCON-I Ativ. 07 e Ativ. 10)
calib_temp_a = 10.4;    % T(°C)   = 10.4 * V + 0.0795
calib_temp_b = 0.0795;
calib_flow_a = 3.94;    % Q(kg/h) = 3.94 * exp(0.617 * V)
calib_flow_b = 0.617;

% Parâmetros iniciais da Malha de VAZÃO (ACTUATOR 1 / SENSOR 5)
enable_flow   = false;
setpoint_flow = 50.0;   % kg/h
kp_flow       = 0.166;
ki_flow       = 0.276;
integral_flow = 0.0;
u_flow        = 0.0;

% Parâmetros iniciais da Malha de TEMPERATURA (ACTUATOR 2 / SENSOR 2)
enable_temp   = false;
setpoint_temp = 40.0;   % °C
kp_temp       = 0.800;
ki_temp       = 0.300;
integral_temp = 0.0;
u_temp        = 0.0;

% Proteção térmica: ventilação mínima quando o aquecedor está ligado e a malha de vazão desligada
SAFE_FAN_WHEN_HEATING = true;
V_FAN_MIN_SAFE        = 2.5; % 25% de ventilação mínima para convecção

% Métricas de rede SCOM (RTT, pacotes e throughput)
tx_packets   = 0;
lost_packets = 0;
last_rtt_ms  = 0.0;
loop_count   = 0;

fprintf("[RUN] Controle dual iniciado a 100 Hz (Telemetria a 2 Hz). Pressione Ctrl+C para parar.\n\n");

%% --- 4. LOOP PRINCIPAL (100 Hz) ---
while true
    t_start    = tic;
    loop_count = loop_count + 1;

    % 4.1. Leitura simultânea de AI0 (Temp) e AI1 (Vazão) em modo on-demand
    scan   = read(d, "OutputFormat", "Matrix");
    temp_v = max(0.0, min(10.0, scan(1)));
    flow_v = max(0.0, min(10.0, scan(2)));

    % 4.2. Conversão para grandezas físicas e Filtragem Passa-Baixas (12.5 Hz)
    temp_raw = (calib_temp_a * temp_v) + calib_temp_b;
    flow_raw = calib_flow_a * exp(calib_flow_b * flow_v);

    xf_temp1 = xf_temp0; xf_temp0 = temp_raw;
    yf_temp1 = yf_temp0; yf_temp0 = (xf_temp0 + xf_temp1 - (a1_lpf * yf_temp1)) / a0_lpf;
    temp_filt = yf_temp0;

    xf_flow1 = xf_flow0; xf_flow0 = flow_raw;
    yf_flow1 = yf_flow0; yf_flow0 = (xf_flow0 + xf_flow1 - (a1_lpf * yf_flow1)) / a0_lpf;
    flow_filt = yf_flow0;

    % 4.3. Controlador PI Digital - Malha de VAZÃO (AO1 -> ACTUATOR 1)
    if enable_flow
        err_flow    = setpoint_flow - flow_filt;
        i_cand_flow = integral_flow + (err_flow * ki_flow * Ts);
        u_raw_flow  = (kp_flow * err_flow) + i_cand_flow;
        u_flow      = max(0.0, min(9.5, u_raw_flow));

        % Anti-windup condicional
        if (u_raw_flow == u_flow) || (u_flow >= 9.5 && err_flow < 0) || (u_flow <= 0.0 && err_flow > 0)
            integral_flow = i_cand_flow;
        end
    else
        integral_flow = 0.0; % Requisito LCON-I: zera integrador ao desabilitar
        if SAFE_FAN_WHEN_HEATING && enable_temp
            u_flow = V_FAN_MIN_SAFE;
        else
            u_flow = 0.0;
        end
    end

    % 4.4. Controlador PI Digital - Malha de TEMPERATURA (AO0 -> ACTUATOR 2)
    if enable_temp
        err_temp    = setpoint_temp - temp_filt;
        i_cand_temp = integral_temp + (err_temp * ki_temp * Ts);
        u_raw_temp  = (kp_temp * err_temp) + i_cand_temp;
        u_temp      = max(0.0, min(9.5, u_raw_temp));

        % Anti-windup condicional
        if (u_raw_temp == u_temp) || (u_temp >= 9.5 && err_temp < 0) || (u_temp <= 0.0 && err_temp > 0)
            integral_temp = i_cand_temp;
        end
    else
        integral_temp = 0.0; % Requisito LCON-I: zera integrador ao desabilitar
        u_temp        = 0.0;
    end

    % 4.5. Escrita simultânea nos atuadores: [AO0 = Aquecedor, AO1 = Ventilador]
    write(d, [u_temp, u_flow]);

    % 4.6. Comunicação HTTP REST a 2 Hz (a cada 50 iterações)
    if mod(loop_count, 50) == 0
        tx_packets = tx_packets + 1;
        try
            % A) Envia leituras para POST /api/readings
            payload_read = struct();
            payload_read.device_id        = device_id;
            payload_read.variables.flow   = round(flow_filt, 3);
            payload_read.variables.temp   = round(temp_filt, 3);
            payload_read.variables.flow_v = round(flow_v, 3);
            payload_read.variables.temp_v = round(temp_v, 3);
            payload_read.variables.u_flow = round(u_flow, 3);
            payload_read.variables.u_temp = round(u_temp, 3);
            payload_read.variables.rtt_ms = round(last_rtt_ms, 2);

            t_http = tic;
            webwrite(server_url + "/readings", payload_read, http_options);
            last_rtt_ms = toc(t_http) * 1000.0;

            % B) Busca comandos pendentes em GET /api/commands
            cmds = webread(server_url + "/commands?device_id=" + device_id, http_options);

            if ~isempty(cmds)
                ack_ids = {};
                for k = 1:numel(cmds)
                    if iscell(cmds)
                        c = cmds{k};
                    else
                        c = cmds(k);
                    end

                    cmd_name = string(c.name);
                    if isnumeric(c.value) || islogical(c.value)
                        cmd_val = double(c.value);

                        if cmd_name == "enable_flow"
                            enable_flow = (cmd_val == 1);
                        elseif cmd_name == "setpoint_flow"
                            setpoint_flow = max(0.0, min(150.0, cmd_val));
                        elseif cmd_name == "kp_flow"
                            kp_flow = max(0.0, min(10.0, cmd_val));
                        elseif cmd_name == "ki_flow"
                            ki_flow = max(0.0, min(10.0, cmd_val));
                        elseif cmd_name == "enable_temp" || cmd_name == "enable"
                            enable_temp = (cmd_val == 1);
                        elseif cmd_name == "setpoint_temp" || cmd_name == "setpoint"
                            setpoint_temp = max(15.0, min(100.0, cmd_val));
                        elseif cmd_name == "kp_temp"
                            kp_temp = max(0.0, min(10.0, cmd_val));
                        elseif cmd_name == "ki_temp"
                            ki_temp = max(0.0, min(10.0, cmd_val));
                        end
                    end

                    ack_ids{end + 1} = c.id; %#ok<SAGROW>
                end

                % C) Confirma recebimento em POST /api/commands/ack
                if ~isempty(ack_ids)
                    ack_payload = struct();
                    ack_payload.device_id = device_id;
                    ack_payload.ids       = ack_ids; % Cell array garante JSON array "[id]"
                    webwrite(server_url + "/commands/ack", ack_payload, http_options);
                end
            end
        catch err_net
            lost_packets = lost_packets + 1;
            fprintf("[NET WARN] %s\n", err_net.message);
        end

        loss_pct = (lost_packets / tx_packets) * 100.0;
        fprintf("FLOW[%s] %.2f/%.1f kg/h (u=%.2fV) | TEMP[%s] %.2f/%.1f°C (u=%.2fV) | RTT=%.1fms (Loss=%.1f%%)\n", ...
            ternary(enable_flow, "ON ", "OFF"), flow_filt, setpoint_flow, u_flow, ...
            ternary(enable_temp, "ON ", "OFF"), temp_filt, setpoint_temp, u_temp, ...
            last_rtt_ms, loss_pct);
    end

    % 4.7. Mantém período alvo de 100 Hz (10 ms)
    elapsed = toc(t_start);
    if elapsed < Ts
        pause(Ts - elapsed);
    end
end

%% --- 5. FUNÇÕES AUXILIARES ---
function encerrarDaq(daqObj)
    fprintf("\n[CLEANUP] Encerrando com segurança...\n");
    try
        write(daqObj, [0.0, 0.0]); % Zera aquecedor (AO0) e ventilador (AO1)
        delete(daqObj);
        fprintf("[CLEANUP] Saídas analógicas zeradas e NI-USB-6003 liberada!\n");
    catch
        fprintf("[CLEANUP] Dispositivo DAQ já estava finalizado.\n");
    end
end

function out = ternary(cond, valTrue, valFalse)
    if cond
        out = valTrue;
    else
        out = valFalse;
    end
end