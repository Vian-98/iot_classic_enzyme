<?php
/**
 * Classic Enzyme IoT - Ingestion Endpoint
 * Method: POST
 * Format: JSON
 * 
 * Menerima kiriman telemetri dari ESP32, memverifikasi API Key, mencatat histori
 * ke tabel telemetry, memperbarui status koneksi dan 'last_seen' di tabel devices,
 * serta mengecek threshold alarm fermentasi.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Hanya menerima method POST']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

// Ambil input JSON mentah
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Format payload tidak valid. Harap kirimkan valid JSON object.'
    ]);
    exit;
}

// Ekstraksi parameter dengan fallback
$deviceId = trim($data['device_id'] ?? '');
$apiKey   = trim($data['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '');

if (empty($deviceId)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Field device_id wajib diisi']);
    exit;
}

$db = getDB();

// 1. Verifikasi Device & API Key
$stmtDevice = $db->prepare("SELECT id, device_id, api_key FROM devices WHERE device_id = ?");
$stmtDevice->execute([$deviceId]);
$device = $stmtDevice->fetch();

if (!$device) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Device ID belum terdaftar di sistem. Hubungi administrator.'
    ]);
    exit;
}

if (!empty($device['api_key']) && $device['api_key'] !== $apiKey) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error', 
        'message' => 'API Key tidak valid untuk device ini.'
    ]);
    exit;
}

// 2. Parsing pembacaan sensor
// Suhu (MAX6675)
$temperature = isset($data['temperature']) && is_numeric($data['temperature']) ? (float)$data['temperature'] : null;

// pH (PH-110)
$ph = isset($data['ph']) && is_numeric($data['ph']) ? (float)$data['ph'] : null;

// Alkohol (MQ-3)
$alcohol = isset($data['alcohol']) && is_numeric($data['alcohol']) ? (float)$data['alcohol'] : null;

// Raw registers / ADC untuk diagnostik lab
$rawTemp = isset($data['raw_temp']) && is_numeric($data['raw_temp']) ? (int)$data['raw_temp'] : null;
$rawAdc  = isset($data['raw_adc']) && is_numeric($data['raw_adc']) ? (int)$data['raw_adc'] : null;

// Sinyal WiFi & Info Firmware
$rssi        = isset($data['rssi']) && is_numeric($data['rssi']) ? (int)$data['rssi'] : null;
$firmwareVer = isset($data['firmware']) ? substr(trim($data['firmware']), 0, 20) : '1.0.0';
$deviceTs    = isset($data['ts']) && is_numeric($data['ts']) ? (int)$data['ts'] : null;
$ipAddress   = $_SERVER['REMOTE_ADDR'] ?? null;

$serverTime = date('Y-m-d H:i:s');

try {
    // 3. Simpan data ke tabel telemetry (Histori Lengkap)
    $stmtInsert = $db->prepare("
        INSERT INTO telemetry (
            device_id, temperature, ph, alcohol, raw_temp, raw_adc, 
            rssi, firmware_ver, device_ts, ip_address, received_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, 
            ?, ?, ?, ?, ?
        )
    ");
    $stmtInsert->execute([
        $deviceId, $temperature, $ph, $alcohol, $rawTemp, $rawAdc,
        $rssi, $firmwareVer, $deviceTs, $ipAddress, $serverTime
    ]);
    $insertedId = $db->lastInsertId();

    // 4. Perbarui status koneksi dan 'last_seen' di tabel devices
    $stmtUpdateDev = $db->prepare("
        UPDATE devices 
        SET status = 'online', last_seen = ? 
        WHERE device_id = ?
    ");
    $stmtUpdateDev->execute([$serverTime, $deviceId]);

    // 5. Cek Ambang Batas (Alarm Check) menggunakan threshold dari database
    $stmtThresh = $db->prepare("SELECT param, val_min, val_max FROM thresholds WHERE device_id = ?");
    $stmtThresh->execute([$deviceId]);
    $threshMap = [];
    while ($t = $stmtThresh->fetch()) {
        $threshMap[$t['param']] = $t;
    }

    $tempMin = isset($threshMap['temp']['val_min']) && $threshMap['temp']['val_min'] !== null ? (float)$threshMap['temp']['val_min'] : 20.0;
    $tempMax = isset($threshMap['temp']['val_max']) && $threshMap['temp']['val_max'] !== null ? (float)$threshMap['temp']['val_max'] : 40.0;
    $phMin   = isset($threshMap['ph']['val_min']) && $threshMap['ph']['val_min'] !== null ? (float)$threshMap['ph']['val_min'] : 3.0;
    $phMax   = isset($threshMap['ph']['val_max']) && $threshMap['ph']['val_max'] !== null ? (float)$threshMap['ph']['val_max'] : 5.0;
    $alcMax  = isset($threshMap['alcohol']['val_max']) && $threshMap['alcohol']['val_max'] !== null ? (float)$threshMap['alcohol']['val_max'] : null;

    $alarmsTriggered = [];

    if ($temperature !== null && $tempMax !== null && $temperature > $tempMax) {
        $alarmsTriggered[] = [
            'type' => 'temp_high',
            'severity' => 'critical',
            'threshold' => $tempMax,
            'actual' => $temperature,
            'msg' => "Suhu bioreaktor mencapai {$temperature}°C (melebihi ambang batas {$tempMax}°C)!"
        ];
    } elseif ($temperature !== null && $tempMin !== null && $temperature < $tempMin) {
        $alarmsTriggered[] = [
            'type' => 'temp_low',
            'severity' => 'warning',
            'threshold' => $tempMin,
            'actual' => $temperature,
            'msg' => "Suhu bioreaktor turun ke {$temperature}°C (di bawah batas ideal {$tempMin}°C)."
        ];
    }

    if ($ph !== null && $phMax !== null && $ph > $phMax) {
        $alarmsTriggered[] = [
            'type' => 'ph_high',
            'severity' => 'warning',
            'threshold' => $phMax,
            'actual' => $ph,
            'msg' => "Nilai pH {$ph} terlalu basa (melebihi ambang {$phMax})."
        ];
    } elseif ($ph !== null && $phMin !== null && $ph < $phMin) {
        $alarmsTriggered[] = [
            'type' => 'ph_low',
            'severity' => 'warning',
            'threshold' => $phMin,
            'actual' => $ph,
            'msg' => "Nilai pH {$ph} terlalu asam (di bawah ambang {$phMin})."
        ];
    }

    if ($alcohol !== null && $alcMax !== null && $alcohol > $alcMax) {
        $alarmsTriggered[] = [
            'type' => 'alcohol_high',
            'severity' => 'warning',
            'threshold' => $alcMax,
            'actual' => $alcohol,
            'msg' => "Konsentrasi alkohol mencapai ADC {$alcohol} (melebihi ambang {$alcMax})!"
        ];
    }

    // Catat alarm jika ada yang terpicu
    if (!empty($alarmsTriggered)) {
        $stmtAlarm = $db->prepare("
            INSERT INTO alarms (device_id, alarm_type, severity, threshold_val, actual_val, message, triggered_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($alarmsTriggered as $alm) {
            $stmtAlarm->execute([
                $deviceId, $alm['type'], $alm['severity'], $alm['threshold'], $alm['actual'], $alm['msg'], $serverTime
            ]);
        }
    }

    // 6. Kembalikan respons sukses ke ESP32
    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'message' => 'Telemetry received successfully',
        'telemetry_id' => (int)$insertedId,
        'server_time' => $serverTime,
        'alarms_count' => count($alarmsTriggered)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Gagal menyimpan data ke database: ' . $e->getMessage()
    ]);
}
