#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <math.h>
#include "config.h"

// =========================================================================
// PARÂMETROS DO CONTROLE DIGITAL (100 Hz) E FILTRO PASSA-BAIXAS (12,5 Hz)
// =========================================================================
const float TS = 0.01f;                          // 10 ms (100 Hz)
const float WC_LPF = 2.0f * PI * 12.5f;          // Frequência de corte: 12,5 Hz
const float TAU_LPF = 1.0f / WC_LPF;             // ~0.0127324 s
const float LPF_A0 = 1.0f + (2.0f * TAU_LPF / TS);
const float LPF_A1 = 1.0f - (2.0f * TAU_LPF / TS);

portMUX_TYPE stateMux = portMUX_INITIALIZER_UNLOCKED;

// Parâmetros compartilhados entre Core 0 (Wi-Fi) e Core 1 (Controle 100 Hz)
struct ControlParams {
  bool  enableFlow   = false;
  float setpointFlow = 50.0f;
  float kpFlow       = DEFAULT_KP_FLOW;
  float kiFlow       = DEFAULT_KI_FLOW;

  bool  enableTemp   = false;
  float setpointTemp = 40.0f;
  float kpTemp       = DEFAULT_KP_TEMP;
  float kiTemp       = DEFAULT_KI_TEMP;
} ctrl;

// Telemetria e métricas de desempenho SCOM compartilhadas
struct TelemetrySnapshot {
  float flowKgh      = 0.0f;
  float tempC        = 22.0f;
  float flowVolts    = 0.0f;
  float tempVolts    = 0.0f;
  float uFlowVolts   = 0.0f;
  float uTempVolts   = 0.0f;

  unsigned long lastRttMs   = 0;
  unsigned long txPackets   = 0;
  unsigned long lostPackets = 0;
  float throughputBps       = 0.0f;
} telem;

// Estados internos do filtro passa-baixas e dos integradores PI (Core 1)
float xfTemp0 = 0.0f, xfTemp1 = 0.0f, yfTemp0 = 22.0f, yfTemp1 = 22.0f;
float xfFlow0 = 0.0f, xfFlow1 = 0.0f, yfFlow0 = 0.0f,  yfFlow1 = 0.0f;
float integralFlow = 0.0f;
float integralTemp = 0.0f;

String serialRxBuffer = "";

// =========================================================================
// FUNÇÕES AUXILIARES DE HARDWARE (ADC / DAC)
// =========================================================================
float readSensorVolts(uint8_t pin) {
  // Média rápida de 8 amostras para reduzir ruído de quantização do ADC do ESP32
  uint32_t acc = 0;
  for (int i = 0; i < 8; i++) {
    acc += analogRead(pin);
  }
  float vAdc = ((float)acc / 8.0f) / 4095.0f * 3.3f;
  float vSensor = vAdc * DIVIDER_GAIN;
  if (vSensor < 0.0f) vSensor = 0.0f;
  if (vSensor > 10.0f) vSensor = 10.0f;
  return vSensor;
}

void writeActuatorVolts(uint8_t dacPin, float volts0to10) {
  if (volts0to10 < 0.0f) volts0to10 = 0.0f;
  if (volts0to10 > 9.5f) volts0to10 = 9.5f; // Saturação segura de 9,5 V (LCON-I)

  // Converte escala 0..10V do atuador para 0..3.0V na saída DAC antes do LM741
  float vDac = (volts0to10 / 10.0f) * DAC_FULL_SCALE_V;
  int dacCode = (int)roundf((vDac / 3.3f) * 255.0f);
  if (dacCode < 0) dacCode = 0;
  if (dacCode > 255) dacCode = 255;

  dacWrite(dacPin, (uint8_t)dacCode);
}

