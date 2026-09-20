# Deploy Classic Enzyme IoT ke VPS

Panduan ini memakai Ubuntu, Apache, PostgreSQL, HTTPS langsung melalui IP VPS, dan port aplikasi `8443`.

Contoh yang harus diganti:

```text
IP VPS: 123.123.123.123
App: /var/www/iot
Database: classic_enzyme_iot
DB user: classic_enzyme
Port: 8443
```

## 1. Install paket

```bash
ssh ubuntu@IP_VPS
sudo apt update
sudo apt install -y apache2 postgresql postgresql-contrib php libapache2-mod-php php-pgsql php-mbstring php-curl openssl unzip ufw
sudo a2enmod rewrite headers ssl env
```

Jika muncul error `Permission denied`, gunakan `sudo`. Jika muncul dpkg lock, tunggu proses update otomatis Ubuntu selesai.

Jika VPS sudah memakai Nginx pada port 80, Apache boleh gagal start sementara selama instalasi. Jangan mematikan Nginx; Apache akan dikonfigurasi khusus untuk port `8443` pada langkah berikutnya.

## 2. Buat database PostgreSQL

```bash
sudo -u postgres psql
```

```sql
CREATE USER classic_enzyme WITH PASSWORD 'PASSWORD_DATABASE_KUAT';
CREATE DATABASE classic_enzyme_iot OWNER classic_enzyme;
\q
```

`PASSWORD_DATABASE_KUAT` hanya placeholder. Ganti dengan password database pilihan Anda saat menjalankan perintah tersebut, lalu gunakan password yang sama pada `SetEnv DB_PASS` di konfigurasi Apache. Jangan biarkan teks placeholder ini di VPS.

Jangan membuka port PostgreSQL `5432` ke internet.

## 3. Upload aplikasi

Dari komputer lokal, jalankan dari folder yang berisi direktori `IOT`:

```bash
scp -r IOT/. ubuntu@IP_VPS:/var/www/iot/
```

Atur permission di VPS:

```bash
sudo chown -R www-data:www-data /var/www/iot
sudo find /var/www/iot -type d -exec chmod 755 {} \;
sudo find /var/www/iot -type f -exec chmod 644 {} \;
```

## 4. Konfigurasi Apache port 8443

Pastikan source code sudah benar-benar berada di `/var/www/iot`. `DocumentRoot` dan blok `<Directory>` di bawah sudah menggunakan lokasi tersebut.

Tambahkan port:

```bash
sudo nano /etc/apache2/ports.conf
```

```apache
# Nginx memakai port 80/443 pada VPS ini.
#Listen 80
#Listen 443

Listen 8443
```

Pastikan tidak ada `Listen 80` atau `Listen 443` aktif di file ini. Cek semua konfigurasi:

```bash
sudo grep -RIn --include='*.conf' -E '^[[:space:]]*Listen[[:space:]]+' /etc/apache2
```

Untuk deployment ini, hanya `Listen 8443` yang boleh aktif.

Buat VirtualHost:

```bash
sudo nano /etc/apache2/sites-available/classic-enzyme-8443.conf
```

```apache
<VirtualHost *:8443>
    ServerName 123.123.123.123
    DocumentRoot /var/www/iot

    <Directory /var/www/iot>
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile /etc/ssl/classic-enzyme/classic-enzyme-server.crt
    SSLCertificateKeyFile /etc/ssl/classic-enzyme/classic-enzyme-server.key

    SetEnv DB_DRIVER pgsql
    SetEnv DB_HOST 127.0.0.1
    SetEnv DB_PORT 5432
    SetEnv DB_NAME classic_enzyme_iot
    SetEnv DB_USER classic_enzyme
    SetEnv DB_PASS PASSWORD_DATABASE_KUAT

    ErrorLog ${APACHE_LOG_DIR}/classic-enzyme-error.log
    CustomLog ${APACHE_LOG_DIR}/classic-enzyme-access.log combined
</VirtualHost>
```

Ganti `ServerName 123.123.123.123` dan `SetEnv DB_PASS PASSWORD_DATABASE_KUAT` dengan nilai nyata. Placeholder yang tertinggal menyebabkan koneksi database gagal.

## 5. Buat sertifikat HTTPS untuk IP publik VPS

