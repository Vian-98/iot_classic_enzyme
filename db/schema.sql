-- ==============================================================================
-- Classic Enzyme IoT - Database Schema (MySQL)
-- Versi: 1.0.0
-- Karakteristik: Zero-dependency, InnoDB, UTF-8 MB4
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. TABEL: devices
-- Menyimpan registrasi alat ESP32, API key keamanan, dan status koneksi terakhir
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `devices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id` VARCHAR(50) NOT NULL,
  `device_name` VARCHAR(100) NOT NULL,
  `location` VARCHAR(100) DEFAULT 'Lab Fermentasi Utama',
  `api_key` VARCHAR(64) NOT NULL,
  `api_key_hash` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Hash API key untuk autentikasi ingest v2',
  `status` ENUM('online', 'offline') NOT NULL DEFAULT 'offline',
  `last_seen` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_device_id` (`device_id`),
  INDEX `idx_status_seen` (`status`, `last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. TABEL: telemetry
-- Riwayat lengkap pembacaan sensor (Suhu MAX6675, pH PH-110, Alkohol MQ-3)
-- Dirancang mendukung ketiga sensor sejak awal meski dipasang bertahap
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `telemetry` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id` VARCHAR(50) NOT NULL,
  `temperature` DECIMAL(6, 2) NULL DEFAULT NULL COMMENT 'Suhu derajat Celcius dari MAX6675',
  `ph` DECIMAL(5, 3) NULL DEFAULT NULL COMMENT 'Nilai pH dari PH-110 (RS485/Analog)',
  `alcohol` FLOAT NULL DEFAULT NULL COMMENT 'Nilai alkohol dari MQ-3 (raw ADC atau ppm)',
  `raw_temp` INT NULL DEFAULT NULL COMMENT 'Register 16-bit mentah MAX6675 untuk diagnostic',
  `raw_adc` INT NULL DEFAULT NULL COMMENT 'Nilai ADC mentah MQ-3 (0-4095)',
  `rssi` SMALLINT NULL DEFAULT NULL COMMENT 'Kekuatan sinyal WiFi dalam dBm',
  `firmware_ver` VARCHAR(20) DEFAULT '1.0.0',
  `device_ts` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Unix timestamp internal ESP32',
  `ip_address` VARCHAR(45) NULL DEFAULT NULL COMMENT 'IP Address pengirim (IPv4/IPv6)',
  `boot_id` VARCHAR(64) NULL DEFAULT NULL COMMENT 'ID acak setiap boot ESP32 untuk anti-replay',
  `request_sequence` BIGINT NULL DEFAULT NULL COMMENT 'Nomor request berurutan dalam satu boot',
  `is_valid` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 bila pembacaan gagal validasi rentang fisik',
  `validation_flags` TEXT NULL DEFAULT NULL COMMENT 'JSON alasan data invalid',
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu server menerima data',
  PRIMARY KEY (`id`),
  INDEX `idx_device_time` (`device_id`, `received_at`),
  INDEX `idx_received_at` (`received_at`),
  UNIQUE KEY `uq_telemetry_replay` (`device_id`, `boot_id`, `request_sequence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. TABEL: alarms
-- Pencatatan anomali proses fermentasi (misal suhu terlalu tinggi / pH abnormal)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alarms` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id` VARCHAR(50) NOT NULL,
  `alarm_type` VARCHAR(50) NOT NULL COMMENT 'e.g. temp_high, temp_low, ph_abnormal, offline',
  `severity` ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'warning',
  `threshold_val` DECIMAL(10, 3) NULL DEFAULT NULL,
  `actual_val` DECIMAL(10, 3) NULL DEFAULT NULL,
  `message` TEXT NOT NULL,
  `triggered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `acknowledged` TINYINT(1) NOT NULL DEFAULT 0,
  `ack_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_device_alarm` (`device_id`, `triggered_at`),
  INDEX `idx_alarm_ack` (`acknowledged`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. TABEL: admins
-- Kredensial login panel manajemen administrator
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. TABEL: thresholds
-- Batas toleransi parameter sensor per device (bisa diatur via panel admin)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `thresholds` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id` VARCHAR(50) NOT NULL,
  `param` VARCHAR(20) NOT NULL COMMENT 'temp, ph, alcohol',
  `val_min` DECIMAL(8, 2) NULL DEFAULT NULL,
  `val_max` DECIMAL(8, 2) NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_device_param` (`device_id`, `param`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 6. TABEL: settings
-- Konfigurasi sistem dinamis (key-value)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` VARCHAR(50) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 7. TABEL KEAMANAN: pembatasan ingest dan audit tanpa menyimpan secret mentah
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ingest_rate_limits` (
  `scope` VARCHAR(191) NOT NULL,
  `window_start` DATETIME NOT NULL,
  `request_count` INT NOT NULL DEFAULT 0,
  `blocked_until` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`scope`, `window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `security_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` VARCHAR(64) NOT NULL,
  `device_id` VARCHAR(64) NULL DEFAULT NULL,
  `source_ip` VARCHAR(64) NULL DEFAULT NULL,
  `detail` TEXT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_security_events_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- SEED DATA: Device Perdana, Admin Default, Thresholds, & Settings
-- ------------------------------------------------------------------------------
INSERT INTO `devices` (`device_id`, `device_name`, `location`, `api_key`, `status`)
VALUES 
  ('esp32-ce-001', 'CE Monitoring 1', 'Ruang Fermentasi A', 'GANTI_API_KEY_SEBELUM_PRODUKSI', 'offline')
ON DUPLICATE KEY UPDATE 
  `device_name` = VALUES(`device_name`),
  `location` = VALUES(`location`);

-- Default admin account: username: admin / password: password
INSERT INTO `admins` (`username`, `password`)
VALUES 
  ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')
ON DUPLICATE KEY UPDATE `username` = `username`;

-- Default thresholds (Suhu: 20-40°C, pH: 3.0-4.5, Alkohol batas aman: 800 ADC)
INSERT INTO `thresholds` (`device_id`, `param`, `val_min`, `val_max`) VALUES
  ('esp32-ce-001', 'temp', 20.0, 40.0),
  ('esp32-ce-001', 'ph', 3.0, 4.5),
  ('esp32-ce-001', 'alcohol', NULL, 800.0)
ON DUPLICATE KEY UPDATE `device_id` = `device_id`;

-- Default system settings (offline timeout: 300 detik = 5 menit)
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('offline_timeout_seconds', '300')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

SET FOREIGN_KEY_CHECKS = 1;
