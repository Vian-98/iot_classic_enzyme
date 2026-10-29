/**
 * ==============================================================================
 * Classic Enzyme IoT - ESP32 Firmware
 * File: esp32_classic_enzyme.ino
 * 
 * Target Board: ESP32 Dev Module / NodeMCU-32S
 * Target Backend: Classic Enzyme Web Dashboard (api/telemetry.php)
 * 
 * Hardware Pinout:
 * 1. MAX6675 (Termokopel K - Suhu Bioreaktor):
 *    - SCK  -> GPIO 18
 *    - SO   -> GPIO 19
 *    - CS   -> GPIO 5
 *    - VCC  -> 3.3V / 5V
 *    - GND  -> GND
 * 
 * 2. MQ-3 (Sensor Gas Alkohol Fermentasi):
 *    - AO   -> GPIO 34 (ADC1_CH6 - Input Only)
 *    - VCC  -> 5V
 *    - GND  -> GND
 * 
 * 3. Sensor pH (PH-110 Analog Probe):
 *    - PO (Analog) -> GPIO 35 (ADC1_CH7)
 *    - VCC  -> 5V
 *    - GND  -> GND
 * ==============================================================================
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <esp_system.h>
#include <Preferences.h>
#include <time.h>
#include "secrets.h"

// ==============================================================================
// 1. DATA STRUCTURES & ENDPOINT CONFIGURATIONS
// ==============================================================================

struct TelemetrySample {
  float    temperature;   // NAN jika sensor mati / off / di luar rentang
  float    ph;            // NAN jika sensor mati / off / di luar rentang
  int      alcohol;       // raw ADC (-1 jika sensor mati / off / di luar rentang)
  float    alcoholPpm;    // -1.0f jika sensor mati / off / di luar rentang
  int      rssi;
  time_t   ts;
  uint32_t sequence;
  char     bootId[33];
};

struct EndpointConfig {
  const char* name;
  const char* url;
  const char* apiKey;
  const char* apiKeyHeader;
  const char* apiKeyPrefix;
  const char* caCert;
  bool        enabled;
};

// Konfigurasi Endpoint Primary (Backend Utama Classic Enzyme - Production)
static const EndpointConfig PRIMARY_ENDPOINT = {
  "primary",
  SERVER_URL,
  API_KEY,
  "X-API-Key",
  "",              // Primary tidak memakai prefix pada header auth
  TLS_ROOT_CA,
  true
};

// Konfigurasi Endpoint Secondary (Server Uji Milik Sendiri)
// Server secondary menggunakan format payload dan autentikasi yang sama
// dengan primary (api/telemetry.php yang identik), sehingga API_KEY_HEADER_2
// dan API_KEY_PREFIX_2 mengikuti konvensi yang sama: "X-API-Key" tanpa prefix.
static const EndpointConfig SECONDARY_ENDPOINT = {
  "secondary",
  SERVER_URL_2,
  API_KEY_2,
  API_KEY_HEADER_2,
  API_KEY_PREFIX_2,
  TLS_ROOT_CA_2,
  SECONDARY_ENDPOINT_ENABLED
};

// ==============================================================================
// 2. KONFIGURASI JARINGAN & SERVER BACKEND
// ==============================================================================

const char* FIRMWARE_VERSION = "2.2.0";

// Interval Pengiriman Data (dalam milidetik: 5000 ms = 5 detik atau 5UL * 60UL * 1000UL = 5 menit)
const unsigned long SEND_INTERVAL_MS = 5UL * 60UL * 1000UL;


// ==============================================================================
// 2. STATUS HARDWARE SENSOR (HANYA SENSOR TERPASANG YANG DIKIRIM)
// Set 'true' jika sensor sudah dicolok ke pin ESP32.
// Set 'false' jika belum dipasang -> sistem otomatis mengirim 'null' (status: MATI/OFF)
// ==============================================================================
const bool SENSOR_SUHU_TERPASANG    = true;   // MAX6675 (Sudah dipasang)
const bool SENSOR_PH_TERPASANG      = false;  // PH-110 (Belum dipasang -> kirim null)
const bool SENSOR_ALKOHOL_TERPASANG = true;  // MQ-3 (Sudah dipasang)

// ==============================================================================
// 3. PIN DEFINITIONS
// ==============================================================================

// MAX6675 (Suhu)
const int PIN_MAX6675_SCK = 18;
const int PIN_MAX6675_SO  = 19;
const int PIN_MAX6675_CS  = 5;

// MQ-3 (Alkohol)
const int PIN_MQ3_AO = 34;

// pH Sensor (Analog Default)
const int PIN_PH_ANALOG = 35;

// LED Indikator Status Bawaan ESP32 (GPIO 2)
const int PIN_LED_BUILTIN = 2;

// ==============================================================================
// 4. KALIBRASI SENSOR
// ==============================================================================
const float PH_7_VOLTAGE = 1.65; // Volt pada larutan kalibrasi pH 7.0
const float PH_SLOPE     = 0.18; // Volt per unit pH

// ==============================================================================
// Kalibrasi MQ-3 (Alkohol - Ethanol)
//
// MQ-3 mengukur Rs (resistansi sensor), bukan ADC mentah:
//   Vout = rawAdc / MQ3_ADC_MAX * MQ3_VREF
//   Rs   = (MQ3_VC - Vout) / Vout * RL
// RL saling menghilangkan saat Rs/R0, jadi cukup menyimpan rasio Rs.
//
// R0 = Rs saat sensor berada di udara bersih. Kalibrasi lewat Serial (CAL)
// dan hasilnya disimpan di NVS. Isi MQ3_R0_RATIO manual jika sudah punya
// hasil kalibrasi. Pastikan sensor benar-benar di udara bersih saat CAL.
// ==============================================================================
const float MQ3_VC         = 5.0f;    // Tegangan suplai modul MQ-3 (Volt)
const float MQ3_VREF       = 3.3f;    // Referensi ADC ESP32 di pin GPIO34 (Volt)
const float MQ3_VOUT_SCALE = 1.0f;    // Vout modul per volt di pin (2.0 jika ada pembagi 1:2)
const float MQ3_ADC_MAX    = 4095.0f; // Resolusi ADC 12-bit
const float MQ3_CURVE_A  = 0.3934f;
const float MQ3_CURVE_B  = -1.504f;
const float MQ3_PPM_MIN  = 1.0f;    // Di bawah ini dianggap di luar rentang ukur
const float MQ3_PPM_MAX  = 10000.0f;

const float MQ3_R0_RATIO        = 37.78f; // Kalibrasi manual dari baseline ADC 160 (telemetri_pra_pencatatan.csv)
const bool  MQ3_AUTO_CALIBRATE  = false; // true = ukur R0 otomatis saat NVS kosong (hanya jika yakin di udara bersih)
const bool  MQ3_FORCE_RECALIBRATE = false; // true = ukur ulang R0 saat boot
const int   MQ3_CAL_SAMPLES     = 50;
const int   MQ3_CAL_DELAY_MS    = 100;

// ==============================================================================
// 5. VARIABEL GLOBAL
// ==============================================================================
unsigned long lastSendTime = 0;
int sendCounter = 0;
uint32_t telemetrySequence = 0;
char bootId[33] = {0};
float mq3R0Ratio = 0.0f; // Rs/R0 di udara bersih, hasil kalibrasi
Preferences mq3Prefs;

const char* NTP_SERVER_PRIMARY = "pool.ntp.org";
const char* NTP_SERVER_FALLBACK = "time.google.com";
const time_t MIN_VALID_EPOCH = 1704067200; // 2024-01-01 UTC

// ==============================================================================
// 6. HELPER PEMBACAAN SENSOR (DENGAN DETEKSI KABEL LEPAS)
// ==============================================================================

/**
 * Membaca suhu dari modul MAX6675 via SPI Bit-Bang
 * Mengembalikan NAN jika probe putus, kabel lepas, atau modul tidak terdeteksi
 */
