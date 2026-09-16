<?php
/**
 * Classic Enzyme IoT - Device Management Endpoint
 * Method: GET
 * 
 * Mengembalikan daftar seluruh bioreaktor/device terdaftar beserta status koneksinya.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$db = getDB();

try {
    $stmt = $db->query("
        SELECT d.id, d.device_id, d.device_name, d.location, d.status, d.last_seen, d.created_at,
               (SELECT temperature FROM telemetry WHERE device_id = d.device_id ORDER BY received_at DESC LIMIT 1) as last_temp,
               (SELECT ph FROM telemetry WHERE device_id = d.device_id ORDER BY received_at DESC LIMIT 1) as last_ph,
               (SELECT alcohol FROM telemetry WHERE device_id = d.device_id ORDER BY received_at DESC LIMIT 1) as last_alcohol
        FROM devices d
        ORDER BY d.id ASC
    ");
    $devices = $stmt->fetchAll();

    $output = [];
    $now = time();

    foreach ($devices as $dev) {
        $lastSeen = $dev['last_seen'];
        $secondsAgo = $lastSeen ? max(0, $now - strtotime($lastSeen)) : null;
        $isOnline = ($secondsAgo !== null && $secondsAgo <= 30);

        $output[] = [
            'id' => (int)$dev['id'],
            'device_id' => $dev['device_id'],
            'device_name' => $dev['device_name'],
            'location' => $dev['location'],
            'last_seen' => $lastSeen,
            'seconds_ago' => $secondsAgo,
            'relative_time' => formatRelativeTime($lastSeen),
            'is_online' => $isOnline,
            'last_temp' => $dev['last_temp'] !== null ? (float)$dev['last_temp'] : null,
            'last_ph' => $dev['last_ph'] !== null ? (float)$dev['last_ph'] : null,
            'last_alcohol' => $dev['last_alcohol'] !== null ? (float)$dev['last_alcohol'] : null,
        ];
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'count' => count($output),
        'devices' => $output
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
