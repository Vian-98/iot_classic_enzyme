<?php
/**
 * Classic Enzyme IoT - Latest Telemetry Endpoint
 * Method: GET
 * Params: ?device_id=esp32-ce-001
 * 
 * Mengembalikan data sensor terkini, waktu terakhir diterima ('last_seen'),
 * hitungan selisih detik ('seconds_ago'), dan status koneksi live (online/offline)
 * untuk digunakan dalam polling realtime dashboard Liquid Glass.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$deviceId = trim($_GET['device_id'] ?? '');

$db = getDB();

try {
    // 1. Ambil device info
    if (!empty($deviceId)) {
        $stmtDev = $db->prepare("SELECT id, device_id, device_name, location, status, last_seen, created_at FROM devices WHERE device_id = ?");
        $stmtDev->execute([$deviceId]);
    } else {
        $stmtDev = $db->query("SELECT id, device_id, device_name, location, status, last_seen, created_at FROM devices ORDER BY last_seen DESC LIMIT 1");
    }

    $device = $stmtDev->fetch();

    if (!$device) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Device tidak ditemukan di database'
        ]);
        exit;
    }

    $targetDevId = $device['device_id'];

    // 2. Ambil data telemetri terakhir
    $stmtTel = $db->prepare("
        SELECT id, device_id, temperature, ph, alcohol, raw_temp, raw_adc, 
               rssi, firmware_ver, device_ts, ip_address, received_at
        FROM telemetry
        WHERE device_id = ?
        ORDER BY received_at DESC, id DESC
        LIMIT 1
    ");
    $stmtTel->execute([$targetDevId]);
    $latestTelemetry = $stmtTel->fetch();

    // 3. Kalkulasi 'Kapan Terakhir Diterima' & Status Online/Offline
    $lastSeen = $device['last_seen'] ?: ($latestTelemetry['received_at'] ?? null);
    $secondsAgo = null;
    $isOnline = false;
    $relativeTime = 'Belum pernah ada data';

    if ($lastSeen) {
        $lastSeenTs = strtotime($lastSeen);
        $secondsAgo = max(0, time() - $lastSeenTs);
        $relativeTime = formatRelativeTime($lastSeen);
        
        // Timeout batas offline: jika lebih dari 30 detik tidak ada kiriman data
        $isOnline = ($secondsAgo <= 30);
    }

    // 4. Hitung alarm aktif/belum di-acknowledge
    $stmtAlarm = $db->prepare("SELECT COUNT(*) as active_alarms FROM alarms WHERE device_id = ? AND acknowledged = 0");
    $stmtAlarm->execute([$targetDevId]);
    $alarmCount = (int)($stmtAlarm->fetch()['active_alarms'] ?? 0);

    // 4b. Ambil rentang ideal / threshold yang dikonfigurasi admin
    $stmtThresh = $db->prepare("SELECT param, val_min, val_max FROM thresholds WHERE device_id = ?");
    $stmtThresh->execute([$targetDevId]);
    $thresholdsMap = [
        'temp'    => ['min' => 30.0, 'max' => 38.0],
        'ph'      => ['min' => 3.2, 'max' => 4.5],
        'alcohol' => ['min' => null, 'max' => null]
    ];
    while ($t = $stmtThresh->fetch()) {
        $thresholdsMap[$t['param']] = [
            'min' => $t['val_min'] !== null ? (float)$t['val_min'] : null,
            'max' => $t['val_max'] !== null ? (float)$t['val_max'] : null
        ];
    }

    // 5. Output respons JSON
    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'server_time' => date('Y-m-d H:i:s'),
        'device' => [
            'device_id' => $device['device_id'],
            'device_name' => $device['device_name'],
            'location' => $device['location'],
            'last_seen' => $lastSeen,
            'seconds_ago' => $secondsAgo,
            'relative_time' => $relativeTime,
            'is_online' => $isOnline,
            'active_alarms' => $alarmCount
        ],
        'thresholds' => $thresholdsMap,
        'telemetry' => $latestTelemetry ? [
            'id' => (int)$latestTelemetry['id'],
            'temperature' => $latestTelemetry['temperature'] !== null ? (float)$latestTelemetry['temperature'] : null,
            'ph' => $latestTelemetry['ph'] !== null ? (float)$latestTelemetry['ph'] : null,
            'alcohol' => $latestTelemetry['alcohol'] !== null ? (float)$latestTelemetry['alcohol'] : null,
            'raw_temp' => $latestTelemetry['raw_temp'] !== null ? (int)$latestTelemetry['raw_temp'] : null,
            'raw_adc' => $latestTelemetry['raw_adc'] !== null ? (int)$latestTelemetry['raw_adc'] : null,
            'rssi' => $latestTelemetry['rssi'] !== null ? (int)$latestTelemetry['rssi'] : null,
            'firmware_ver' => $latestTelemetry['firmware_ver'],
            'device_ts' => $latestTelemetry['device_ts'],
            'ip_address' => isAdminLoggedIn() ? $latestTelemetry['ip_address'] : null,
            'received_at' => $latestTelemetry['received_at']
        ] : null
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