float readMAX6675() {
  uint16_t v = 0;

  digitalWrite(PIN_MAX6675_CS, LOW);
  delayMicroseconds(2);

  // Baca 16-bit register MAX6675
  for (int i = 15; i >= 0; i--) {
    digitalWrite(PIN_MAX6675_SCK, HIGH);
    delayMicroseconds(2);
    if (digitalRead(PIN_MAX6675_SO)) {
      v |= (1 << i);
    }
    digitalWrite(PIN_MAX6675_SCK, LOW);
    delayMicroseconds(2);
  }

  digitalWrite(PIN_MAX6675_CS, HIGH);

  // Cek apakah modul terhubung (jika semua bit 1 atau semua bit 0 = pin tidak terhubung)
  if (v == 0x0000 || v == 0xFFFF) {
    Serial.println("[MAX6675] Sensor TIDAK TERDETEKSI (Periksa kabel SCK/SO/CS/VCC)!");
    return NAN;
  }

  // Bit D2: Termokopel open-circuit (kabel probe thermocouple lepas/putus)
  if (v & 0x04) {
    Serial.println("[MAX6675] Probe termokopel LEPAS / OPEN-CIRCUIT!");
    return NAN;
  }

  // Bit D14 - D3 adalah data suhu (12-bit, resolusi 0.25 C)
  v >>= 3;
  return v * 0.25;
}

