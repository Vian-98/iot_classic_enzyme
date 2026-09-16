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
- **Panel Admin Terproteksi**: Dilengkapi halaman login (`/login.php`) dan panel konfigurasi (`/admin.php`) untuk:
  - Mengubah batas rentang ideal (Threshold Min/Max) Suhu, pH, dan Alkohol secara realtime
  - Melihat IP Address asli pengirim ESP32 (disembunyikan dari dashboard publik demi keamanan)
  - Mengelola API Key dan nama bioreaktor
  - Mengubah password admin
- **Ekspor Laporan**: Fitur unduh log telemetri ke format CSV untuk analisis laboratorium di Excel (kolom IP hanya muncul saat login sebagai admin).

---

## Struktur Berkas

```
IOT/
├── config/
│   ├── database.php         # Koneksi PDO (MySQL dengan auto-fallback SQLite saat dev lokal)
│   └── auth.php             # Session handler otentikasi & proteksi halaman admin
├── api/
│   ├── telemetry.php        # [POST] Ingest data ESP32 + update last_seen + alarm check
│   ├── latest.php           # [GET]  Data realtime & kalkulasi status online/offline
│   ├── history.php          # [GET]  Data histori untuk grafik Chart.js & filter rentang waktu
│   └── devices.php          # [GET]  Daftar seluruh bioreaktor terdaftar
├── db/
│   ├── schema.sql           # Schema MySQL (devices, telemetry, alarms, admins, thresholds)
│   └── iot.sqlite           # Database lokal SQLite (otomatis dibuat saat dev offline)
├── assets/
│   ├── css/
│   │   └── style.css        # Sistem desain Liquid Glass, mode cerah/gelap, responsive
│   └── js/
│       └── app.js           # Polling 5s, ticker waktu relatif, Chart.js, dynamic thresholds
├── admin.php                # Panel admin (pengaturan threshold, device, akun)
├── login.php                # Halaman login admin
├── logout.php               # Script logout admin
├── history.php              # Halaman riwayat telemetri, filter rentang waktu & ekspor CSV
├── index.php                # Dashboard realtime utama berestetika Liquid Glass
├── .htaccess                # Proteksi akses direktori config/ & db/ (Apache/cPanel)
├── .gitignore               # Exclude database SQLite, logs, dan secrets dari Git
└── README.md                # Dokumentasi instalasi dan integrasi firmware ESP32
```

---

## Akun Login Admin Default

Setelah instalasi (baik via SQLite lokal maupun import `schema.sql` di MySQL):
- **URL Login**: `http://domain-anda.com/login.php`
- **Username**: `admin`
- **Password**: `password`

> **Sangat Disarankan**: Segera ganti password ini setelah berhasil login pertama kali melalui tab **"Pengaturan Akun"** di dalam panel admin.

---

## Panduan Menjalankan Secara Lokal (Testing di Laptop)

1. Buka Terminal di folder proyek ini:
   ```bash
   cd "/Users/favian/Proyek /Classic Enzyme/IOT"
   ```
2. Jalankan PHP Built-in Web Server:
   ```bash
   php -S 127.0.0.1:8899
   ```
3. Buka browser di alamat:
   ```
   http://127.0.0.1:8899
   ```
   *Catatan: Saat pengujian lokal tanpa konfigurasi MySQL, sistem otomatis menggunakan SQLite lokal (`db/iot.sqlite`) dan menginisialisasi seluruh tabel + admin default secara otomatis.*

4. Untuk menguji pengiriman data simulasi tanpa ESP32 fisik:
   - Klik tombol **"⚡ Simulasi Ingest ESP32"** di dashboard, atau
   - Jalankan perintah curl:
     ```bash
     curl -X POST http://127.0.0.1:8899/api/telemetry.php \
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

## Panduan Deploy ke Hosting / VPS (cPanel, Nginx, Apache)

### Metode A: Menggunakan MySQL (Disarankan untuk Produksi)
1. **Buat Database MySQL**:
   - Masuk ke cPanel > **MySQL Databases**.
   - Buat database baru (misal: `u1234_classic_enzyme`).
   - Buat user database dan berikan hak akses penuh (**ALL PRIVILEGES**).
2. **Import Schema**:
   - Buka **phpMyAdmin**.
   - Pilih database tersebut, klik tab **Import**, pilih file `db/schema.sql`.
   - Ini otomatis membuat 5 tabel: `devices`, `telemetry`, `alarms`, `admins`, dan `thresholds` lengkap dengan data awal.
3. **Konfigurasi Kredensial Database**:
   - Buka file `config/database.php`:
     ```php
     define('DB_DRIVER', 'mysql');
     define('DB_HOST',   'localhost'); // atau 127.0.0.1
     define('DB_NAME',   'u1234_classic_enzyme');
     define('DB_USER',   'u1234_iotuser');
     define('DB_PASS',   'PasswordDatabaseAnda');
     ```
4. **Upload File**:
   - Upload seluruh isi folder proyek ke direktori `public_html` (atau subdomain).
   - Pastikan web server mendukung file `.htaccess` (mod_rewrite aktif) agar folder `config/` dan `db/` terproteksi otomatis.

### Metode B: Menggunakan SQLite (Zero Setup di Hosting Murah/VPS)
1. Cukup upload semua file ke server.
2. Di `config/database.php`, biarkan default:
   ```php
   define('DB_DRIVER', 'sqlite');
   ```
3. Pastikan direktori `db/` memiliki permission tulis (CHMOD 755 atau 775) agar file `db/iot.sqlite` dapat ditulis oleh web server.

---

## Format Integrasi Firmware ESP32 (HTTP POST)

Setiap interval pembacaan (misal 5 detik), firmware ESP32 mengirim HTTP POST JSON ke endpoint:
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
