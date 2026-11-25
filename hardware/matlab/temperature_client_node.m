%% =========================================================================
%% PROJETO INTEGRADOR LCON-I / SCOM - TEMPERATURA (PI) + SOPRADOR CONSTANTE
%% Hardware: NI-USB-6003 (Dev3) + Kit ELWE LTR701
%%   - AI0 -> SENSOR 2 (Temperatura)
%%   - AO0 -> ACTUATOR 2 (Aquecedor - controlado pelo PI)
%%   - AO1 -> ACTUATOR 1 (Soprador - tensao constante enquanto o script roda)
%% =========================================================================
function temperature_client_node()

clear;
clc;

%% --- 1. REDE E SERVIDOR LARAVEL ---
server_url = "http://127.0.0.1:8000/api";
device_id  = "MATLAB-NI6003";
api_token  = "token_do_dispositivo"; % Igual ao DEVICE_API_TOKEN no web/.env

headers = [
    "Accept",        "application/json";
    "Authorization", "Bearer " + api_token;
    "X-Device-Id",   device_id;
    "Connection",    "close"
];

http_options = weboptions( ...
    "HeaderFields", headers, ...
    "Timeout", 3.0, ...
    "MediaType", "application/json" ...
);

%% --- 2. TENSAO CONSTANTE DO SOPRADOR (ACTUATOR 1 / AO1) ---
% 2.5 V = ~25% (referencia usada na identificacao do Kit 001)
% Ajuste se quiser mais vazao fixa (ex.: 3.0 V, 4.0 V ou 5.0 V)
V_FAN_CONST = 2.5;

%% --- 3. PLACA NI-USB-6003 ---
dev_name = "Dev1"; % Ajuste se daqlist("ni") mostrar outro ID
fprintf("[INIT] Inicializando NI-USB-6003 (%s)...\n", dev_name);

d = daq("ni");

% Entrada: AI0 = SENSOR 2 (Temperatura)
ch_temp = addinput(d, dev_name, "ai0", "Voltage");
ch_temp.TerminalConfig = "SingleEnded";

% Saidas: Coluna 1 = AO0 (Aquecedor) | Coluna 2 = AO1 (Soprador)
addoutput(d, dev_name, "ao0", "Voltage");
addoutput(d, dev_name, "ao1", "Voltage");

% Liga o soprador na tensao constante e garante aquecedor zerado no inicio
u_fan = max(0.0, min(9.5, V_FAN_CONST));
write(d, [0.0, u_fan]);

cleanupObj = onCleanup(@() encerrarDaq(d));
fprintf("[INIT] NI-USB-6003 pronta! Soprador fixo em %.2f V (AO1) | Aquecedor em AO0.\n", u_fan);

%% --- 4. CONSTANTES DE CONTROLE (100 Hz) E FILTRO (12.5 Hz) ---
Ts      = 0.01;                      % 100 Hz (10 ms)
tau_lpf = 1.0 / (2.0 * pi * 12.5);   % Corte em 12,5 Hz (Tustin / bilinear)
a0_lpf  = 1.0 + (2.0 * tau_lpf / Ts);
a1_lpf  = 1.0 - (2.0 * tau_lpf / Ts);

xf_temp0 = 22.0; xf_temp1 = 22.0;
yf_temp0 = 22.0; yf_temp1 = 22.0;

% Calibracao SENSOR 2 (Kit 001): T(C) = 10.4 * V + 0.0795
calib_temp_a = 10.4;
calib_temp_b = 0.0795;

% Parametros iniciais do controlador PI de temperatura
enable_temp   = false;
setpoint_temp = 40.0;   % C
kp_temp       = 0.800;
ki_temp       = 0.300;
integral_temp = 0.0;
u_temp        = 0.0;

tx_packets   = 0;
lost_packets = 0;
last_rtt_ms  = 0.0;
loop_count   = 0;

fprintf("[RUN] Controle PI 100 Hz ativo | Sync com site a 2 Hz. Ctrl+C para parar.\n\n");

%% --- 5. LOOP PRINCIPAL (100 Hz) ---
while true
    t_start    = tic;
    loop_count = loop_count + 1;

    % 5.1. Leitura fisica (AI0)
    scan   = read(d, "OutputFormat", "Matrix");
    temp_v = max(0.0, min(10.0, scan(1)));

    % 5.2. Calibracao + Filtro Passa-Baixas 12.5 Hz
    temp_raw = (calib_temp_a * temp_v) + calib_temp_b;

    xf_temp1  = xf_temp0;
    xf_temp0  = temp_raw;
    yf_temp1  = yf_temp0;
    yf_temp0  = (xf_temp0 + xf_temp1 - (a1_lpf * yf_temp1)) / a0_lpf;
    temp_filt = yf_temp0;

    % 5.3. Controlador PI Digital - TEMPERATURA (AO0)
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
        integral_temp = 0.0; % Zera integrador ao desabilitar aquecedor
        u_temp        = 0.0;
    end

    % 5.4. Escrita simultanea: [AO0 = Aquecedor (variavel), AO1 = Soprador (constante)]
    write(d, [u_temp, u_fan]);

    % 5.5. Sincronizacao HTTP REST a 2 Hz (a cada 50 iteracoes)
    if mod(loop_count, 50) == 0
        tx_packets = tx_packets + 1;
        try
            payload = struct();
            payload.device_id        = device_id;
            payload.variables.temp   = round(temp_filt, 3);
            payload.variables.temp_v = round(temp_v, 3);
            payload.variables.u_temp = round(u_temp, 3);
            payload.variables.rtt_ms = round(last_rtt_ms, 2);

            t_http = tic;
            resp = webwrite(server_url + "/sync", payload, http_options);
            last_rtt_ms = toc(t_http) * 1000.0;

            % Aplica comandos vindos do site
            if isstruct(resp) && isfield(resp, 'state')
                s = resp.state;
                enable_temp   = logical(s.enable_temp);
                setpoint_temp = max(15.0, min(100.0, double(s.setpoint_temp)));
                kp_temp       = max(0.0,  min(10.0,  double(s.kp_temp)));
                ki_temp       = max(0.0,  min(10.0,  double(s.ki_temp)));
            end
        catch err_net
            lost_packets = lost_packets + 1;
            fprintf("[NET WARN] %s\n", err_net.message);
        end

        loss_pct = (lost_packets / tx_packets) * 100.0;
        fprintf("TEMP[%s] %.2f/%.1f C | Aq(AO0)=%.2fV | Fan(AO1)=%.2fV | Kp=%.2f Ki=%.2f | RTT=%.1fms (Loss=%.0f%%)\n", ...
            ternary(enable_temp, "ON ", "OFF"), temp_filt, setpoint_temp, u_temp, u_fan, ...
            kp_temp, ki_temp, last_rtt_ms, loss_pct);
    end

    % 5.6. Mantem periodo de 100 Hz (10 ms)
    elapsed = toc(t_start);
    if elapsed < Ts
        pause(Ts - elapsed);
    end
end

end

function encerrarDaq(daqObj)
    fprintf("\n[CLEANUP] Zerando aquecedor (AO0) e soprador (AO1)...\n");
    try
        write(daqObj, [0.0, 0.0]);
        delete(daqObj);
        fprintf("[CLEANUP] Finalizado com seguranca!\n");
    catch
        fprintf("[CLEANUP] DAQ ja liberada.\n");
    end
end

function out = ternary(cond, valTrue, valFalse)
    if cond
        out = valTrue;
    else
        out = valFalse;
    end
end