// =========================================================================
// PARSER DE COMANDOS (SERIAL USB + API REST)
// =========================================================================
void applySingleCommand(const String& name, float val) {
  taskENTER_CRITICAL(&stateMux);
  if (name == "enable_flow") {
    ctrl.enableFlow = ((int)val == 1);
  } else if (name == "setpoint_flow") {
    ctrl.setpointFlow = constrain(val, 0.0f, 150.0f);
  } else if (name == "kp_flow") {
    ctrl.kpFlow = constrain(val, 0.0f, 10.0f);
  } else if (name == "ki_flow") {
    ctrl.kiFlow = constrain(val, 0.0f, 10.0f);
  } else if (name == "enable_temp" || name == "enable") {
    ctrl.enableTemp = ((int)val == 1);
  } else if (name == "setpoint_temp" || name == "setpoint") {
    ctrl.setpointTemp = constrain(val, 15.0f, 100.0f);
  } else if (name == "kp_temp") {
    ctrl.kpTemp = constrain(val, 0.0f, 10.0f);
  } else if (name == "ki_temp") {
    ctrl.kiTemp = constrain(val, 0.0f, 10.0f);
  }
  taskEXIT_CRITICAL(&stateMux);
}

void processSerialInput() {
  while (Serial.available() > 0) {
    char c = (char)Serial.read();
    if (c == '\n' || c == '\r') {
      serialRxBuffer.trim();
      if (serialRxBuffer.length() > 0) {
        int eq = serialRxBuffer.indexOf('=');
        if (eq > 0) {
          String key = serialRxBuffer.substring(0, eq);
          float val = serialRxBuffer.substring(eq + 1).toFloat();
          key.trim();
          applySingleCommand(key, val);
          Serial.printf("{\"ack_serial\":\"%s\",\"value\":%.4f}\n", key.c_str(), val);
        } else {
          // Compatibilidade com Ativ. 08/09: digitar apenas número altera setpoint de temperatura
          float sp = serialRxBuffer.toFloat();
          if (sp >= 15.0f && sp <= 100.0f) {
            applySingleCommand("setpoint_temp", sp);
            Serial.printf("{\"ack_serial\":\"setpoint_temp\",\"value\":%.2f}\n", sp);
          }
        }
        serialRxBuffer = "";
      }
    } else {
      serialRxBuffer += c;
    }
  }
}

