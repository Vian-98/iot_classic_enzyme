<?php
/**
 * Classic Enzyme IoT - Database Connection & Configuration
 * 
 * PostgreSQL-only runtime configuration.
 */

define('DB_HOST',     getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT',     getenv('DB_PORT') ?: '5432');
define('DB_NAME',     getenv('DB_NAME') ?: 'classic_enzyme_iot');
define('DB_USER',     getenv('DB_USER') ?: 'classic_enzyme');
define('DB_PASS',     getenv('DB_PASS') ?: '');
// Schema otomatis memudahkan setup lokal. Untuk produksi PostgreSQL, jalankan
// inisialisasi sekali lalu set DB_AUTO_INIT_SCHEMA=false agar request web tidak
// melakukan DDL (CREATE/ALTER/INDEX) berulang kali.
define('DB_AUTO_INIT_SCHEMA', filter_var(getenv('DB_AUTO_INIT_SCHEMA') ?: 'true', FILTER_VALIDATE_BOOLEAN));

// Zona Waktu (WIB / GMT+7)
date_default_timezone_set('Asia/Jakarta');

/**
 * Mendapatkan koneksi instance PDO
 * @return PDO
 */
function getDB() {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        if (DB_AUTO_INIT_SCHEMA) {
            initPgsqlSchema($pdo);
            ensureSecuritySchema($pdo);
        }
        return $pdo;
    } catch (PDOException $e) {
        error_log('PostgreSQL connection failed: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'PostgreSQL connection failed'
        ]);
        exit;
    }
}

/**
 * Migrasi keamanan aditif PostgreSQL yang aman dijalankan berulang kali.
 * Tidak menghapus kolom/key lama agar firmware v1 dapat dimigrasikan bertahap.
 */