/**
 * Membaca nilai ADC Analog MQ-3 (Alkohol)
 */
int readMQ3() {
  long total = 0;
  const int SAMPLES = 10;
  for (int i = 0; i < SAMPLES; i++) {
    total += analogRead(PIN_MQ3_AO);
    delay(5);
  }
  return (int)(total / SAMPLES);
}

/**
 * Mengubah ADC menjadi rasio Rs (tanpa RL karena hanya dipakai sebagai rasio).
 * Mengembalikan -1 jika tegangan di luar rentang yang masuk akal.
 */
float mq3RsRatioFromAdc(int rawAdc) {
  if (rawAdc <= 0 || rawAdc >= (int)MQ3_ADC_MAX) return -1.0f;

  float vout = ((float)rawAdc / MQ3_ADC_MAX) * MQ3_VREF * MQ3_VOUT_SCALE;
  if (vout <= 0.01f || vout >= (MQ3_VC - 0.01f)) return -1.0f;

  return (MQ3_VC - vout) / vout;
}

/**
 * Kalibrasi R0 (Rs di udara bersih). Prioritas: MQ3_R0_RATIO manual,
 * R0 tersimpan di NVS, lalu pengukuran baru (CAL atau MQ3_AUTO_CALIBRATE).
 * Mengembalikan true jika R0 tersedia.
 */
