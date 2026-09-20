<?php
/**
 * Classic Enzyme IoT - Telemetry History Endpoint
 * Method: GET
 * Params: 
 *   - device_id (string, default: esp32-ce-001)
 *   - range (string: 1h, 6h, 24h, 7d, all; default: 1h)
 *   - limit (int: default 60, max 1000)
 *   - order (string: asc, desc; default: asc)
 * 
 * Mengembalikan array historis untuk konsumsi Chart.js dan tabel riwayat.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$deviceId = trim($_GET['device_id'] ?? 'esp32-ce-001');
$range    = trim($_GET['range'] ?? '1h');
$limit    = min(1000, max(10, (int)($_GET['limit'] ?? 60)));
$order    = strtolower(trim($_GET['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

$db = getDB();

// Tentukan filter interval waktu
$timeCondition = '';
$params = [$deviceId];

$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

switch ($range) {
    case '1h':
        if ($driver === 'mysql')      $timeCondition = "AND received_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        elseif ($driver === 'pgsql')  $timeCondition = "AND received_at >= NOW() - INTERVAL '1 hour'";
        else                          $timeCondition = "AND received_at >= datetime('now', '-1 hour', 'localtime')";
        break;
    case '6h':
        if ($driver === 'mysql')      $timeCondition = "AND received_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)";
        elseif ($driver === 'pgsql')  $timeCondition = "AND received_at >= NOW() - INTERVAL '6 hours'";
        else                          $timeCondition = "AND received_at >= datetime('now', '-6 hours', 'localtime')";
        break;
    case '24h':
        if ($driver === 'mysql')      $timeCondition = "AND received_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        elseif ($driver === 'pgsql')  $timeCondition = "AND received_at >= NOW() - INTERVAL '24 hours'";
        else                          $timeCondition = "AND received_at >= datetime('now', '-24 hours', 'localtime')";
        break;
    case '7d':
        if ($driver === 'mysql')      $timeCondition = "AND received_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        elseif ($driver === 'pgsql')  $timeCondition = "AND received_at >= NOW() - INTERVAL '7 days'";
        else                          $timeCondition = "AND received_at >= datetime('now', '-7 days', 'localtime')";
        break;
    case 'all':
    default:
        $timeCondition = '';
        break;
}

try {
    // Ambil N data PALING BARU terlebih dahulu (DESC), lalu urutkan ASC untuk tampilan grafik kronologis
    $sql = "
        SELECT * FROM (
            SELECT id, device_id, temperature, ph, alcohol, raw_temp, raw_adc, 
                   rssi, firmware_ver, device_ts, ip_address, received_at, is_valid, validation_flags
            FROM telemetry
            WHERE device_id = ? {$timeCondition}
            ORDER BY received_at DESC, id DESC
            LIMIT {$limit}
        ) AS latest_sub
        ORDER BY received_at {$order}, id {$order}
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Format list data
    $records = [];
    $temps = [];
    $phs = [];
    $alcohols = [];

    foreach ($rows as $r) {
        $isValid = (bool)$r['is_valid'];
        $temp = $isValid && $r['temperature'] !== null ? (float)$r['temperature'] : null;
        $ph   = $isValid && $r['ph'] !== null ? (float)$r['ph'] : null;
        $alc  = $isValid && $r['alcohol'] !== null ? (float)$r['alcohol'] : null;

        if ($temp !== null) $temps[] = $temp;
        if ($ph !== null) $phs[] = $ph;
        if ($alc !== null) $alcohols[] = $alc;

        $records[] = [
            'id' => (int)$r['id'],
            'time' => date('H:i:s', strtotime($r['received_at'])),
            'datetime' => $r['received_at'],
            'epoch' => strtotime($r['received_at']),
            'relative_time' => formatRelativeTime($r['received_at']),
            'is_valid' => $isValid,
            'validation_flags' => $r['validation_flags'] ? json_decode($r['validation_flags'], true) : [],
            'temperature' => $temp,
            'ph' => $ph,
            'alcohol' => $alc,
            'rssi' => $r['rssi'] !== null ? (int)$r['rssi'] : null,
            'raw_temp' => $r['raw_temp'] !== null ? (int)$r['raw_temp'] : null,
            'raw_adc' => $r['raw_adc'] !== null ? (int)$r['raw_adc'] : null,
        ];
    }

    // Statistik Ringkas
    $stats = [
        'count' => count($records),
        'temperature' => [
            'min' => !empty($temps) ? min($temps) : null,
            'max' => !empty($temps) ? max($temps) : null,
            'avg' => !empty($temps) ? round(array_sum($temps) / count($temps), 2) : null,
        ],
        'ph' => [
            'min' => !empty($phs) ? min($phs) : null,
            'max' => !empty($phs) ? max($phs) : null,
            'avg' => !empty($phs) ? round(array_sum($phs) / count($phs), 2) : null,
        ],
        'alcohol' => [
            'min' => !empty($alcohols) ? min($alcohols) : null,
            'max' => !empty($alcohols) ? max($alcohols) : null,
            'avg' => !empty($alcohols) ? round(array_sum($alcohols) / count($alcohols), 1) : null,
        ]
    ];

    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'device_id' => $deviceId,
        'range' => $range,
        'stats' => $stats,
        'data' => $records
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
