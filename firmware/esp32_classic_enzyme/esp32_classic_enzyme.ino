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

// ==============================================================================
// 1. KONFIGURASI JARINGAN & SERVER BACKEND
// ==============================================================================

const char* WIFI_SSID     = "fathur";
const char* WIFI_PASSWORD = "PASSWORD_WIFI_ANDA"; // <-- Masukkan password WiFi Anda di sini

// IP Laptop / Web Server lokal
const char* SERVER_URL = "http://192.168.1.3:8899/api/telemetry.php";

// Kredensial Otentikasi Perangkat (harus sesuai dengan tabel 'devices')
const char* DEVICE_ID = "esp32-ce-001";
const char* API_KEY   = "ce-secret-key-001";
const char* FIRMWARE_VERSION = "1.0.0";

// Interval Pengiriman Data (dalam milidetik: 5000 ms = 5 detik)
const unsigned long SEND_INTERVAL_MS = 5000;

// ==============================================================================
// 2. STATUS HARDWARE SENSOR (HANYA SENSOR TERPASANG YANG DIKIRIM)
// Set 'true' jika sensor sudah dicolok ke pin ESP32.
// Set 'false' jika belum dipasang -> sistem otomatis mengirim 'null' (status: MATI/OFF)
// ==============================================================================
const bool SENSOR_SUHU_TERPASANG    = true;   // MAX6675 (Sudah dipasang)
const bool SENSOR_PH_TERPASANG      = false;  // PH-110 (Belum dipasang -> kirim null)
const bool SENSOR_ALKOHOL_TERPASANG = false;  // MQ-3 (Belum dipasang -> kirim null)

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
// 5. VARIABEL GLOBAL
// ==============================================================================
unsigned long lastSendTime = 0;
int sendCounter = 0;

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

  connectWiFi();
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

// ==============================================================================
// 9. PENGIRIMAN DATA TELEMETRI VIA HTTP POST
// ==============================================================================
void sendTelemetryData(float temp, float ph, int alcohol, int rssi) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[HTTP] WiFi terputus. Mencoba reconnect...");
    connectWiFi();
    if (WiFi.status() != WL_CONNECTED) return;
  }

  digitalWrite(PIN_LED_BUILTIN, HIGH);

  // Gunakan WiFiClientSecure untuk koneksi HTTPS (Cloudflare)
  WiFiClientSecure client;
  client.setInsecure(); // Skip verifikasi sertifikat SSL agar hemat memori di ESP32

  HTTPClient http;

  Serial.printf("[HTTP] Mengirim POST ke: %s\n", SERVER_URL);
  http.begin(client, SERVER_URL);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);
  http.setTimeout(4000);

  // Buffer string untuk nilai sensor (jika sensor mati/lepas, kirim null secara eksplisit)
  char tempStr[16];
  if (SENSOR_SUHU_TERPASANG && !isnan(temp)) {
    snprintf(tempStr, sizeof(tempStr), "%.2f", temp);
  } else {
    strcpy(tempStr, "null");
  }

  char phStr[16];
  if (SENSOR_PH_TERPASANG && !isnan(ph)) {
    snprintf(phStr, sizeof(phStr), "%.2f", ph);
  } else {
    strcpy(phStr, "null");
  }

  char alcoholStr[16];
  if (SENSOR_ALKOHOL_TERPASANG && alcohol >= 0) {
    snprintf(alcoholStr, sizeof(alcoholStr), "%d", alcohol);
  } else {
    strcpy(alcoholStr, "null");
  }

  // Buat payload JSON
  char jsonBuffer[384];
  snprintf(jsonBuffer, sizeof(jsonBuffer),
    "{"
      "\"device_id\":\"%s\","
      "\"api_key\":\"%s\","
      "\"temperature\":%s,"
      "\"ph\":%s,"
      "\"alcohol\":%s,"
      "\"raw_temp\":%s,"
      "\"raw_adc\":%s,"
      "\"rssi\":%d,"
      "\"firmware\":\"%s\","
      "\"ts\":%lu"
    "}",
    DEVICE_ID,
    API_KEY,
    tempStr,
    phStr,
    alcoholStr,
    tempStr,
    alcoholStr,
    rssi,
    FIRMWARE_VERSION,
    (unsigned long)(millis() / 1000)
  );

  Serial.println("[HTTP] Payload JSON:");
  Serial.println(jsonBuffer);

  int httpCode = http.POST((uint8_t*)jsonBuffer, strlen(jsonBuffer));

  if (httpCode > 0) {
    Serial.printf("[HTTP] Respon Server Code: %d\n", httpCode);
    String response = http.getString();
    Serial.println("[HTTP] Respon Body:");
    Serial.println(response);

    if (httpCode == 200 || httpCode == 201) {
      Serial.println("[SUCCESS] Data telemetri berhasil tercatat!\n");
    } else {
      Serial.printf("[WARNING] Server merespon HTTP %d\n\n", httpCode);
    }
  } else {
    Serial.printf("[ERROR] HTTP POST Gagal, Error: %s\n\n", http.errorToString(httpCode).c_str());
  }

  http.end();
  digitalWrite(PIN_LED_BUILTIN, LOW);
}

// ==============================================================================
// 10. LOOP UTAMA
// ==============================================================================
void loop() {
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
    if (SENSOR_ALKOHOL_TERPASANG) {
      alcohol = readMQ3();
      Serial.printf("Alkohol (ADC)  : %d\n", alcohol);
    } else {
      Serial.println("Alkohol        : [SENSOR MATI / BELUM DIPASANG]");
    }

    // 4. WiFi RSSI
    int rssi = WiFi.status() == WL_CONNECTED ? WiFi.RSSI() : -70;
    Serial.printf("WiFi RSSI      : %d dBm\n", rssi);

    // 5. Kirimkan ke Web Backend (Sensor yang mati dikirim 'null')
    sendTelemetryData(temperature, ph, alcohol, rssi);
  }

  delay(50);
}