bool calibrateMQ3(bool forceMeasure = false) {
  if (!SENSOR_ALKOHOL_TERPASANG) return false;

  if (MQ3_R0_RATIO > 0.0f) {
    mq3R0Ratio = MQ3_R0_RATIO;
    Serial.printf("[MQ-3] R0 manual: %.4f\n", mq3R0Ratio);
    return true;
  }

  bool mustMeasure = forceMeasure || MQ3_FORCE_RECALIBRATE;
  if (!mustMeasure) {
    mq3Prefs.begin("mq3", true);
    float stored = mq3Prefs.getFloat("r0", 0.0f);
    mq3Prefs.end();
    if (stored > 0.0f) {
      mq3R0Ratio = stored;
      Serial.printf("[MQ-3] R0 tersimpan (NVS): %.4f\n", mq3R0Ratio);
      return true;
    }
  }

  if (!MQ3_AUTO_CALIBRATE && !mustMeasure) {
    Serial.println("[MQ-3] Auto-kalibrasi mati dan R0 belum ada; ppm akan null.");
    return false;
  }

  Serial.println("[MQ-3] Kalibrasi R0: pastikan sensor di udara bersih...");
  float sum = 0.0f;
  float adcSum = 0.0f;
  int valid = 0;
  for (int i = 0; i < MQ3_CAL_SAMPLES; i++) {
    int rawAdc = readMQ3();
    float ratio = mq3RsRatioFromAdc(rawAdc);
    if (ratio > 0.0f) {
      sum += ratio;
      adcSum += rawAdc;
      valid++;
    }
    delay(MQ3_CAL_DELAY_MS);
  }

  if (valid < MQ3_CAL_SAMPLES / 2) {
    Serial.println("[MQ-3] Kalibrasi gagal: pembacaan ADC tidak valid.");
    return false;
  }

  mq3R0Ratio = sum / (float)valid;
  mq3Prefs.begin("mq3", false);
  mq3Prefs.putFloat("r0", mq3R0Ratio);
  mq3Prefs.end();
  Serial.printf("[MQ-3] R0 ratio = %.4f (ADC ~%.0f, %d sampel) tersimpan di NVS\n",
                mq3R0Ratio, adcSum / (float)valid, valid);
  return true;
}

/**
 * Menghitung konsentrasi etanol (ppm) dari pembacaan ADC MQ-3.
 * Kurva power Hanwei: ppm = A * (Rs/R0)^B
 */
float readMQ3_ppm(int rawAdc = -1) {
  if (rawAdc < 0) {
    rawAdc = readMQ3();
  }

  float rsRatio = mq3RsRatioFromAdc(rawAdc);
  if (rsRatio <= 0.0f) return -1.0f;

  float r0 = (mq3R0Ratio > 0.0f) ? mq3R0Ratio : MQ3_R0_RATIO;
  if (r0 <= 0.0f) return -1.0f;

  float ratio = rsRatio / r0;
  if (ratio <= 0.0f) return -1.0f;

  float ppm = MQ3_CURVE_A * pow(ratio, MQ3_CURVE_B);

  if (ppm < MQ3_PPM_MIN || ppm > MQ3_PPM_MAX) return -1.0f;

  return ppm;
}

/**
 * Membaca nilai pH dari probe analog
 */
float readPH() {
  long total = 0;
  const int SAMPLES = 15;
  for (int i = 0; i < SAMPLES; i++) {
    total += analogRead(PIN_PH_ANALOG);
    delay(5);
  }
  float avgAdc = (float)total / SAMPLES;
  float voltage = (avgAdc / 4095.0) * 3.3;
  float phVal = 7.0 + ((PH_7_VOLTAGE - voltage) / PH_SLOPE);

  if (phVal < 0.0) phVal = 0.0;
  if (phVal > 14.0) phVal = 14.0;
  return phVal;
}

// ==============================================================================
// 7. SETUP
// ==============================================================================
void setup() {
  Serial.begin(115200);
  delay(1000);

  Serial.println("\n==========================================");
  Serial.println("  Classic Enzyme IoT - ESP32 Firmware     ");
  Serial.println("==========================================");

  // Inisialisasi Pin GPIO
  pinMode(PIN_MAX6675_CS, OUTPUT);
  pinMode(PIN_MAX6675_SCK, OUTPUT);
  pinMode(PIN_MAX6675_SO, INPUT);
  digitalWrite(PIN_MAX6675_CS, HIGH);

  pinMode(PIN_MQ3_AO, INPUT);
  pinMode(PIN_PH_ANALOG, INPUT);
  pinMode(PIN_LED_BUILTIN, OUTPUT);
  digitalWrite(PIN_LED_BUILTIN, LOW);

  analogReadResolution(12);
  analogSetAttenuation(ADC_11db);

  if (SENSOR_ALKOHOL_TERPASANG) {
    calibrateMQ3();
  }

  snprintf(bootId, sizeof(bootId), "%08lx%08lx%08lx%08lx",
    (unsigned long)esp_random(), (unsigned long)esp_random(),
    (unsigned long)esp_random(), (unsigned long)esp_random());

  connectWiFi();
  syncClock();
}