// =========================================================================
// TAREFA DE REDE WI-FI (CORE 0 - NÃO BLOQUEIA O CONTROLE DE 100 Hz)
// =========================================================================
void networkTask(void* pvParameters) {
  for (;;) {
    if (WiFi.status() != WL_CONNECTED) {
      WiFi.disconnect();
      WiFi.begin(WIFI_SSID, WIFI_PASS);
      vTaskDelay(pdMS_TO_TICKS(3000));
      continue;
    }

    // Copia snapshot atual com proteção de concorrência
    TelemetrySnapshot snap;
    taskENTER_CRITICAL(&stateMux);
    snap = telem;
    taskEXIT_CRITICAL(&stateMux);

    // 1) POST /api/readings (Envia telemetria e mede RTT / Perda / Throughput)
    {
      HTTPClient http;
      String url = String(SERVER_URL) + "/readings";
      http.begin(url);
      http.setTimeout(1500);
      http.addHeader("Content-Type", "application/json");
      http.addHeader("Accept", "application/json");
      http.addHeader("Authorization", String("Bearer ") + API_TOKEN);
      http.addHeader("X-Device-Id", DEVICE_ID);

      StaticJsonDocument<320> doc;
      doc["device_id"] = DEVICE_ID;
      JsonObject vars = doc.createNestedObject("variables");
      vars["flow"]   = roundf(snap.flowKgh * 1000.0f) / 1000.0f;
      vars["temp"]   = roundf(snap.tempC * 1000.0f) / 1000.0f;
      vars["flow_v"] = roundf(snap.flowVolts * 1000.0f) / 1000.0f;
      vars["temp_v"] = roundf(snap.tempVolts * 1000.0f) / 1000.0f;
      vars["u_flow"] = roundf(snap.uFlowVolts * 1000.0f) / 1000.0f;
      vars["u_temp"] = roundf(snap.uTempVolts * 1000.0f) / 1000.0f;
      vars["rtt_ms"] = snap.lastRttMs;

      String body;
      serializeJson(doc, body);

      unsigned long tStart = millis();
      int httpCode = http.POST(body);
      unsigned long rtt = millis() - tStart;
      String resp = (httpCode > 0) ? http.getString() : "";
      http.end();

      taskENTER_CRITICAL(&stateMux);
      telem.txPackets++;
      if (httpCode >= 200 && httpCode < 300) {
        telem.lastRttMs = rtt;
        size_t totalBytes = body.length() + resp.length();
        telem.throughputBps = (rtt > 0) ? ((float)totalBytes * 1000.0f / (float)rtt) : (float)totalBytes;
      } else {
        telem.lostPackets++;
      }
      taskEXIT_CRITICAL(&stateMux);
    }

    // 2) GET /api/commands + POST /api/commands/ack
    {
      HTTPClient http;
      String url = String(SERVER_URL) + "/commands?device_id=" + DEVICE_ID;
      http.begin(url);
      http.setTimeout(1500);
      http.addHeader("Accept", "application/json");
      http.addHeader("Authorization", String("Bearer ") + API_TOKEN);
      http.addHeader("X-Device-Id", DEVICE_ID);

      int code = http.GET();
      if (code == 200) {
        String payload = http.getString();
        http.end();

        StaticJsonDocument<1024> cmdDoc;
        if (!deserializeJson(cmdDoc, payload) && cmdDoc.is<JsonArray>()) {
          JsonArray arr = cmdDoc.as<JsonArray>();
          if (arr.size() > 0) {
            StaticJsonDocument<256> ackDoc;
            ackDoc["device_id"] = DEVICE_ID;
            JsonArray ids = ackDoc.createNestedArray("ids");

            for (JsonObject c : arr) {
              int id = c["id"] | 0;
              String name = c["name"] | "";
              if (c["value"].is<float>() || c["value"].is<int>()) {
                applySingleCommand(name, c["value"].as<float>());
              }
              if (id > 0) ids.add(id);
            }

            // Confirma execução dos comandos no Laravel
            HTTPClient ackHttp;
            ackHttp.begin(String(SERVER_URL) + "/commands/ack");
            ackHttp.setTimeout(1500);
            ackHttp.addHeader("Content-Type", "application/json");
            ackHttp.addHeader("Authorization", String("Bearer ") + API_TOKEN);
            ackHttp.addHeader("X-Device-Id", DEVICE_ID);
            String ackBody;
            serializeJson(ackDoc, ackBody);
            ackHttp.POST(ackBody);
            ackHttp.end();
          }
        }
      } else {
        http.end();
      }
    }

    vTaskDelay(pdMS_TO_TICKS(500)); // Ciclo de rede a 2 Hz
  }
}

// =========================================================================
// SETUP E LOOP DE CONTROLE EM TEMPO REAL (CORE 1 - 100 Hz)
// =========================================================================
void setup() {
  Serial.begin(115200);
  analogReadResolution(12);
  analogSetAttenuation(ADC_11db); // Faixa 0..3.3V nos pinos GPIO 34 e 35

  // Garante atuadores desligados na inicialização
  dacWrite(PIN_DAC_TEMP, 0);
  dacWrite(PIN_DAC_FLOW, 0);

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);

  // Cria tarefa de rede no Core 0 deixando Core 1 dedicado ao controle de 100 Hz
  xTaskCreatePinnedToCore(
    networkTask,
    "NetworkTask",
    8192,
    NULL,
    1,
    NULL,
    0
  );
}

