#ifndef CONFIG_H
#define CONFIG_H

// =========================================================================
// 1. REDE WI-FI E SERVIDOR LARAVEL
// =========================================================================
#define WIFI_SSID       "NOME_DA_REDE_WIFI"
#define WIFI_PASS       "SENHA_DA_REDE_WIFI"

// Use o IPv4 da máquina onde roda "php artisan serve --host=0.0.0.0 --port=8000"
// Não use "localhost" ou "127.0.0.1" no ESP32
#define SERVER_URL      "http://192.168.0.100:8000/api"
#define DEVICE_ID       "ESP32-LTR701"
#define API_TOKEN       "token_do_dispositivo"

// =========================================================================
// 2. PINAGEM ESP32 (ADC1 para compatibilidade com Wi-Fi + DACs internos)
// =========================================================================
#define PIN_ADC_TEMP    34  // SENSOR 2 (Temp) via divisor 10V -> 3V (Ativ. 08/09)
#define PIN_ADC_FLOW    35  // SENSOR 5 (Vazão) via divisor 10V -> 3V
#define PIN_DAC_TEMP    25  // ACTUATOR 2 (Aquecedor) via LM741 3V -> 10V (Ativ. 08/09)
#define PIN_DAC_FLOW    26  // ACTUATOR 1 (Ventilador) via LM741 3V -> 10V

// =========================================================================
// 3. CALIBRAÇÃO DE HARDWARE E EQUAÇÕES DO GRUPO (LCON-I)
// =========================================================================
// Fator do divisor resistivo calibrado de 10V para 3V (10.0 / 3.0 = 3.3333)
#define DIVIDER_GAIN    3.3333f

// Tensão máxima da saída DAC do ESP32 que corresponde a 10V após o LM741
#define DAC_FULL_SCALE_V 3.0f

// Equação Temperatura (Ativ. 07): T(°C) = 10.4 * V + 0.0795
#define TEMP_CAL_A      10.4f
#define TEMP_CAL_B      0.0795f

// Equação Vazão (Ativ. 10): Q(kg/h) = 3.94 * exp(0.617 * V)
#define FLOW_CAL_A      3.94f
#define FLOW_CAL_B      0.617f

// Ganhos iniciais do Controlador PI (ajustáveis ao vivo pelo site ou Serial)
#define DEFAULT_KP_FLOW 0.166f
#define DEFAULT_KI_FLOW 0.276f
#define DEFAULT_KP_TEMP 0.800f
#define DEFAULT_KI_TEMP 0.300f

#endif