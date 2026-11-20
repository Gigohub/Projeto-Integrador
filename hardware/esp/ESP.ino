#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include "config.h"   // WIFI_SSID, WIFI_PASS, SERVER, DEVICE_ID, TOKEN

bool enableCtrl = false;
float setpoint = 25.0;

float lerTemperatura() {
  // TODO: ler o sensor (ADC) e converter V -> °C com a equação da LCON-I
  return 25.0;
}

void postReading(float t) {
  HTTPClient http;
  http.begin(String(SERVER) + "/readings");
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", String("Bearer ") + TOKEN);
  StaticJsonDocument<200> doc;
  doc["device_id"] = DEVICE_ID;
  doc["variables"]["temp"] = t;
  String body; serializeJson(doc, body);
  http.POST(body);
  http.end();
}

void getCommands() {
  HTTPClient http;
  http.begin(String(SERVER) + "/commands");
  http.addHeader("Authorization", String("Bearer ") + TOKEN);
  if (http.GET() == 200) {
    StaticJsonDocument<512> doc;
    if (!deserializeJson(doc, http.getString())) {
      for (JsonObject c : doc.as<JsonArray>()) {
        String n = c["name"];
        if (n == "setpoint") setpoint = c["value"].as<float>();
        if (n == "enable")   enableCtrl = c["value"].as<int>() == 1;
      }
      // TODO: POST /commands/ack
    }
  }
  http.end();
}

void setup() {
  Serial.begin(115200);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  while (WiFi.status() != WL_CONNECTED) delay(300);
}

void loop() {
  static unsigned long t0 = 0;
  if (millis() - t0 >= 1000) {          // 1 Hz; ajuste conforme o desempenho
    t0 = millis();
    postReading(lerTemperatura());
    getCommands();
  }
}