// ==============================================================================
// 8. KONEKSI WIFI
// ==============================================================================
void connectWiFi() {
  Serial.printf("[WiFi] Menghubungkan ke '%s'", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 25) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n[WiFi] Berhasil Terhubung!");
    Serial.print("[WiFi] IP Address : ");
    Serial.println(WiFi.localIP());
    Serial.print("[WiFi] RSSI Signal: ");
    Serial.print(WiFi.RSSI());
    Serial.println(" dBm");
  } else {
    Serial.println("\n[WiFi] GAGAL terhubung. Periksa SSID & Password.");
  }
}

/** Sinkronkan waktu UTC sebelum TLS dan payload anti-replay digunakan. */
bool syncClock() {
  if (WiFi.status() != WL_CONNECTED) return false;

  time_t now;
  time(&now);
  if (now >= MIN_VALID_EPOCH) return true;

  Serial.println("[NTP] Menyinkronkan waktu...");
  configTime(0, 0, NTP_SERVER_PRIMARY, NTP_SERVER_FALLBACK);
  for (int attempt = 0; attempt < 20; attempt++) {
    delay(500);
    time(&now);
    if (now >= MIN_VALID_EPOCH) {
      Serial.println("[NTP] Waktu tersinkronkan.");
      return true;
    }
  }

  Serial.println("[NTP] Gagal mendapatkan waktu; telemetri HTTPS ditunda.");
  return false;
}

bool isHttpsUrl(const char* url) {
  return url != nullptr && strncmp(url, "https://", 8) == 0;
}

// ==============================================================================
// 10. PEMBENTUKAN PAYLOAD & PENGIRIMAN DUAL ENDPOINT
// ==============================================================================

/**
 * Menyusun payload v2 untuk backend utama Classic Enzyme (SERVER_URL).
 * Mempertahankan kompatibilitas penuh dengan skema api/telemetry.php.
 */
bool buildPrimaryPayload(const TelemetrySample& s, char* out, size_t outSize) {
  char tempStr[16];
  if (SENSOR_SUHU_TERPASANG && !isnan(s.temperature)) {
    snprintf(tempStr, sizeof(tempStr), "%.2f", s.temperature);
  } else {
    strcpy(tempStr, "null");
  }

  char phStr[16];
  if (SENSOR_PH_TERPASANG && !isnan(s.ph)) {
    snprintf(phStr, sizeof(phStr), "%.2f", s.ph);
  } else {
    strcpy(phStr, "null");
  }

  char alcoholStr[16];
  if (SENSOR_ALKOHOL_TERPASANG && s.alcohol >= 0) {
    snprintf(alcoholStr, sizeof(alcoholStr), "%d", s.alcohol);
  } else {
    strcpy(alcoholStr, "null");
  }

  char alcoholPpmStr[16];
  if (SENSOR_ALKOHOL_TERPASANG && s.alcoholPpm >= 0) {
    snprintf(alcoholPpmStr, sizeof(alcoholPpmStr), "%.2f", s.alcoholPpm);
  } else {
    strcpy(alcoholPpmStr, "null");
  }

  int written = snprintf(out, outSize,
    "{"
      "\"device_id\":\"%s\","
      "\"temperature\":%s,"
      "\"ph\":%s,"
      "\"alcohol\":%s,"
      "\"alcohol_ppm\":%s,"
      "\"raw_temp\":%s,"
      "\"raw_adc\":%s,"
      "\"rssi\":%d,"
      "\"firmware\":\"%s\","
      "\"protocol_version\":2,"
      "\"ts\":%lu,"
      "\"boot_id\":\"%s\","
      "\"sequence\":%lu"
    "}",
    DEVICE_ID,
    tempStr,
    phStr,
    alcoholStr,
    alcoholPpmStr,
    tempStr,
    alcoholStr,
    s.rssi,
    FIRMWARE_VERSION,
    (unsigned long)s.ts,
    s.bootId,
    (unsigned long)s.sequence
  );

  return (written > 0 && (size_t)written < outSize);
}