Gunakan **IPv4 publik VPS** dari panel provider VPS (misalnya AWS EC2 **Public IPv4 address**), yaitu IP yang dipakai untuk membuka aplikasi dari internet dan yang nanti ditulis di `SERVER_URL` pada ESP32.

Contoh di bawah memakai `203.0.113.10`. Ganti semua `203.0.113.10` dengan IP publik VPS Anda sendiri.

Jangan gunakan:

- `127.0.0.1` (loopback VPS)
- `172.31.x.x`, `10.x.x.x`, atau `192.168.x.x` (IP private jaringan VPS)
- IP ESP32 atau IP laptop

Sertifikat harus mencantumkan IP publik VPS pada `Subject Alternative Name`.

```bash
sudo mkdir -p /etc/ssl/classic-enzyme
cd /etc/ssl/classic-enzyme
sudo openssl genrsa -out classic-enzyme-ca.key 4096
sudo openssl req -x509 -new -nodes -key classic-enzyme-ca.key -sha256 -days 3650 -out classic-enzyme-ca.crt -subj "/C=ID/O=Classic Enzyme/CN=Classic Enzyme Root CA"
```

Buat konfigurasi:

```bash
sudo nano server-san.cnf
```

Isi file harus dimulai tepat dengan `[req]`. Jangan ikut menyalin baris pembuka/penutup Markdown seperti ```` ```ini ```` atau ```` ``` ````.

```ini
[req]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no

[req_distinguished_name]
C = ID
O = Classic Enzyme
CN = 203.0.113.10

[v3_req]
subjectAltName = IP:203.0.113.10
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
```

Pastikan `CN` dan `subjectAltName` berisi **IP publik VPS yang sama**, lalu jalankan:

Buat private key server terlebih dahulu:

```bash
sudo openssl genrsa -out classic-enzyme-server.key 2048
```

Periksa konfigurasi sebelum membuat CSR:

```bash
sudo openssl req -new -key classic-enzyme-server.key -out /tmp/classic-enzyme-server.csr -config server-san.cnf
```

Jika berhasil, lanjutkan pembuatan sertifikat berikut. Jika muncul `missing equal sign`, buka kembali `server-san.cnf` dan hapus baris Markdown atau teks tambahan apa pun di luar isi konfigurasi.

```bash
sudo openssl req -new -key classic-enzyme-server.key -out classic-enzyme-server.csr -config server-san.cnf
sudo openssl x509 -req -in classic-enzyme-server.csr -CA classic-enzyme-ca.crt -CAkey classic-enzyme-ca.key -CAcreateserial -out classic-enzyme-server.crt -days 825 -sha256 -extensions v3_req -extfile server-san.cnf
sudo chmod 600 /etc/ssl/classic-enzyme/*.key
```

Aktifkan Apache dan firewall:

```bash
sudo a2ensite classic-enzyme-8443.conf
sudo apachectl configtest
sudo systemctl reset-failed apache2
sudo systemctl enable --now apache2
sudo systemctl reload apache2
sudo ufw allow OpenSSH
sudo ufw allow 8443/tcp
sudo ufw enable
```

Jika muncul `DocumentRoot does not exist`, cari lokasi source code terlebih dahulu:

```bash
sudo find /var/www -maxdepth 4 -type f -name index.php -print
```

Kemudian ubah `DocumentRoot` dan `<Directory>` agar menunjuk ke folder yang berisi `index.php`.

Jika Apache gagal dengan `Address already in use` pada port `80`, cari proses yang memakainya:

```bash
sudo ss -ltnp | grep -E ':80|:8443'
```

Jika port `80` dipakai Nginx atau web server lain, Apache untuk deployment ini cukup mendengarkan `8443`. Edit `/etc/apache2/ports.conf`, hapus atau komentari `Listen 80`, dan pastikan ada:

```apache
Listen 8443
```

Jika port `80` dipakai Apache lain, jangan langsung mematikan proses sebelum memeriksa service yang aktif; gunakan `sudo systemctl status apache2` dan `sudo systemctl status nginx` terlebih dahulu.

Verifikasi hasilnya:

```bash
sudo ss -ltnp | grep -E ':80|:8443'
sudo apachectl -M | grep php
```

