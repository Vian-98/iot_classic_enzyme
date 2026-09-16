# Classic Enzyme IoT — Backend & Liquid Glass Dashboard (v1.0)

Sistem monitoring fermentasi Classic Enzyme berbasis **100% Native PHP + Vanilla CSS + Vanilla JS + MySQL**.
Dirancang khusus untuk keandalan maksimal di cPanel / Shared Hosting maupun VPS tanpa butuh Node.js, npm, ataupun Composer.

---

## Fitur Utama

- **100% Native Vanilla**: Cukup upload file via File Manager cPanel / FTP, langsung aktif.
- **Nafas Utama Liquid Glass**: Desain antarmuka kaca cair dengan refraksi lensa (SVG Filter `#glass-distortion`), pantulan tepi spekular, dan animasi ambient fluid.
- **Dual Mode (Cerah & Gelap)**: Beralih tema kapan saja via tombol switch dengan penyimpanan status di browser (`localStorage`).
- **Pelacakan "Kapan Terakhir Diterima"**: Menghitung selisih waktu secara dinamis ("*3 detik lalu*", "*2 menit lalu*") dan otomatis menandai device **ONLINE** (<= 30 detik) atau **OFFLINE** (> 30 detik).
- **Pencatatan Histori Lengkap**: Seluruh data tersimpan di tabel `telemetry` dengan timestamp penerimaan di server (`received_at`) dan timestamp internal ESP32 (`device_ts`).
- **Multi-Sensor Ready**: Siap menerima pembacaan 3 sensor fermentasi:
  1. Suhu (°C) — **MAX6675**
  2. pH — **PH-110** (RS485/Analog)
  3. Alkohol — **MQ-3** (ADC/ppm)
  4. Kekuatan Sinyal — **WiFi RSSI** (dBm)
- **Ekspor Laporan**: Fitur unduh log telemetri ke format CSV untuk analisis laboratorium di Excel.

---

## Struktur Berkas

```
IOT/
├── config/
│   └── database.php         # Koneksi PDO (MySQL dengan auto-fallback SQLite saat dev lokal)
├── api/
│   ├── telemetry.php        # [POST] Ingest data ESP32 + update last_seen + alarm check
│   ├── latest.php           # [GET]  Data realtime & kalkulasi status online/offline
│   ├── history.php          # [GET]  Data histori untuk grafik Chart.js & filter rentang waktu
│   └── devices.php          # [GET]  Daftar seluruh bioreaktor terdaftar
├── db/
│   └── schema.sql           # Schema MySQL (devices, telemetry, alarms) + seed data
├── assets/
│   ├── css/
│   │   └── style.css        # Sistem desain Liquid Glass, mode cerah/gelap, responsive
│   └── js/
│       └── app.js           # Polling 5s, ticker waktu relatif, Chart.js, simulasi data
├── history.php              # Halaman riwayat telemetri, filter rentang tanggal & ekspor CSV
├── index.php                # Dashboard realtime utama berestetika Liquid Glass
├── .htaccess                # Proteksi akses folder config/ & db/
└── README.md                # Dokumentasi instalasi dan integrasi firmware ESP32
```

---

## Panduan Menjalankan Secara Lokal (Testing di Laptop)

1. Buka Terminal di folder proyek ini:
   ```bash
   cd "/Users/favian/Proyek /Classic Enzyme/IOT"
   ```
2. Jalankan PHP Built-in Web Server:
   ```bash
   php -S 127.0.0.1:8080
   ```
3. Buka browser di alamat:
   ```
   http://127.0.0.1:8080
   ```
   *Catatan: Saat pengujian lokal di Mac tanpa MySQL, sistem otomatis menggunakan SQLite lokal (`db/iot.sqlite`) sehingga Anda bisa langsung mencoba dashboard tanpa setup database sama sekali!*

4. Untuk menguji pengiriman data simulasi tanpa ESP32 fisik:
   - Klik tombol **"⚡ Simulasi Ingest ESP32"** di dashboard, atau
   - Jalankan perintah curl:
     ```bash
     curl -X POST http://127.0.0.1:8080/api/telemetry.php \
       -H "Content-Type: application/json" \
       -d '{
         "device_id": "esp32-ce-001",
         "api_key": "ce-secret-key-001",
         "temperature": 35.40,
         "ph": 3.82,
         "alcohol": 125,
         "rssi": -62
       }'
     ```

---

## Panduan Deploy ke cPanel Hosting

1. **Buat Database MySQL di cPanel**:
   - Masuk ke cPanel > **MySQL Databases**.
   - Buat database baru (misal: `u1234_classic_enzyme`).
   - Buat user database dan hubungkan dengan hak akses penuh (**ALL PRIVILEGES**).
2. **Import Schema**:
   - Buka **phpMyAdmin** di cPanel.
   - Pilih database yang baru dibuat, klik tab **Import**, lalu pilih file `db/schema.sql`.
3. **Konfigurasi Kredensial**:
   - Edit file `config/database.php` melalui File Manager:
     ```php
     define('DB_DRIVER', 'mysql');
     define('DB_HOST',   'localhost'); // atau 127.0.0.1
     define('DB_NAME',   'u1234_classic_enzyme');
     define('DB_USER',   'u1234_iotuser');
     define('DB_PASS',   'PasswordKuatAnda');
     ```
4. **Upload File**:
   - Upload seluruh folder/file proyek ini ke direktori tujuan (misal: `public_html/iot` atau langsung di `public_html`).

---

## Format Integrasi Firmware ESP32 (HTTP POST)

Setiap 5 detik (atau interval yang diinginkan), firmware ESP32 mengirim HTTP POST JSON ke endpoint:
`http://domain-anda.com/api/telemetry.php`

### JSON Payload:
```json
{
  "device_id": "esp32-ce-001",
  "api_key": "ce-secret-key-001",
  "temperature": 34.75,
  "ph": 3.92,
  "alcohol": 130,
  "raw_temp": 14850,
  "raw_adc": 130,
  "rssi": -64,
  "firmware": "1.0.0",
  "ts": 1726500000
}
```

### Pin Mapping Rekomendasi Hardware ESP32:
- **MAX6675 (Termokopel K)**:
  - SCK = GPIO 18
  - SO  = GPIO 19
  - CS  = GPIO 5
- **PH-110 (RS485 Modbus / Analog)**:
  - RX = GPIO 16
  - TX = GPIO 17
- **MQ-3 (Sensor Alkohol)**:
  - AO (Analog Out) = GPIO 34 (ADC1)
