<?php
/**
 * Classic Enzyme IoT - SQLite to PostgreSQL Data Migrator
 */
require_once __DIR__ . '/../config/database.php';

echo "Memulai migrasi data dari SQLite ke PostgreSQL...\n";

$sqlitePath = __DIR__ . '/../db/iot.sqlite';
if (!file_exists($sqlitePath)) {
    die("File db/iot.sqlite tidak ditemukan!\n");
}

$sqlite = new PDO('sqlite:' . $sqlitePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Konek ke Postgres
$pgDsn = "pgsql:host=127.0.0.1;port=5432;dbname=classic_enzyme_iot";
$pg = new PDO($pgDsn, 'favian', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "Koneksi PostgreSQL berhasil!\n";

// Inisialisasi tabel di Postgres jika belum ada
initPgsqlSchema($pg);

// Migrasi Devices
$devices = $sqlite->query("SELECT * FROM devices")->fetchAll();
$stmtDev = $pg->prepare("
    INSERT INTO devices (device_id, device_name, location, api_key, status, last_seen, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON CONFLICT (device_id) DO UPDATE 
    SET device_name = EXCLUDED.device_name,
        location = EXCLUDED.location,
        last_seen = EXCLUDED.last_seen,
        status = EXCLUDED.status
");

foreach ($devices as $d) {
    $stmtDev->execute([
        $d['device_id'],
        $d['device_name'],
        $d['location'] ?? 'Lab Fermentasi Utama',
        $d['api_key'] ?? 'GANTI_API_KEY_SEBELUM_PRODUKSI',
        $d['status'] ?? 'offline',
        $d['last_seen'] ?? null,
        $d['created_at'] ?? date('Y-m-d H:i:s'),
    ]);
}
echo "✓ " . count($devices) . " devices termigrasi.\n";

// Migrasi Settings
$settings = $sqlite->query("SELECT * FROM settings")->fetchAll();
$stmtSet = $pg->prepare("
    INSERT INTO settings (setting_key, setting_value, updated_at)
    VALUES (?, ?, ?)
    ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = EXCLUDED.updated_at
");
foreach ($settings as $s) {
    $stmtSet->execute([$s['setting_key'], $s['setting_value'], $s['updated_at'] ?? date('Y-m-d H:i:s')]);
}
echo "✓ " . count($settings) . " settings termigrasi.\n";

// Migrasi Thresholds
$thresholds = $sqlite->query("SELECT * FROM thresholds")->fetchAll();
$stmtThr = $pg->prepare("
    INSERT INTO thresholds (device_id, param, val_min, val_max, updated_at)
    VALUES (?, ?, ?, ?, ?)
    ON CONFLICT (device_id, param) DO UPDATE SET val_min = EXCLUDED.val_min, val_max = EXCLUDED.val_max
");
foreach ($thresholds as $t) {
    $stmtThr->execute([$t['device_id'], $t['param'], $t['val_min'], $t['val_max'], $t['updated_at'] ?? date('Y-m-d H:i:s')]);
}
echo "✓ " . count($thresholds) . " thresholds termigrasi.\n";

// Migrasi Admins
$admins = $sqlite->query("SELECT * FROM admins")->fetchAll();
$stmtAdm = $pg->prepare("
    INSERT INTO admins (username, password, created_at)
    VALUES (?, ?, ?)
    ON CONFLICT (username) DO NOTHING
");
foreach ($admins as $a) {
    $stmtAdm->execute([$a['username'], $a['password'], $a['created_at'] ?? date('Y-m-d H:i:s')]);
}
echo "✓ " . count($admins) . " admins termigrasi.\n";

// Migrasi Telemetry
$countTelemetry = (int)$sqlite->query("SELECT COUNT(*) FROM telemetry")->fetchColumn();
echo "Memigrasi {$countTelemetry} baris telemetri...\n";

$pg->beginTransaction();
$stmtTel = $pg->prepare("
    INSERT INTO telemetry (id, device_id, temperature, ph, alcohol, raw_temp, raw_adc, rssi, firmware_ver, device_ts, ip_address, received_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON CONFLICT (id) DO NOTHING
");

$cursor = $sqlite->query("SELECT * FROM telemetry ORDER BY id ASC");
$batch = 0;
while ($r = $cursor->fetch()) {
    $stmtTel->execute([
        $r['id'],
        $r['device_id'],
        $r['temperature'],
        $r['ph'],
        $r['alcohol'],
        $r['raw_temp'] ?? null,
        $r['raw_adc'] ?? null,
        $r['rssi'] ?? null,
        $r['firmware_ver'] ?? '1.0.0',
        $r['device_ts'] ?? null,
        $r['ip_address'] ?? null,
        $r['received_at'] ?? date('Y-m-d H:i:s'),
    ]);
    $batch++;
    if ($batch % 1000 === 0) {
        $pg->commit();
        $pg->beginTransaction();
        echo "  - {$batch} baris...\n";
    }
}
$pg->commit();

// Update sequence telemetry id di postgres agar autoincrement ID berikutnya benar
$pg->exec("SELECT setval('telemetry_id_seq', (SELECT COALESCE(MAX(id), 1) FROM telemetry));");
$pg->exec("SELECT setval('devices_id_seq', (SELECT COALESCE(MAX(id), 1) FROM devices));");

echo "✓ Seluruh {$batch} baris telemetri berhasil dipindahkan ke PostgreSQL!\n";
