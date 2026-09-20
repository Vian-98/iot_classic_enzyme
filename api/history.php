<?php
/**
 * Classic Enzyme IoT - Telemetry History Endpoint
 * Method: GET
 * Params: 
 *   - device_id (string, default: esp32-ce-001)
 *   - range (string: 1h, 6h, 24h, 7d, all; default: 1h)
 *   - limit (int: default 60, max 1000)
 *   - order (string: asc, desc; default: asc)
 *   - start_date/end_date (YYYY-MM-DD, optional)
 *   - status (all, valid, invalid, optional)
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
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');
$status = strtolower(trim($_GET['status'] ?? 'all'));

$db = getDB();

function respond(int $statusCode, string $status, string $message): never {
    http_response_code($statusCode);
    echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

// Tentukan filter interval waktu
$timeCondition = '';
$params = [$deviceId];

$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
$validTrue = $driver === 'pgsql' ? 'TRUE' : '1';
$validFalse = $driver === 'pgsql' ? 'FALSE' : '0';

if ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    respond(400, 'error', 'start_date harus berformat YYYY-MM-DD');
}
if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    respond(400, 'error', 'end_date harus berformat YYYY-MM-DD');
}
if ($status !== 'all' && !in_array($status, ['valid', 'invalid'], true)) {
    respond(400, 'error', 'status tidak valid');
}

$rangeHours = ['1h' => 1, '6h' => 6, '24h' => 24, '7d' => 24 * 7];
if (isset($rangeHours[$range])) {
    $cutoff = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))
        ->modify("-{$rangeHours[$range]} hours")
        ->format('Y-m-d H:i:s');
    $timeCondition = ' AND received_at >= ?';
    $params[] = $cutoff;
}

if ($startDate !== '') {
    $timeCondition .= ' AND received_at >= ?';
    $params[] = $startDate . ' 00:00:00';
}
if ($endDate !== '') {
    $endExclusive = (new DateTimeImmutable($endDate . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s');
    $timeCondition .= ' AND received_at < ?';
    $params[] = $endExclusive;
}
if ($status === 'valid') {
    $timeCondition .= " AND is_valid = {$validTrue}";
} elseif ($status === 'invalid') {
    $timeCondition .= " AND is_valid = {$validFalse}";
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
