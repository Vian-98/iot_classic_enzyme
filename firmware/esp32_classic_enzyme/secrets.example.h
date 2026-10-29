#pragma once

// Salin file ini menjadi `secrets.h`, lalu isi nilainya sebelum firmware di-flash.
// `secrets.h` sengaja diabaikan Git agar konfigurasi perangkat tidak ikut ter-commit.

constexpr char WIFI_SSID[] = "NAMA_WIFI";
constexpr char WIFI_PASSWORD[] = "PASSWORD_WIFI";
// Gunakan IP publik VPS. Sertifikat server harus memuat IP ini pada SAN.
constexpr char SERVER_URL[] = "https://203.0.113.10/api/telemetry.php";
constexpr char DEVICE_ID[] = "esp32-ce-001";
constexpr char API_KEY[] = "GANTI_DENGAN_API_KEY_UNIK_DEVICE";

// Tempel CA PEM yang menandatangani sertifikat HTTPS VPS. Jika memakai CA
// privat, tempel sertifikat CA privatnya—bukan leaf certificate server.
constexpr char TLS_ROOT_CA[] = R"PEM(
-----BEGIN CERTIFICATE-----
TEMPel_CA_PENERBIT_SERTIFIKAT_SERVER_DI_SINI
-----END CERTIFICATE-----
)PEM";

// ==============================================================================
// KONFIGURASI ENDPOINT KEDUA (API Pihak Ketiga) - Opsional
// ==============================================================================
// Set 'true' jika ingin mengaktifkan pengiriman data ke server uji.
// Jika 'false', firmware berjalan seperti versi sebelumnya (hanya primary).
constexpr bool SECONDARY_ENDPOINT_ENABLED = false;

constexpr char SERVER_URL_2[]     = "https://SERVER_UJI_ANDA/iot/api/telemetry.php";
constexpr char API_KEY_2[]        = "API_KEY_UNTUK_SERVER_UJI";
// Samakan dengan primary: header "X-API-Key" tanpa prefix
constexpr char API_KEY_HEADER_2[] = "X-API-Key";
constexpr char API_KEY_PREFIX_2[] = "";

constexpr char TLS_ROOT_CA_2[] = R"PEM(
-----BEGIN CERTIFICATE-----
TEMPEL_CA_PENERBIT_SERTIFIKAT_API_PIHAK_KETIGA
-----END CERTIFICATE-----
)PEM";

