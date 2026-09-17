<?php
/**
 * Classic Enzyme IoT - Database Connection & Configuration
 * 
 * Mendukung MySQL (standar cPanel / Production) dengan auto-fallback SQLite
 * untuk kemudahan pengujian lokal tanpa konfigurasi tambahan.
 */

// Konfigurasi Database MySQL (Sesuaikan dengan kredensial cPanel / Server Anda)
define('DB_DRIVER', 'mysql');         // 'mysql' atau 'sqlite'
define('DB_HOST',   '127.0.0.1');     // Host database MySQL
define('DB_PORT',   '3306');          // Port default MySQL
define('DB_NAME',   'classic_enzyme_iot'); // Nama database
define('DB_USER',   'root');          // Username database
define('DB_PASS',   '');              // Password database
define('DB_CHARSET','utf8mb4');

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

    // Coba koneksi MySQL terlebih dahulu jika diset mysql
    if (DB_DRIVER === 'mysql') {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            return $pdo;
        } catch (PDOException $e) {
            // Jika MySQL gagal dihubungi (misal saat testing lokal di Mac tanpa MySQL aktif),
            // fallback secara mulus ke SQLite agar dashboard dan API tetap bisa dicoba langsung!
            error_log("MySQL connection failed: " . $e->getMessage() . ". Falling back to SQLite for local development.");
        }
    }

    // Fallback atau Penggunaan SQLite
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
    VALUES ('esp32-ce-001', 'Bioreaktor Classic Enzyme 01', 'Ruang Fermentasi A', 'ce-secret-key-001', 'offline');

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
 * Mengambil nilai konfigurasi dari tabel settings (MySQL & SQLite)
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function getSetting($key, $default = null) {
    try {
        $db = getDB();
        // Pastikan tabel settings ada
        $isMysql = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
        if ($isMysql) {
            $db->exec("CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(50) NOT NULL PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
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
 * Menyimpan / memperbarui nilai konfigurasi ke tabel settings (MySQL & SQLite)
 * @param string $key
 * @param mixed $value
 * @return bool
 */
function setSetting($key, $value) {
    try {
        $db = getDB();
        $isMysql = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
        if ($isMysql) {
            $stmt = $db->prepare("
                INSERT INTO settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
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