/**
 * Payload untuk server secondary (server uji milik sendiri, SERVER_URL_2).
 * Menggunakan format v2 yang sama dengan primary karena server secondary
 * dikontrol penuh dan menggunakan api/telemetry.php yang identik.
 * Jika di kemudian hari server secondary memakai skema berbeda,
 * ubah hanya fungsi ini tanpa menyentuh buildPrimaryPayload().
 */
bool buildSecondaryPayload(const TelemetrySample& s, char* out, size_t outSize) {
  // Pakai format payload v2 yang identik dengan primary
  return buildPrimaryPayload(s, out, outSize);
}

/**
 * Mengirim payload JSON ke satu endpoint secara aman (HTTPS TLS tervalidasi).
 * Dijalankan berurutan agar hanya ada satu client TLS yang hidup pada satu waktu.
 */
bool sendToEndpoint(const EndpointConfig& ep, const char* payload) {
  if (!ep.enabled) {
    return false;
  }

  if (!isHttpsUrl(ep.url)) {
    Serial.printf("[%s] URL wajib HTTPS. Pengiriman dibatalkan.\n", ep.name);
    return false;
  }

  if (ep.caCert == nullptr || strlen(ep.caCert) < 10) {
    Serial.printf("[%s] CA certificate kosong atau tidak valid. Pengiriman dibatalkan.\n", ep.name);
    return false;
  }

  unsigned long t0 = millis();

  // Client TLS lokal di dalam fungsi agar hanya satu client TLS hidup pada satu waktu (menghemat RAM)
  WiFiClientSecure client;
  client.setCACert(ep.caCert);

  HTTPClient http;
  Serial.printf("[%s] Mengirim POST ke: %s\n", ep.name, ep.url);
  http.begin(client, ep.url);
  http.addHeader("Content-Type", "application/json");

  // Pasang header autentikasi sesuai konfigurasi endpoint
  if (ep.apiKey != nullptr && strlen(ep.apiKey) > 0 &&
      ep.apiKeyHeader != nullptr && strlen(ep.apiKeyHeader) > 0) {
    char authValue[256];
    const char* prefix = (ep.apiKeyPrefix != nullptr) ? ep.apiKeyPrefix : "";
    snprintf(authValue, sizeof(authValue), "%s%s", prefix, ep.apiKey);
    http.addHeader(ep.apiKeyHeader, authValue);
  }

  http.setTimeout(4000);

  int httpCode = http.POST((uint8_t*)payload, strlen(payload));
  unsigned long elapsed = millis() - t0;

  bool success = false;
  if (httpCode > 0) {
    // Semua kode HTTP 2xx dianggap berhasil
    success = (httpCode >= 200 && httpCode < 300);
    Serial.printf("[%s] Respon Server Code: %d (%lu ms)\n", ep.name, httpCode, elapsed);

    String response = http.getString();
    if (response.length() > 0) {
      if (response.length() > 256) {
        response = response.substring(0, 256) + "...";
      }
      Serial.printf("[%s] Respon Body: %s\n", ep.name, response.c_str());
    }

    if (success) {
      Serial.printf("[%s] [SUCCESS] Data telemetri berhasil diterima!\n", ep.name);
    } else {
      Serial.printf("[%s] [WARNING] Server merespon status non-2xx (HTTP %d)\n", ep.name, httpCode);
    }
  } else {
    Serial.printf("[%s] [ERROR] HTTP POST Gagal, Error: %s (%lu ms)\n",
                  ep.name, http.errorToString(httpCode).c_str(), elapsed);
  }

  http.end();
  client.stop();

  return success;
}