Targetnya adalah Nginx pada `:80`, Apache pada `:8443`, dan `php_module (shared)`. Jika respons HTTP menampilkan isi source `.php`, pasang `libapache2-mod-php` dan restart Apache.

Buka TCP `8443` juga pada Security Group/cloud firewall provider VPS.

## 6. Inisialisasi dan cek database

Buka `https://IP_VPS:8443/` sekali. Aplikasi membuat schema PostgreSQL otomatis ketika koneksi PHP ke PostgreSQL berhasil.

Pastikan VirtualHost yang aktif memakai `DB_DRIVER=pgsql` dan kredensial yang benar. Jika aplikasi gagal tersambung, cek log:

```bash
sudo tail -n 100 /var/log/apache2/classic-enzyme-error.log
sudo apachectl -S
```

Jika log menunjukkan masalah permission PostgreSQL, jalankan sebagai `postgres`:

```bash
sudo -u postgres psql
```

```sql
ALTER DATABASE classic_enzyme_iot OWNER TO classic_enzyme;
\c classic_enzyme_iot
ALTER SCHEMA public OWNER TO classic_enzyme;
GRANT ALL ON SCHEMA public TO classic_enzyme;
\q
```

Kemudian buka ulang halaman aplikasi, lalu cek tabel:

```bash
sudo -u postgres psql -d classic_enzyme_iot
```

```sql
\dt
```

Tekan Enter dan jalankan query berikut sebagai perintah terpisah:

```sql
SELECT * FROM devices;
\q
```

Jika tabel belum muncul, uji koneksi dan inisialisasi schema langsung sebagai user Apache:

```bash
sudo -u www-data env \
DB_DRIVER=pgsql \
DB_HOST=127.0.0.1 \
DB_PORT=5432 \
DB_NAME=classic_enzyme_iot \
DB_USER=classic_enzyme \
DB_PASS='PASSWORD_DATABASE_ASLI' \
php -r 'require "/var/www/iot/config/database.php"; $db=getDB(); echo $db->getAttribute(PDO::ATTR_DRIVER_NAME), PHP_EOL;'
```

Output yang benar adalah `pgsql`. Fungsi `getDB()` juga membuat schema PostgreSQL otomatis. Jika output `sqlite`, password atau permission PostgreSQL masih salah.

## 7. Buat API key device

```bash
openssl rand -hex 32
sudo -u postgres psql -d classic_enzyme_iot
```

```sql
UPDATE devices
SET api_key = 'API_KEY_BARU', api_key_hash = NULL
WHERE device_id = 'esp32-ce-001';
\q
```

Simpan hasil `openssl rand -hex 32` dan gunakan nilai yang sama di database, `secrets.h`, dan header `X-API-Key`. Request pertama akan mengubah API key plaintext menjadi hash secara otomatis.

## 8. Tes endpoint

```bash
NOW=$(date +%s)
curl -k -X POST 'https://IP_VPS:8443/api/telemetry.php' \
  -H 'Content-Type: application/json' \
  -H 'X-API-Key: API_KEY_BARU' \
  -d "{\"device_id\":\"esp32-ce-001\",\"temperature\":35.4,\"rssi\":-60,\"firmware\":\"2.0.0\",\"protocol_version\":2,\"ts\":${NOW},\"boot_id\":\"0123456789abcdef0123456789abcdef\",\"sequence\":1}"
```

Salin perintah sebagai teks biasa; jangan ikut menyalin ```` ``` ```` Markdown, `\_`, `&#x20;`, atau format link `[https://...]()`.

`-k` hanya untuk tes karena CA privat belum dipercaya komputer. Respons harus memiliki `"status":"ok"`. Jika mengulang request dengan `boot_id` yang sama, naikkan `sequence` (misalnya dari `1` ke `2`); kombinasi yang sama akan menghasilkan `409` karena dianggap replay.

## 9. Konfigurasi firmware

Di komputer lokal:

```bash
cp IOT/firmware/esp32_classic_enzyme/secrets.example.h IOT/firmware/esp32_classic_enzyme/secrets.h
```

Isi `secrets.h`:

```cpp
constexpr char WIFI_SSID[] = "NAMA_WIFI";
constexpr char WIFI_PASSWORD[] = "PASSWORD_WIFI";
constexpr char SERVER_URL[] = "https://IP_VPS:8443/api/telemetry.php";
constexpr char DEVICE_ID[] = "esp32-ce-001";
constexpr char API_KEY[] = "API_KEY_BARU";
```

Ambil CA dari VPS:

```bash
sudo cat /etc/ssl/classic-enzyme/classic-enzyme-ca.crt
```

Salin seluruh isi `classic-enzyme-ca.crt` ke `TLS_ROOT_CA` pada `secrets.h`. Jangan gunakan `classic-enzyme-server.crt`.

## 10. Flash ESP32

1. Buka `esp32_classic_enzyme.ino` di Arduino IDE.
2. Pastikan `secrets.h` satu folder dengan file `.ino`.
3. Pilih **ESP32 Dev Module**.
4. Pilih port USB ESP32.
5. Klik **Upload**.
6. Buka Serial Monitor pada `115200 baud`.

Log yang diharapkan:

```text
[WiFi] Berhasil Terhubung!
[NTP] Waktu tersinkronkan.
[HTTP] Respon Server Code: 200
[SUCCESS] Data telemetri berhasil tercatat!
```

Dashboard:

```text
https://IP_VPS:8443/
https://IP_VPS:8443/admin.php
```

## Troubleshooting

```bash
sudo tail -f /var/log/apache2/classic-enzyme-error.log
sudo tail -f /var/log/apache2/classic-enzyme-access.log
sudo systemctl status apache2
sudo systemctl status postgresql
```

- `403`: periksa permission dan `.htaccess`.
- `500`: periksa log Apache dan `SetEnv` database.
- `SQLSTATE connection refused`: PostgreSQL belum aktif atau kredensial salah.
- `Address already in use ... :80`: Nginx memakai port 80. Komentari `Listen 80` dan `Listen 443` di `/etc/apache2/ports.conf`; biarkan Apache hanya `Listen 8443`.
- `apache2.service ... cannot reload`: Apache belum aktif. Jalankan `sudo apachectl configtest`, perbaiki error, lalu `sudo systemctl enable --now apache2`.
- `DocumentRoot does not exist`: pastikan source code ada di `/var/www/iot` dan `DocumentRoot` cocok.
- Respons berisi source code PHP: `libapache2-mod-php` belum terpasang/aktif. Pasang paket tersebut, lalu restart Apache.
- `Did not find any relations`: schema belum diinisialisasi. Pastikan Apache/PHP bisa konek PostgreSQL, lalu jalankan uji `getDB()` pada langkah 6.
- `relation "devices" does not exist`: normal jika schema belum dibuat; jangan menjalankan `UPDATE devices` sebelum `\dt` menampilkan tabel.
- `missing equal sign` pada OpenSSL: file `server-san.cnf` berisi teks Markdown. Isinya harus dimulai `[req]`, tanpa baris fence ```` ```ini ````.
- `401`: API key pada `secrets.h` tidak cocok dengan database.
- `405`: endpoint diakses dengan GET; gunakan POST untuk telemetry.
- Source command gagal karena `classic_enzyme_iot: command not found`: nama database hanya argumen `psql`, bukan command Linux.
- Perintah `openssl`, `curl`, atau `tail` error di `psql`: keluar dulu dengan `\q`, lalu jalankan perintah tersebut di shell Linux.
- TLS gagal di ESP32: CA salah, sertifikat tidak memiliki IP SAN, NTP gagal, atau port `8443` belum dibuka pada firewall VPS/cloud.
- `409`: kombinasi `boot_id` dan `sequence` sudah pernah diterima.
- Peringatan `server certificate does NOT include an ID ... 123.123.123.123:443`: ada VirtualHost SSL lama memakai IP contoh. Tidak memblokir port 8443, tetapi ganti `ServerName` placeholder atau nonaktifkan site SSL lama jika memang tidak digunakan.

## Data lama dari MySQL

Jangan hapus MySQL sebelum PostgreSQL stabil. Script `scripts/migrate_to_pgsql.php` hanya mendukung SQLite → PostgreSQL. Migrasi MySQL → PostgreSQL memerlukan `pgloader` atau proses konversi khusus.
