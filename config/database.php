<?php
/**
 * Classic Enzyme IoT - Database Connection & Configuration
 * 
 * Mendukung MySQL (standar cPanel / Production) dengan auto-fallback SQLite
 * untuk kemudahan pengujian lokal tanpa konfigurasi tambahan.
 */

// Konfigurasi Database Multi-Driver: PostgreSQL (prioritas lokal) / MySQL (cPanel VPS) / SQLite (portable fallback)
define('DB_DRIVER',   getenv('DB_DRIVER') ?: 'pgsql');
define('DB_HOST',     getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT',     getenv('DB_PORT') ?: (DB_DRIVER === 'pgsql' ? '5432' : '3306'));
define('DB_NAME',     getenv('DB_NAME') ?: 'classic_enzyme_iot');
define('DB_USER',     getenv('DB_USER') ?: (DB_DRIVER === 'pgsql' ? (getenv('USER') ?: 'favian') : 'root'));
define('DB_PASS',     getenv('DB_PASS') ?: '');
define('DB_CHARSET',  'utf8mb4');

// Lokasi file SQLite untuk fallback lokal otomatis
define('SQLITE_FILE', __DIR__ . '/../db/iot.sqlite');

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

    // 1. Coba koneksi PostgreSQL jika DB_DRIVER === 'pgsql'
    if (DB_DRIVER === 'pgsql') {
        try {
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_NAME);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            initPgsqlSchema($pdo);
            ensureSecuritySchema($pdo);
            return $pdo;
        } catch (PDOException $e) {
            error_log("PostgreSQL connection failed: " . $e->getMessage() . ". Falling back to MySQL/SQLite.");
        }
    }

    // 2. Coba koneksi MySQL jika diset mysql atau fallback dari pgsql
    if (DB_DRIVER === 'mysql' || DB_DRIVER === 'pgsql') {
        try {
            $mysqlPort = (DB_PORT === '5432') ? '3306' : DB_PORT;
            $mysqlUser = (DB_USER === 'favian' || DB_USER === getenv('USER')) ? 'root' : DB_USER;
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST,
                $mysqlPort,
                DB_NAME,
                DB_CHARSET
            );
            $pdo = new PDO($dsn, $mysqlUser, DB_PASS, $options);
            ensureSecuritySchema($pdo);
            return $pdo;
        } catch (PDOException $e) {
            error_log("MySQL connection failed: " . $e->getMessage() . ". Falling back to SQLite for local development.");
        }
    }

    // 3. Fallback atau Penggunaan SQLite
    try {
        $sqlitePath = SQLITE_FILE;
        $dbDir = dirname($sqlitePath);
        if (!is_dir($dbDir)) {
            @mkdir($dbDir, 0777, true);
        }

        $isNewDb = !file_exists($sqlitePath);
        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA journal_mode = WAL;');

        // Inisialisasi schema SQLite jika file baru dibuat
        if ($isNewDb) {
            initSqliteSchema($pdo);
        }
        ensureSecuritySchema($pdo);

        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Database connection failed: ' . $e->getMessage()
        ]);
        exit;
    }
}

/**
 * Migrasi keamanan aditif yang aman dijalankan berulang kali pada semua driver.
 * Tidak menghapus kolom/key lama agar firmware v1 dapat dimigrasikan bertahap.
 */
function ensureSecuritySchema(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) return;
    $initialized = true;

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $columnSpecs = $driver === 'pgsql' ? [
        'devices' => ['api_key_hash' => 'VARCHAR(255) NULL'],
        'telemetry' => [
            'boot_id' => 'VARCHAR(64) NULL',
            'request_sequence' => 'BIGINT NULL',
            'is_valid' => 'BOOLEAN NOT NULL DEFAULT TRUE',
            'validation_flags' => 'TEXT NULL',
        ],
    ] : ($driver === 'mysql' ? [
        'devices' => ['api_key_hash' => 'VARCHAR(255) NULL'],
        'telemetry' => [
            'boot_id' => 'VARCHAR(64) NULL',
            'request_sequence' => 'BIGINT NULL',
            'is_valid' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'validation_flags' => 'TEXT NULL',
        ],
    ] : [
        'devices' => ['api_key_hash' => 'TEXT NULL'],
        'telemetry' => [
            'boot_id' => 'TEXT NULL',
            'request_sequence' => 'INTEGER NULL',
            'is_valid' => 'INTEGER NOT NULL DEFAULT 1',
            'validation_flags' => 'TEXT NULL',
        ],
    ]);

    foreach ($columnSpecs as $table => $columns) {
        foreach ($columns as $column => $definition) {
            if (!securityColumnExists($pdo, $table, $column)) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        }
    }

    if ($driver === 'pgsql') {
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
    } elseif ($driver === 'mysql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ingest_rate_limits (
            scope VARCHAR(191) NOT NULL,
            window_start DATETIME NOT NULL,
            request_count INT NOT NULL DEFAULT 0,
            blocked_until DATETIME NULL,
            PRIMARY KEY (scope, window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS security_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(64) NOT NULL,
            device_id VARCHAR(64) NULL,
            source_ip VARCHAR(64) NULL,
            detail TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_security_events_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ingest_rate_limits (
            scope TEXT NOT NULL,
            window_start TEXT NOT NULL,
            request_count INTEGER NOT NULL DEFAULT 0,
            blocked_until TEXT NULL,
            PRIMARY KEY (scope, window_start)
        );
        CREATE TABLE IF NOT EXISTS security_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_type TEXT NOT NULL,
            device_id TEXT NULL,
            source_ip TEXT NULL,
            detail TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );");
    }

    try {
        if ($driver === 'mysql') {
            $pdo->exec('CREATE UNIQUE INDEX uq_telemetry_replay ON telemetry (device_id, boot_id, request_sequence)');
        } else {
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_telemetry_replay ON telemetry (device_id, boot_id, request_sequence)');
        }
    } catch (PDOException $e) {
        // Indeks sudah ada pada MySQL, atau data lama perlu dibersihkan oleh operator.
    }
}

