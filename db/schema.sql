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
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu server menerima data',
  PRIMARY KEY (`id`),
  INDEX `idx_device_time` (`device_id`, `received_at`),
  INDEX `idx_received_at` (`received_at`)
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
-- SEED DATA: Device Perdana
-- ------------------------------------------------------------------------------
INSERT INTO `devices` (`device_id`, `device_name`, `location`, `api_key`, `status`)
VALUES 
  ('esp32-ce-001', 'Bioreaktor Classic Enzyme 01', 'Ruang Fermentasi A', 'ce-secret-key-001', 'offline')
ON DUPLICATE KEY UPDATE 
  `device_name` = VALUES(`device_name`),
  `location` = VALUES(`location`);

SET FOREIGN_KEY_CHECKS = 1;