/**
 * Orkestrator pengiriman telemetri:
 * 1. Memeriksa koneksi Wi-Fi dan sinkronisasi waktu NTP.
 * 2. Memvalidasi rentang transport sensor satu kali.
 * 3. Membentuk satu TelemetrySample dengan sequence dan timestamp bersama.
 * 4. Mengirimkan ke Primary Endpoint.
 * 5. Jika Secondary Endpoint diaktifkan, mengirimkan ke Secondary Endpoint.
 * 6. Mencatat ringkasan status masing-masing endpoint.
 */
void sendTelemetryData(float temp, float ph, int alcohol, float alcoholPpm, int rssi) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[HTTP] WiFi terputus. Mencoba reconnect...");
    connectWiFi();
    if (WiFi.status() != WL_CONNECTED) return;
  }

  if (!syncClock()) return;

  // Batas transport, bukan threshold proses fermentasi. Nilai tidak masuk akal
  // dikirim sebagai null agar server dapat menandai kualitas data tanpa alarm palsu.
  if (!isnan(temp) && (temp < 0.0f || temp > 100.0f)) {
    Serial.println("[SENSOR] Suhu di luar rentang transport (0-100 C).");
    temp = NAN;
  }
  if (!isnan(ph) && (ph < 0.0f || ph > 14.0f)) {
    Serial.println("[SENSOR] pH di luar rentang transport (0-14).");
    ph = NAN;
  }
  if (alcohol < 0 || alcohol > 4095) {
    if (alcohol > 4095) Serial.println("[SENSOR] ADC MQ-3 di luar rentang (0-4095).");
    alcohol = -1;
    alcoholPpm = -1.0f;
  }

  digitalWrite(PIN_LED_BUILTIN, HIGH);

  // Bentuk satu sampel sensor tunggal untuk dipakai oleh kedua endpoint
  TelemetrySample sample;
  sample.temperature = temp;
  sample.ph          = ph;
  sample.alcohol     = alcohol;
  sample.alcoholPpm  = alcoholPpm;
  sample.rssi        = rssi;
  time(&sample.ts);
  sample.sequence    = ++telemetrySequence;
  strncpy(sample.bootId, bootId, sizeof(sample.bootId) - 1);
  sample.bootId[sizeof(sample.bootId) - 1] = '\0';

  // 1. Kirim ke Endpoint Primary
  bool primaryOk = false;
  char primaryPayload[512];
  if (buildPrimaryPayload(sample, primaryPayload, sizeof(primaryPayload))) {
    Serial.println("[HTTP] Primary Payload JSON:");
    Serial.println(primaryPayload);
    primaryOk = sendToEndpoint(PRIMARY_ENDPOINT, primaryPayload);
  } else {
    Serial.println("[HTTP] [ERROR] Gagal menyusun payload primary.");
  }

  // 2. Kirim ke Endpoint Secondary (jika diaktifkan)
  bool secondaryOk = false;
  bool secondarySkip = !SECONDARY_ENDPOINT.enabled;

  if (SECONDARY_ENDPOINT.enabled) {
    // Jika WiFi terputus saat request primary, coba hubungkan kembali
    if (WiFi.status() != WL_CONNECTED) {
      Serial.println("[HTTP] WiFi terputus sebelum secondary. Mencoba reconnect...");
      connectWiFi();
    }

    if (WiFi.status() == WL_CONNECTED) {
      char secondaryPayload[512];
      if (buildSecondaryPayload(sample, secondaryPayload, sizeof(secondaryPayload))) {
        Serial.println("[HTTP] Secondary Payload JSON:");
        Serial.println(secondaryPayload);
        secondaryOk = sendToEndpoint(SECONDARY_ENDPOINT, secondaryPayload);
      } else {
        Serial.println("[HTTP] [ERROR] Gagal menyusun payload secondary.");
      }
    } else {
      Serial.println("[HTTP] WiFi tidak tersambung. Secondary dilewati.");
    }
  }

  // Ringkasan status pengiriman per siklus
  Serial.printf("[SUMMARY] primary=%s secondary=%s\n\n",
    primaryOk ? "OK" : "FAIL",
    secondarySkip ? "SKIP" : (secondaryOk ? "OK" : "FAIL")
  );

  digitalWrite(PIN_LED_BUILTIN, LOW);
}

