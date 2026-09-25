<?php
/**
 * Classic Enzyme IoT — Telemetry ingest v2.
 * API key hanya diterima melalui X-API-Key. Payload v2 menambah timestamp,
 * boot_id, dan sequence untuk menolak replay request secara atomik.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, 'error', 'Hanya menerima method POST');
}

require_once __DIR__ . '/../config/database.php';

$sourceIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rawBody = file_get_contents('php://input', false, null, 0, 16385);
if (PHP_SAPI === 'cli' && $rawBody === '') {
    $rawBody = stream_get_contents(STDIN);
}
if ($rawBody === false || strlen($rawBody) > 16384) {
    respond(413, 'error', 'Payload terlalu besar');
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    respond(400, 'error', 'Format payload tidak valid');
}

$deviceId = trim((string)($data['device_id'] ?? ''));
if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $deviceId)) {
    respond(400, 'error', 'device_id tidak valid');
}

// Tidak ada fallback api_key dari JSON. Header tidak pernah dicatat ke log/event.
$apiKey = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
if ($apiKey === '' || strlen($apiKey) > 255) {
    respond(401, 'error', 'Autentikasi perangkat gagal');
}

$db = getDB();
$deviceStmt = $db->prepare('SELECT id, device_id, api_key, api_key_hash FROM devices WHERE device_id = ? LIMIT 1');
$deviceStmt->execute([$deviceId]);
$device = $deviceStmt->fetch();

if (!$device) {
    $limit = consumeRateLimit($db, 'unknown-ip:' . $sourceIp, 10);
    recordSecurityEvent($db, 'unknown_device', $deviceId, $sourceIp, 'Telemetry ditolak: device tidak ditemukan');
    if (!$limit['allowed']) respond(429, 'error', 'Terlalu banyak request', ['retry_after' => $limit['retry_after']]);
    respond(401, 'error', 'Autentikasi perangkat gagal');
}

$authenticated = false;
if (!empty($device['api_key_hash'])) {
    $authenticated = password_verify($apiKey, $device['api_key_hash']);
} elseif (!empty($device['api_key'])) {
    // Kompatibilitas sementara untuk device lama; hash dibuat segera setelah key valid dipakai.
    $authenticated = hash_equals((string)$device['api_key'], $apiKey);
    if ($authenticated) {
        $hashStmt = $db->prepare('UPDATE devices SET api_key_hash = ? WHERE id = ? AND api_key_hash IS NULL');
        $hashStmt->execute([password_hash($apiKey, PASSWORD_DEFAULT), $device['id']]);
    }
}

if (!$authenticated) {
    $limit = consumeRateLimit($db, 'auth-fail-ip:' . $sourceIp, 10);
    recordSecurityEvent($db, 'invalid_api_key', $deviceId, $sourceIp, 'Telemetry ditolak: autentikasi gagal');
    if (!$limit['allowed']) respond(429, 'error', 'Terlalu banyak request', ['retry_after' => $limit['retry_after']]);
    respond(401, 'error', 'Autentikasi perangkat gagal');
}

$limit = consumeRateLimit($db, 'device:' . $deviceId, 30);
if (!$limit['allowed']) {
    recordSecurityEvent($db, 'rate_limited', $deviceId, $sourceIp, 'Batas ingest device terlampaui');
    respond(429, 'error', 'Terlalu banyak request', ['retry_after' => $limit['retry_after']]);
}

$protocolVersion = isset($data['protocol_version']) && is_numeric($data['protocol_version'])
    ? (int)$data['protocol_version'] : 1;
$deviceTs = isset($data['ts']) && is_numeric($data['ts']) ? (int)$data['ts'] : null;
$bootId = trim((string)($data['boot_id'] ?? ''));
$sequence = isset($data['sequence']) && is_numeric($data['sequence']) ? (int)$data['sequence'] : null;

if ($protocolVersion >= 2) {
    if ($deviceTs === null || abs(time() - $deviceTs) > 900) {
        recordSecurityEvent($db, 'stale_timestamp', $deviceId, $sourceIp, 'Timestamp telemetri di luar toleransi');
        respond(422, 'error', 'Timestamp perangkat tidak valid');
    }
    if (!preg_match('/^[a-f0-9]{32}$/i', $bootId) || $sequence === null || $sequence < 1) {
        respond(422, 'error', 'Identitas request tidak valid');
    }
} else {
    // Firmware v1 tetap dapat bermigrasi, namun tidak mendapat proteksi replay v2.
    $bootId = null;
    $sequence = null;
}

$flags = [];
$temperature = numericTelemetryValue($data, 'temperature', $flags);
$ph = numericTelemetryValue($data, 'ph', $flags);
$alcohol = numericTelemetryValue($data, 'alcohol', $flags);
$rawTemp = numericTelemetryValue($data, 'raw_temp', $flags);
$rawAdc = numericTelemetryValue($data, 'raw_adc', $flags);
$rssi = numericTelemetryValue($data, 'rssi', $flags);

if ($temperature !== null && ($temperature < 0 || $temperature > 100)) $flags[] = 'temperature_out_of_physical_range';
if ($ph !== null && ($ph < 0 || $ph > 14)) $flags[] = 'ph_out_of_physical_range';
if ($alcohol !== null && ($alcohol < 0 || $alcohol > 4095)) $flags[] = 'alcohol_out_of_physical_range';
if ($rawAdc !== null && ($rawAdc < 0 || $rawAdc > 4095)) $flags[] = 'raw_adc_out_of_range';
if ($rssi !== null && ($rssi < -120 || $rssi > 0)) $flags[] = 'rssi_out_of_range';

$isValid = empty($flags);
$firmwareVer = isset($data['firmware']) ? substr(trim((string)$data['firmware']), 0, 32) : '1.0.0';
$serverTime = date('Y-m-d H:i:s');
$validationFlags = $isValid ? null : json_encode(array_values(array_unique($flags)), JSON_UNESCAPED_SLASHES);

try {
    $db->beginTransaction();
    $stmtInsert = $db->prepare('INSERT INTO telemetry (
        device_id, temperature, ph, alcohol, raw_temp, raw_adc, rssi,
        firmware_ver, device_ts, ip_address, received_at, boot_id,
        request_sequence, is_valid, validation_flags
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmtInsert->execute([
        $deviceId, $temperature, $ph, $alcohol, $rawTemp, $rawAdc, $rssi,
        $firmwareVer, $deviceTs, $sourceIp, $serverTime, $bootId,
        $sequence, $isValid ? 1 : 0, $validationFlags,
    ]);
    $insertedId = (int)$db->lastInsertId();

    $stmtUpdateDev = $db->prepare("UPDATE devices SET status = 'online', last_seen = ? WHERE device_id = ?");
    $stmtUpdateDev->execute([$serverTime, $deviceId]);

    $alarmsTriggered = $isValid ? evaluateAlarms($db, $deviceId, $temperature, $ph, $alcohol, $serverTime) : [];
    $db->commit();

    respond(200, 'ok', 'Telemetry received successfully', [
        'telemetry_id' => $insertedId,
        'server_time' => $serverTime,
        'alarms_count' => count($alarmsTriggered),
        'is_valid' => $isValid,
        'validation_flags' => $isValid ? [] : json_decode($validationFlags, true),
    ]);
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ($protocolVersion >= 2 && isUniqueViolation($e)) {
        recordSecurityEvent($db, 'replay_rejected', $deviceId, $sourceIp, 'Duplikasi boot_id dan sequence');
        respond(409, 'error', 'Request telemetri duplikat');
    }
    error_log('Telemetry insert failed: ' . $e->getMessage());
    respond(500, 'error', 'Gagal menyimpan data telemetri');
}

function numericTelemetryValue(array $data, string $key, array &$flags): ?float {
    if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') return null;
    if (!is_numeric($data[$key])) {
        $flags[] = $key . '_not_numeric';
        return null;
    }
    return (float)$data[$key];
}

function evaluateAlarms(PDO $db, string $deviceId, ?float $temperature, ?float $ph, ?float $alcohol, string $serverTime): array {
    $stmt = $db->prepare('SELECT param, val_min, val_max FROM thresholds WHERE device_id = ?');
    $stmt->execute([$deviceId]);
    $thresholds = [];
    while ($row = $stmt->fetch()) $thresholds[$row['param']] = $row;

    $checks = [
        ['temp', $temperature, 'Suhu fermentasi', '°C', 'critical'],
        ['ph', $ph, 'Nilai pH', '', 'warning'],
        ['alcohol', $alcohol, 'Konsentrasi alkohol ADC', '', 'warning'],
    ];
    $alarms = [];
    foreach ($checks as [$param, $actual, $label, $unit, $severity]) {
        if ($actual === null || !isset($thresholds[$param])) continue;
        $min = $thresholds[$param]['val_min'] !== null ? (float)$thresholds[$param]['val_min'] : null;
        $max = $thresholds[$param]['val_max'] !== null ? (float)$thresholds[$param]['val_max'] : null;
        if ($max !== null && $actual > $max) {
            $alarms[] = [$param . '_high', $severity, $max, $actual, "{$label} {$actual}{$unit} melebihi ambang {$max}{$unit}."];
        } elseif ($min !== null && $actual < $min) {
            $alarms[] = [$param . '_low', $param === 'temp' ? 'warning' : $severity, $min, $actual, "{$label} {$actual}{$unit} di bawah ambang {$min}{$unit}."];
        }
    }
    if ($alarms) {
        $insert = $db->prepare('INSERT INTO alarms (device_id, alarm_type, severity, threshold_val, actual_val, message, triggered_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($alarms as $alarm) $insert->execute([$deviceId, ...$alarm, $serverTime]);
    }
    return $alarms;
}

function consumeRateLimit(PDO $db, string $scope, int $maxRequests): array {
    $now = date('Y-m-d H:i:s');
    $window = date('Y-m-d H:i:00');
    $existing = $db->prepare('SELECT blocked_until FROM ingest_rate_limits WHERE scope = ? AND blocked_until > ? ORDER BY blocked_until DESC LIMIT 1');
    $existing->execute([$scope, $now]);
    $blockedUntil = $existing->fetchColumn();
    if ($blockedUntil) return ['allowed' => false, 'retry_after' => max(1, strtotime($blockedUntil) - time())];

    $sql = 'INSERT INTO ingest_rate_limits (scope, window_start, request_count)
            VALUES (?, ?, 1)
            ON CONFLICT(scope, window_start)
            DO UPDATE SET request_count = ingest_rate_limits.request_count + 1';
    $stmt = $db->prepare($sql);
    $stmt->execute([$scope, $window]);
    $countStmt = $db->prepare('SELECT request_count FROM ingest_rate_limits WHERE scope = ? AND window_start = ?');
    $countStmt->execute([$scope, $window]);
    $count = (int)$countStmt->fetchColumn();
    if ($count <= $maxRequests) return ['allowed' => true, 'retry_after' => 0];

    $blockedUntil = date('Y-m-d H:i:s', time() + 300);
    $block = $db->prepare('UPDATE ingest_rate_limits SET blocked_until = ? WHERE scope = ? AND window_start = ?');
    $block->execute([$blockedUntil, $scope, $window]);
    return ['allowed' => false, 'retry_after' => 300];
}

function recordSecurityEvent(PDO $db, string $type, ?string $deviceId, string $sourceIp, string $detail): void {
    try {
        $stmt = $db->prepare('INSERT INTO security_events (event_type, device_id, source_ip, detail) VALUES (?, ?, ?, ?)');
        $stmt->execute([$type, $deviceId, substr($sourceIp, 0, 64), substr($detail, 0, 250)]);
    } catch (Throwable $e) {
        error_log('Security event logging failed');
    }
}

function isUniqueViolation(PDOException $e): bool {
    return in_array($e->getCode(), ['23000', '23505'], true);
}

function respond(int $statusCode, string $status, string $message, array $extra = []): never {
    http_response_code($statusCode);
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}