void loop() {
  static unsigned long lastControlUs = 0;
  static unsigned long lastSerialMs  = 0;

  processSerialInput();

  unsigned long nowUs = micros();
  if (nowUs - lastControlUs >= 10000UL) { // 10.000 us = 10 ms = 100 Hz exatos
    lastControlUs = nowUs;

    // 1. Leitura dos sensores (0..10V recuperados do divisor)
    float vTemp = readSensorVolts(PIN_ADC_TEMP);
    float vFlow = readSensorVolts(PIN_ADC_FLOW);

    // 2. Conversão para unidades de engenharia (Ativ. 07 e Ativ. 10)
    float rawTempC   = (TEMP_CAL_A * vTemp) + TEMP_CAL_B;
    float rawFlowKgh = FLOW_CAL_A * expf(FLOW_CAL_B * vFlow);

    // 3. Filtro Passa-Baixas Digital (fc = 12,5 Hz, Tustin)
    xfTemp1 = xfTemp0; xfTemp0 = rawTempC;
    yfTemp1 = yfTemp0; yfTemp0 = (xfTemp0 + xfTemp1 - (LPF_A1 * yfTemp1)) / LPF_A0;

    xfFlow1 = xfFlow0; xfFlow0 = rawFlowKgh;
    yfFlow1 = yfFlow0; yfFlow0 = (xfFlow0 + xfFlow1 - (LPF_A1 * yfFlow1)) / LPF_A0;

    // Copia parâmetros ativos do controlador
    ControlParams p;
    taskENTER_CRITICAL(&stateMux);
    p = ctrl;
    taskEXIT_CRITICAL(&stateMux);

    // 4. Controlador PI Digital - Malha de Vazão (ACTUATOR 1)
    float uFlow = 0.0f;
    if (p.enableFlow) {
      float errFlow = p.setpointFlow - yfFlow0;
      float iCand = integralFlow + (errFlow * p.kiFlow * TS);
      float uRaw = (p.kpFlow * errFlow) + iCand;
      uFlow = constrain(uRaw, 0.0f, 9.5f);
      if (uRaw == uFlow || (uFlow >= 9.5f && errFlow < 0.0f) || (uFlow <= 0.0f && errFlow > 0.0f)) {
        integralFlow = iCand;
      }
    } else {
      integralFlow = 0.0f; // Requisito LCON-I: zera integrador ao desabilitar
      uFlow = 0.0f;
    }

    // 5. Controlador PI Digital - Malha de Temperatura (ACTUATOR 2)
    float uTemp = 0.0f;
    if (p.enableTemp) {
      float errTemp = p.setpointTemp - yfTemp0;
      float iCand = integralTemp + (errTemp * p.kiTemp * TS);
      float uRaw = (p.kpTemp * errTemp) + iCand;
      uTemp = constrain(uRaw, 0.0f, 9.5f);
      if (uRaw == uTemp || (uTemp >= 9.5f && errTemp < 0.0f) || (uTemp <= 0.0f && errTemp > 0.0f)) {
        integralTemp = iCand;
      }
    } else {
      integralTemp = 0.0f; // Requisito LCON-I: zera integrador ao desabilitar
      uTemp = 0.0f;
    }

    // 6. Envia tensão de controle aos atuadores
    writeActuatorVolts(PIN_DAC_FLOW, uFlow);
    writeActuatorVolts(PIN_DAC_TEMP, uTemp);

    // Atualiza telemetria para a task de rede
    taskENTER_CRITICAL(&stateMux);
    telem.flowKgh    = yfFlow0;
    telem.tempC      = yfTemp0;
    telem.flowVolts  = vFlow;
    telem.tempVolts  = vTemp;
    telem.uFlowVolts = uFlow;
    telem.uTempVolts = uTemp;
    taskEXIT_CRITICAL(&stateMux);
  }

  // Envia telemetria JSON pela USB Serial a 2 Hz (compatível com o painel Web Serial)
  unsigned long nowMs = millis();
  if (nowMs - lastSerialMs >= 500UL) {
    lastSerialMs = nowMs;

    TelemetrySnapshot snap;
    taskENTER_CRITICAL(&stateMux);
    snap = telem;
    taskEXIT_CRITICAL(&stateMux);

    float lossPct = (snap.txPackets > 0)
      ? ((float)snap.lostPackets * 100.0f / (float)snap.txPackets)
      : 0.0f;

    Serial.printf(
      "{\"device_id\":\"%s\",\"flow\":%.3f,\"temp\":%.3f,\"u_flow\":%.3f,\"u_temp\":%.3f,\"rtt_ms\":%lu,\"throughput_bps\":%.1f,\"pkt_loss_pct\":%.2f}\n",
      DEVICE_ID, snap.flowKgh, snap.tempC, snap.uFlowVolts, snap.uTempVolts,
      snap.lastRttMs, snap.throughputBps, lossPct
    );
  }
}