// ==============================================================================
// 9b. PERINTAH KALIBRASI VIA SERIAL
// ==============================================================================
void handleSerialCommand() {
  if (!Serial.available()) return;

  String cmd = Serial.readStringUntil('\n');
  cmd.trim();
  if (cmd.length() == 0) return;

  if (cmd.equalsIgnoreCase("CAL")) {
    calibrateMQ3(true);
  } else if (cmd.equalsIgnoreCase("R0")) {
    Serial.printf("[MQ-3] R0 ratio = %.4f\n", mq3R0Ratio);
  } else if (cmd.equalsIgnoreCase("RESET")) {
    mq3Prefs.begin("mq3", false);
    mq3Prefs.remove("r0");
    mq3Prefs.end();
    mq3R0Ratio = 0.0f;
    Serial.println("[MQ-3] R0 dihapus. Kirim CAL saat sensor di udara bersih.");
  } else {
    Serial.println("[CMD] CAL = kalibrasi R0, R0 = lihat nilai, RESET = hapus R0");
  }
}

// ==============================================================================
// 10. LOOP UTAMA
// ==============================================================================
void loop() {
  handleSerialCommand();

  unsigned long currentMillis = millis();

  if (currentMillis - lastSendTime >= SEND_INTERVAL_MS || lastSendTime == 0) {
    lastSendTime = currentMillis;
    sendCounter++;

    Serial.println("--------------------------------------------------");
    Serial.printf("Transmisi Data Telemetri #%d\n", sendCounter);

    // 1. Suhu (MAX6675)
    float temperature = NAN;
    if (SENSOR_SUHU_TERPASANG) {
      temperature = readMAX6675();
      if (isnan(temperature)) {
        Serial.println("Suhu           : [ERROR / KABEL LEPAS]");
      } else {
        Serial.printf("Suhu           : %.2f °C\n", temperature);
      }
    } else {
      Serial.println("Suhu           : [SENSOR DINONAKTIFKAN]");
    }

    // 2. pH (PH-110)
    float ph = NAN;
    if (SENSOR_PH_TERPASANG) {
      ph = readPH();
      Serial.printf("pH             : %.2f\n", ph);
    } else {
      Serial.println("pH             : [SENSOR MATI / BELUM DIPASANG]");
    }

    // 3. Alkohol (MQ-3)
    int alcohol = -1;
    float alcoholPpm = -1.0f;
    if (SENSOR_ALKOHOL_TERPASANG) {
      alcohol    = readMQ3();
      alcoholPpm = readMQ3_ppm(alcohol);
      Serial.printf("Alkohol (ADC)  : %d\n", alcohol);
      if (alcoholPpm >= 0) {
        Serial.printf("Alkohol (ppm)  : %.2f ppm\n", alcoholPpm);
      } else {
        Serial.println("Alkohol (ppm)  : [Di luar rentang / tidak valid]");
      }
    } else {
      Serial.println("Alkohol        : [SENSOR MATI / BELUM DIPASANG]");
    }

    // 4. WiFi RSSI
    int rssi = WiFi.status() == WL_CONNECTED ? WiFi.RSSI() : -70;
    Serial.printf("WiFi RSSI      : %d dBm\n", rssi);

    // 5. Kirimkan ke Web Backend (Sensor yang mati dikirim 'null')
    sendTelemetryData(temperature, ph, alcohol, alcoholPpm, rssi);
  }

  delay(50);
}