function securityColumnExists(PDO $pdo, string $table, string $column): bool {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $rows = $pdo->query("PRAGMA table_info({$table})")->fetchAll();
        foreach ($rows as $row) if ($row['name'] === $column) return true;
        return false;
    }

    $schema = $driver === 'pgsql' ? 'public' : DB_NAME;
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
    $stmt->execute([$schema, $table, $column]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Inisialisasi otomatis schema PostgreSQL jika database baru dibuat untuk dev lokal
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

    CREATE INDEX IF NOT EXISTS idx_dev_time ON telemetry(device_id, received_at);
    CREATE INDEX IF NOT EXISTS idx_recv_time ON telemetry(received_at);

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
 * Inisialisasi otomatis schema SQLite jika database baru dibuat untuk dev lokal
 */
function initSqliteSchema(PDO $pdo) {
    $schema = "
    CREATE TABLE IF NOT EXISTS devices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id TEXT UNIQUE NOT NULL,
        device_name TEXT NOT NULL,
        location TEXT DEFAULT 'Lab Fermentasi Utama',
        api_key TEXT NOT NULL,
        status TEXT DEFAULT 'offline',
        last_seen DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS telemetry (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id TEXT NOT NULL,
        temperature REAL NULL,
        ph REAL NULL,
        alcohol REAL NULL,
        raw_temp INTEGER NULL,
        raw_adc INTEGER NULL,
        rssi INTEGER NULL,
        firmware_ver TEXT DEFAULT '1.0.0',
        device_ts INTEGER NULL,
        ip_address TEXT NULL,
        received_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE INDEX IF NOT EXISTS idx_dev_time ON telemetry(device_id, received_at);
    CREATE INDEX IF NOT EXISTS idx_recv_time ON telemetry(received_at);

    CREATE TABLE IF NOT EXISTS alarms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id TEXT NOT NULL,
        alarm_type TEXT NOT NULL,
        severity TEXT DEFAULT 'warning',
        threshold_val REAL NULL,
        actual_val REAL NULL,
        message TEXT NOT NULL,
        triggered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        acknowledged INTEGER DEFAULT 0,
        ack_at DATETIME NULL
    );

    CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS thresholds (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id TEXT NOT NULL,
        param TEXT NOT NULL,
        val_min REAL NULL,
        val_max REAL NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(device_id, param)
    );

    CREATE TABLE IF NOT EXISTS settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    INSERT OR IGNORE INTO devices (device_id, device_name, location, api_key, status)
    VALUES ('esp32-ce-001', 'CE Monitoring 1', 'Ruang Fermentasi A', 'GANTI_API_KEY_SEBELUM_PRODUKSI', 'offline');

    INSERT OR IGNORE INTO admins (username, password)
    VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

    INSERT OR IGNORE INTO thresholds (device_id, param, val_min, val_max) VALUES
        ('esp32-ce-001', 'temp', 20.0, 40.0),
        ('esp32-ce-001', 'ph', 3.0, 4.5),
        ('esp32-ce-001', 'alcohol', NULL, 800.0);

    INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES
        ('offline_timeout_seconds', '300');
    ";
    $pdo->exec($schema);
}

/**
 * Mengambil nilai konfigurasi dari tabel settings (PostgreSQL, MySQL, & SQLite)
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function getSetting($key, $default = null) {
    try {
        $db = getDB();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $db->exec("CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(50) NOT NULL PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } else if ($driver === 'pgsql') {
            $db->exec("CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(64) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );");
        }

        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Menyimpan / memperbarui nilai konfigurasi ke tabel settings (PostgreSQL, MySQL & SQLite)
 * @param string $key
 * @param mixed $value
 * @return bool
 */
function setSetting($key, $value) {
    try {
        $db = getDB();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $db->prepare("
                INSERT INTO settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");
        } else if ($driver === 'pgsql') {
            $stmt = $db->prepare("
                INSERT INTO settings (setting_key, setting_value)
                VALUES (?, ?)
                ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP
            ");
        } else {
            $stmt = $db->prepare("
                INSERT INTO settings (setting_key, setting_value)
                VALUES (?, ?)
                ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = datetime('now','localtime')
            ");
        }
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