function ensureSecuritySchema(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) return;
    $initialized = true;

    $columnSpecs = [
        'devices' => ['api_key_hash' => 'VARCHAR(255) NULL'],
        'telemetry' => [
            'boot_id' => 'VARCHAR(64) NULL',
            'request_sequence' => 'BIGINT NULL',
            'is_valid' => 'BOOLEAN NOT NULL DEFAULT TRUE',
            'validation_flags' => 'TEXT NULL',
        ],
    ];

    foreach ($columnSpecs as $table => $columns) {
        foreach ($columns as $column => $definition) {
            if (!securityColumnExists($pdo, $table, $column)) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS ingest_rate_limits (
            scope VARCHAR(191) NOT NULL,
            window_start TIMESTAMP NOT NULL,
            request_count INTEGER NOT NULL DEFAULT 0,
            blocked_until TIMESTAMP NULL,
            PRIMARY KEY (scope, window_start)
        );
        CREATE TABLE IF NOT EXISTS security_events (
            id BIGSERIAL PRIMARY KEY,
            event_type VARCHAR(64) NOT NULL,
            device_id VARCHAR(64) NULL,
            source_ip VARCHAR(64) NULL,
            detail TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rate_limits_scope_blocked ON ingest_rate_limits(scope, blocked_until);
                CREATE INDEX IF NOT EXISTS idx_security_events_created ON security_events(created_at DESC);
                CREATE UNIQUE INDEX IF NOT EXISTS uq_telemetry_replay ON telemetry(device_id, boot_id, request_sequence);");
}

function securityColumnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
    $stmt->execute(['public', $table, $column]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Bootstrap schema PostgreSQL saat DB_AUTO_INIT_SCHEMA=true.
 */
function initPgsqlSchema(PDO $pdo) {
    $schema = "
    CREATE TABLE IF NOT EXISTS devices (
        id SERIAL PRIMARY KEY,
        device_id VARCHAR(64) UNIQUE NOT NULL,
        device_name VARCHAR(128) NOT NULL,
        location VARCHAR(255) DEFAULT 'Lab Fermentasi Utama',
        api_key VARCHAR(128) NOT NULL,
        status VARCHAR(32) DEFAULT 'offline',
        last_seen TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS telemetry (
        id SERIAL PRIMARY KEY,
        device_id VARCHAR(64) NOT NULL,
        temperature NUMERIC(5,2) NULL,
        ph NUMERIC(4,2) NULL,
        alcohol NUMERIC(6,2) NULL,
        raw_temp NUMERIC(10,2) NULL,
        raw_adc INTEGER NULL,
        rssi INTEGER NULL,
        firmware_ver VARCHAR(32) DEFAULT '1.0.0',
        device_ts BIGINT NULL,
        ip_address VARCHAR(64) NULL,
        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE INDEX IF NOT EXISTS idx_telemetry_device_time ON telemetry(device_id, received_at DESC, id DESC);
    CREATE INDEX IF NOT EXISTS idx_telemetry_received_at ON telemetry(received_at DESC);

    CREATE TABLE IF NOT EXISTS alarms (
        id SERIAL PRIMARY KEY,
        device_id VARCHAR(64) NOT NULL,
        alarm_type VARCHAR(64) NOT NULL,
        severity VARCHAR(32) DEFAULT 'warning',
        threshold_val NUMERIC(6,2) NULL,
        actual_val NUMERIC(6,2) NULL,
        message TEXT NOT NULL,
        triggered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        acknowledged INTEGER DEFAULT 0,
        ack_at TIMESTAMP NULL
    );

    CREATE TABLE IF NOT EXISTS admins (
        id SERIAL PRIMARY KEY,
        username VARCHAR(64) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS thresholds (
        id SERIAL PRIMARY KEY,
        device_id VARCHAR(64) NOT NULL,
        param VARCHAR(32) NOT NULL,
        val_min NUMERIC(6,2) NULL,
        val_max NUMERIC(6,2) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(device_id, param)
    );

    CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(64) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE INDEX IF NOT EXISTS idx_alarms_device_ack_time ON alarms(device_id, acknowledged, triggered_at DESC);

    INSERT INTO devices (device_id, device_name, location, api_key, status)
    VALUES ('esp32-ce-001', 'CE Monitoring 1', 'Ruang Fermentasi A', 'GANTI_API_KEY_SEBELUM_PRODUKSI', 'online')
    ON CONFLICT (device_id) DO NOTHING;

    INSERT INTO admins (username, password)
    VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')
    ON CONFLICT (username) DO NOTHING;

    INSERT INTO thresholds (device_id, param, val_min, val_max) VALUES
        ('esp32-ce-001', 'temp', 20.0, 40.0),
        ('esp32-ce-001', 'ph', 3.0, 4.5),
        ('esp32-ce-001', 'alcohol', NULL, 800.0)
    ON CONFLICT (device_id, param) DO NOTHING;

    INSERT INTO settings (setting_key, setting_value) VALUES
        ('offline_timeout_seconds', '300')
    ON CONFLICT (setting_key) DO NOTHING;
    ";
    $pdo->exec($schema);
}


/**
 * Mengambil nilai konfigurasi dari tabel settings PostgreSQL.
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function getSetting($key, $default = null) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Menyimpan / memperbarui nilai konfigurasi ke tabel settings PostgreSQL.
 * @param string $key
 * @param mixed $value
 * @return bool
 */
function setSetting($key, $value) {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value)
            VALUES (?, ?)
            ON CONFLICT (setting_key) DO UPDATE
            SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP");
        return $stmt->execute([$key, (string)$value]);
    } catch (Exception $e) {
        error_log("setSetting error: " . $e->getMessage());
        return false;
    }
}

/**
 * Helper format waktu relatif (misal: '3 detik lalu', '2 menit lalu')
 */
function formatRelativeTime($datetime) {
    if (!$datetime) return 'Belum pernah';
    
    $timestamp = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
    if (!$timestamp) return 'Invalid date';

    $diff = time() - $timestamp;

    if ($diff < 0) return 'Baru saja';
    if ($diff < 5) return 'Baru saja';
    if ($diff < 60) return $diff . ' detik lalu';
    if ($diff < 3600) return floor($diff / 60) . ' menit lalu';
    if ($diff < 86400) return floor($diff / 3600) . ' jam lalu';
    if ($diff < 604800) return floor($diff / 86400) . ' hari lalu';

    return date('d M Y H:i', $timestamp);
}
