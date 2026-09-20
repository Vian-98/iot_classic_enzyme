<?php
/**
 * Classic Enzyme IoT - Full History & Data Export Page
 * Pure Native PHP + Liquid Glass Aesthetic
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

$db = getDB();

$deviceId = trim($_GET['device_id'] ?? 'esp32-ce-001');
$range    = trim($_GET['range'] ?? '24h');
$limit    = min(500, max(10, (int)($_GET['limit'] ?? 100)));
$action   = $_GET['action'] ?? '';

// Handle CSV Export
if ($action === 'export_csv') {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=telemetri_classic_enzyme_' . date('Ymd_His') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM untuk Microsoft Excel agar kolom rapi otomatis
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $headers = ['ID', 'Device ID', 'Waktu Penerimaan', 'Suhu (C)', 'pH', 'Alkohol (ADC)', 'Raw Temp', 'Raw ADC', 'WiFi RSSI', 'Firmware'];
    if (isAdminLoggedIn()) {
        $headers[] = 'IP Pengirim';
    }
    // Escape eksplisit agar kompatibel dengan PHP 8.4+ dan output CSV bersih.
    fputcsv($output, $headers, ',', '"', '');

    $stmtExport = $db->prepare("SELECT * FROM telemetry WHERE device_id = ? ORDER BY received_at DESC LIMIT 5000");
    $stmtExport->execute([$deviceId]);
    while ($row = $stmtExport->fetch()) {
        $csvRow = [
            $row['id'],
            $row['device_id'],
            $row['received_at'],
            $row['temperature'],
            $row['ph'],
            $row['alcohol'],
            $row['raw_temp'],
            $row['raw_adc'],
            $row['rssi'],
            $row['firmware_ver']
        ];
        if (isAdminLoggedIn()) {
            $csvRow[] = $row['ip_address'];
        }
        fputcsv($output, $csvRow, ',', '"', '');
    }
    fclose($output);
    exit;
}

// Query devices
try {
    $devices = $db->query("SELECT device_id, device_name FROM devices ORDER BY id ASC")->fetchAll();
} catch (Exception $e) {
    $devices = [];
}

// Query Data with date filter (detect active driver: PostgreSQL / MySQL / SQLite)
$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
$timeCondition = '';
if ($range === '1h') {
    if ($driver === 'mysql')      $timeCondition = "AND received_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
    elseif ($driver === 'pgsql')  $timeCondition = "AND received_at >= NOW() - INTERVAL '1 hour'";
    else                          $timeCondition = "AND received_at >= datetime('now', '-1 hour', 'localtime')";
} elseif ($range === '6h') {
    if ($driver === 'mysql')      $timeCondition = "AND received_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)";
    elseif ($driver === 'pgsql')  $timeCondition = "AND received_at >= NOW() - INTERVAL '6 hours'";
    else                          $timeCondition = "AND received_at >= datetime('now', '-6 hours', 'localtime')";
} elseif ($range === '24h') {
    if ($driver === 'mysql')      $timeCondition = "AND received_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    elseif ($driver === 'pgsql')  $timeCondition = "AND received_at >= NOW() - INTERVAL '24 hours'";
    else                          $timeCondition = "AND received_at >= datetime('now', '-24 hours', 'localtime')";
} elseif ($range === '7d') {
    if ($driver === 'mysql')      $timeCondition = "AND received_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    elseif ($driver === 'pgsql')  $timeCondition = "AND received_at >= NOW() - INTERVAL '7 days'";
    else                          $timeCondition = "AND received_at >= datetime('now', '-7 days', 'localtime')";
}

$stmt = $db->prepare("
    SELECT * FROM telemetry 
    WHERE device_id = ? {$timeCondition} 
    ORDER BY received_at DESC 
    LIMIT ?
");
$stmt->bindValue(1, $deviceId, PDO::PARAM_STR);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll();

// Statistik hanya menggunakan pembacaan yang lolos validasi transport.
$validRecords = array_filter($records, fn($r) => !array_key_exists('is_valid', $r) || (bool)$r['is_valid']);
$temps    = array_filter(array_column($validRecords, 'temperature'), fn($v) => $v !== null);
$phs      = array_filter(array_column($validRecords, 'ph'), fn($v) => $v !== null);
$alcohols = array_filter(array_column($validRecords, 'alcohol'), fn($v) => $v !== null);

$stats = [
    'count'       => count($records),
    'temp_avg'    => !empty($temps)    ? round(array_sum($temps) / count($temps), 2) : '--',
    'temp_min'    => !empty($temps)    ? min($temps) : '--',
    'temp_max'    => !empty($temps)    ? max($temps) : '--',
    'ph_avg'      => !empty($phs)      ? round(array_sum($phs) / count($phs), 2) : '--',
    'ph_min'      => !empty($phs)      ? min($phs) : '--',
    'ph_max'      => !empty($phs)      ? max($phs) : '--',
    'alc_avg'     => !empty($alcohols) ? round(array_sum($alcohols) / count($alcohols)) : '--',
    'alc_min'     => !empty($alcohols) ? min($alcohols) : '--',
    'alc_max'     => !empty($alcohols) ? max($alcohols) : '--',
    'alc_active'  => !empty($alcohols),
];
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Riwayat & Laporan Telemetri — Classic Enzyme</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <style>
        .filter-form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            width: 100%;
        }
        @media (min-width: 640px) {
            .filter-form-grid {
                grid-template-columns: repeat(3, 1fr) auto;
                align-items: flex-end;
            }
        }
    </style>
</head>
<body data-page="history">

    <!-- Ambient Animated Background -->
    <div class="ambient-mesh">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
    </div>

    <div class="app-wrapper">

        <!-- Navbar Dock -->
        <header class="navbar-dock glass">
            <!-- Row 1: Brand & Action Buttons (Mobile) / Left & Right (Desktop) -->
            <div class="navbar-row-1">
                <div class="brand-section">
                    <a href="index.php" style="text-decoration:none; display:flex; align-items:center;">
                        <div>
                            <h1 class="brand-title">Classic Enzyme</h1>
                            <div class="brand-subtitle">Riwayat Telemetri</div>
                        </div>
                    </a>
                </div>
                <div class="navbar-row-1-actions">
                    <a href="index.php" class="glass-btn nav-desktop-only">Dashboard</a>
                    <a href="?device_id=<?= urlencode($deviceId) ?>&range=<?= urlencode($range) ?>&action=export_csv" class="glass-btn nav-desktop-only">Ekspor CSV</a>
                    <?php if (isAdminLoggedIn()): ?>
                    <a href="admin.php" class="glass-btn nav-desktop-only" style="color:var(--teal); border-color:var(--teal-border);">Admin</a>
                    <?php else: ?>
                    <a href="login.php" class="glass-btn nav-desktop-only" style="color:var(--text-muted); font-size:0.75rem;">Admin</a>
                    <?php endif; ?>
                    <button id="themeToggleBtn" class="glass-btn glass-btn-icon" aria-label="Toggle Theme" title="Beralih Tema">
                        <span id="themeIcon" style="display:inline-flex; align-items:center; justify-content:center;"></span>
                    </button>
                </div>
            </div>
            <!-- Row 2: Ekspor CSV (mobile visible) -->
            <div class="navbar-row-2">
                <a href="?device_id=<?= urlencode($deviceId) ?>&range=<?= urlencode($range) ?>&action=export_csv" class="glass-btn" style="font-size:0.8rem;">⬇ Ekspor CSV</a>
            </div>
        </header>

        <!-- Filter Bar -->
        <section class="glass" style="padding: 18px 20px;">
            <form method="GET" class="filter-form-grid">
                <div>
                    <label style="font-size:0.72rem; color:var(--text-muted); display:block; margin-bottom:4px; font-weight:600;">PILIH DEVICE</label>
                    <select name="device_id" class="glass-select" onchange="this.form.submit()">
                        <?php foreach ($devices as $d): ?>
                            <option value="<?= htmlspecialchars($d['device_id']) ?>" <?= $d['device_id'] === $deviceId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['device_name']) ?> (<?= htmlspecialchars($d['device_id']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="font-size:0.72rem; color:var(--text-muted); display:block; margin-bottom:4px; font-weight:600;">RENTANG WAKTU</label>
                    <select name="range" class="glass-select" onchange="this.form.submit()">
                        <option value="1h" <?= $range === '1h' ? 'selected' : '' ?>>1 Jam Terakhir</option>
                        <option value="6h" <?= $range === '6h' ? 'selected' : '' ?>>6 Jam Terakhir</option>
                        <option value="24h" <?= $range === '24h' ? 'selected' : '' ?>>24 Jam Terakhir</option>
                        <option value="7d" <?= $range === '7d' ? 'selected' : '' ?>>7 Hari Terakhir</option>
                        <option value="all" <?= $range === 'all' ? 'selected' : '' ?>>Semua Data</option>
                    </select>
                </div>

                <div>
                    <label style="font-size:0.72rem; color:var(--text-muted); display:block; margin-bottom:4px; font-weight:600;">BATAS BARIS</label>
                    <select name="limit" class="glass-select" onchange="this.form.submit()">
                        <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50 Baris</option>
                        <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100 Baris</option>
                        <option value="200" <?= $limit == 200 ? 'selected' : '' ?>>200 Baris</option>
                        <option value="500" <?= $limit == 500 ? 'selected' : '' ?>>500 Baris</option>
                    </select>
                </div>

                <div style="display: flex; align-items: flex-end;">
                    <button type="submit" class="glass-btn" style="width:100%; background: var(--teal); color: #000; font-weight: 700;">
                        Filter
                    </button>
                </div>
            </form>
        </section>

        <!-- Summary Statistics Cards -->
        <section class="metric-grid">
            <div class="glass sensor-card card-temp">
                <span class="sensor-title">Rata-Rata Suhu</span>
                <div class="sensor-value-area">
                    <span class="sensor-value" style="color:var(--pink);"><?= $stats['temp_avg'] ?></span>
                    <span class="sensor-unit">°C</span>
                </div>
                <div class="sensor-footer">
                    <span>Min: <?= $stats['temp_min'] ?> °C</span>
                    <span>Max: <?= $stats['temp_max'] ?> °C</span>
                </div>
            </div>

            <div class="glass sensor-card card-ph">
                <span class="sensor-title">Rata-Rata pH</span>
                <div class="sensor-value-area">
                    <span class="sensor-value" style="color:var(--teal);"><?= $stats['ph_avg'] ?></span>
                    <span class="sensor-unit">pH</span>
                </div>
                <div class="sensor-footer">
                    <span>Min: <?= $stats['ph_min'] ?></span>
                    <span>Max: <?= $stats['ph_max'] ?></span>
                </div>
            </div>

            <div class="glass sensor-card card-alcohol">
                <div class="sensor-header">
                    <div class="sensor-meta">
                        <span class="sensor-title">Rata-Rata Alkohol</span>
                        <span class="sensor-hardware">MQ-3 · GPIO 34</span>
                    </div>
                    <span class="sensor-badge <?= $stats['alc_active'] ? 'badge-violet' : 'badge-slate' ?>"><?= $stats['alc_active'] ? 'GAS' : 'OFF' ?></span>
                </div>
                <div class="sensor-value-area">
                    <span class="sensor-value" style="color:var(--violet);"><?= $stats['alc_avg'] ?></span>
                    <span class="sensor-unit"><?= $stats['alc_active'] ? 'ADC' : '' ?></span>
                </div>
                <div class="sensor-footer">
                    <?php if ($stats['alc_active']): ?>
                    <span>Min: <?= $stats['alc_min'] ?></span>
                    <span>Max: <?= $stats['alc_max'] ?></span>
                    <?php else: ?>
                    <span style="color:var(--text-subtle);">Sensor belum terpasang</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="glass sensor-card">
                <span class="sensor-title">Total Rekaman</span>
                <div class="sensor-value-area">
                    <span class="sensor-value" style="color:var(--text-main);"><?= $stats['count'] ?></span>
                    <span class="sensor-unit">sampel</span>
                </div>
                <div class="sensor-footer">
                    <span>Rentang: <?= htmlspecialchars($range) ?></span>
                    <span><?= htmlspecialchars($deviceId) ?></span>
                </div>
            </div>
        </section>

        <!-- History Table Section -->
        <section class="glass table-section">
            <div class="table-header">
                <div>
                    <h2 style="font-size: 1.15rem; font-weight: 700;">Log Riwayat Telemetri</h2>
                    <p style="font-size: 0.78rem; color: var(--text-muted);">Diurutkan dari data yang paling baru diterima di server</p>
                </div>
                <!-- Filter Tampilan Tabel: Semua / Valid (Ada Data) / Offline -->
                <div class="device-status-filter" id="histFilterGroup" role="group" aria-label="Filter tampilan tabel">
                    <button class="filter-status-btn active" data-histfilter="all">
                        Semua
                    </button>
                    <button class="filter-status-btn" data-histfilter="valid">
                        <span class="filter-dot online"></span>Valid (Ada Data)
                    </button>
                    <button class="filter-status-btn" data-histfilter="offline">
                        <span class="filter-dot offline"></span>Offline (Tidak Ada Data)
                    </button>
                </div>
            </div>

            <!-- Mobile Card View List (< 640px) -->
            <div class="table-card-list" id="historyCardList">
                <?php if (empty($records)): ?>
                    <div class="table-card-item" style="text-align: center; color: var(--text-muted); padding: 24px;">
                        Tidak ada data untuk filter waktu yang dipilih.
                    </div>
                <?php else: ?>
                    <?php
                        $prevTimestampCard = null;
                        $offlineTimeoutCard = (int)getSetting('offline_timeout_seconds', 300);
                        foreach ($records as $r):
                            $currentTimestamp = strtotime($r['received_at']);
                            if ($prevTimestampCard !== null) {
                                $gapSeconds = $prevTimestampCard - $currentTimestamp;
                                if ($gapSeconds > $offlineTimeoutCard) {
                                    $gapHrs = floor($gapSeconds / 3600);
                                    $gapMins = floor(($gapSeconds % 3600) / 60);
                                    $gapLabel = $gapHrs > 0 ? "{$gapHrs} jam {$gapMins} menit" : "{$gapMins} menit";
                                    echo "<div class='table-card-downtime downtime-row'>
                                        <span class='downtime-dot' style='width:8px;height:8px;border-radius:50%;background:var(--pink);flex-shrink:0;'></span>
                                        <span><strong>OFFLINE</strong> selama <strong>{$gapLabel}</strong> (antara " . date('H:i:s', $currentTimestamp) . " s/d " . date('H:i:s', $prevTimestampCard) . ")</span>
                                    </div>";
                                }
                            }
                            $prevTimestampCard = $currentTimestamp;
                            $isValidRecord = !array_key_exists('is_valid', $r) || (bool)$r['is_valid'];
                            $temp = $isValidRecord && $r['temperature'] !== null ? htmlspecialchars($r['temperature']) . ' °C' : '--';
                            $ph   = $isValidRecord && $r['ph'] !== null ? htmlspecialchars($r['ph']) : '--';
                            $alc  = $isValidRecord && $r['alcohol'] !== null ? htmlspecialchars($r['alcohol']) : '--';
                            $rssi = $r['rssi'] !== null ? htmlspecialchars($r['rssi']) . ' dBm' : '--';
                    ?>
                        <div class="table-card-item data-row">
                            <div class="table-card-header">
                                <span class="table-card-time"><?= htmlspecialchars($r['received_at']) ?></span>
                                <span class="table-card-rel"><?= $isValidRecord ? formatRelativeTime($r['received_at']) : 'DATA INVALID' ?></span>
                            </div>
                            <div class="table-card-grid">
                                <div class="table-card-metric">
                                    <span class="table-card-metric-label">Suhu</span>
                                    <span class="table-card-metric-value temp"><?= $temp ?></span>
                                </div>
                                <div class="table-card-metric">
                                    <span class="table-card-metric-label">pH</span>
                                    <span class="table-card-metric-value ph"><?= $ph ?></span>
                                </div>
                                <div class="table-card-metric">
                                    <span class="table-card-metric-label">Alkohol</span>
                                    <span class="table-card-metric-value alc"><?= $alc ?></span>
                                </div>
                            </div>
                            <div class="table-card-footer">
                                <span>WiFi RSSI: <?= $rssi ?></span>
                                <span>#<?= $r['id'] ?><?= isAdminLoggedIn() ? ' · ' . htmlspecialchars($r['ip_address'] ?? '') : '' ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="table-responsive">
                <table class="glass-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>WAKTU LENGKAP</th>
                            <th>SELISIH WAKTU</th>
                            <th>SUHU (MAX6675)</th>
                            <th>PH (PH-110)</th>
                            <th>ALKOHOL (MQ-3)</th>
                            <th>WIFI RSSI</th>
                            <?php if (isAdminLoggedIn()): ?>
                            <th>IP PENGIRIM</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="<?= isAdminLoggedIn() ? '8' : '7' ?>" style="text-align: center; color: var(--text-muted); padding: 32px;">
                                    Tidak ada data untuk filter waktu yang dipilih.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php
                                $prevTimestamp = null;
                                $offlineTimeout = (int)getSetting('offline_timeout_seconds', 300);
                                foreach ($records as $r):
                                    $currentTimestamp = strtotime($r['received_at']);
                                    if ($prevTimestamp !== null) {
                                        $gapSeconds = $prevTimestamp - $currentTimestamp;
                                        if ($gapSeconds > $offlineTimeout) {
                                            $gapHrs = floor($gapSeconds / 3600);
                                            $gapMins = floor(($gapSeconds % 3600) / 60);
                                            $gapLabel = $gapHrs > 0 ? "{$gapHrs} jam {$gapMins} menit" : "{$gapMins} menit";
                                            $colSpan = isAdminLoggedIn() ? 8 : 7;
                                            echo "<tr class='downtime-row'>
                                                <td colspan='{$colSpan}'>
                                                    <div class='downtime-badge'>
                                                        <span class='downtime-dot'></span>
                                                        <span><strong>PERANGKAT OFFLINE / PUTUS</strong> selama <strong>{$gapLabel}</strong> (antara " . date('H:i:s', $currentTimestamp) . " s/d " . date('H:i:s', $prevTimestamp) . ")</span>
                                                    </div>
                                                </td>
                                            </tr>";
                                        }
                                    }
                                    $prevTimestamp = $currentTimestamp;
                            ?>
                                <?php $isValidRecord = !array_key_exists('is_valid', $r) || (bool)$r['is_valid']; ?>
                                <tr class="data-row" title="<?= $isValidRecord ? '' : htmlspecialchars($r['validation_flags'] ?? 'Data di luar rentang fisik') ?>">
                                    <td>#<?= $r['id'] ?></td>
                                    <td><?= htmlspecialchars($r['received_at']) ?></td>
                                    <td style="color:var(--text-muted); font-size:0.75rem;"><?= formatRelativeTime($r['received_at']) ?></td>
                                    <td>
                                        <span style="color:var(--pink); font-weight:700;">
                                            <?= $isValidRecord && $r['temperature'] !== null ? htmlspecialchars($r['temperature']) . ' °C' : '--' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color:var(--teal); font-weight:700;">
                                            <?= $isValidRecord && $r['ph'] !== null ? htmlspecialchars($r['ph']) : '--' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color:var(--amber); font-weight:700;">
                                            <?= $isValidRecord && $r['alcohol'] !== null ? htmlspecialchars($r['alcohol']) : '--' ?>
                                        </span>
                                    </td>
                                    <td><?= $isValidRecord && $r['rssi'] !== null ? htmlspecialchars($r['rssi']) . ' dBm' : 'INVALID' ?></td>
                                    <?php if (isAdminLoggedIn()): ?>
                                    <td style="color:var(--text-subtle); font-size:0.75rem; font-family:var(--font-mono);"><?= htmlspecialchars($r['ip_address'] ?? '127.0.0.1') ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Footer -->
        <footer class="footer-bar glass">
            <div><strong>Classic Enzyme IoT</strong> · Laporan Data Historis</div>
            <div>Format Ekspor: CSV / UTF-8</div>
        </footer>

    </div>

    <!-- Theme Switcher + History Filter Script -->
    <script>
        // -----------------------------------------------
        // Theme
        // -----------------------------------------------
        const theme = localStorage.getItem('ce_theme') || 'dark';
        document.documentElement.setAttribute('data-theme', theme);
        const icon = document.getElementById('themeIcon');
        
        function updateThemeIcon(t) {
            if (!icon) return;
            if (t === 'dark') {
                icon.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>';
            } else {
                icon.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>';
            }
        }
        updateThemeIcon(theme);

        document.getElementById('themeToggleBtn')?.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme') || 'dark';
            const next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('ce_theme', next);
            updateThemeIcon(next);
        });

        // -----------------------------------------------
        // Filter Tampilan Tabel Riwayat
        // -----------------------------------------------
        (function() {
            const filterBtns  = document.querySelectorAll('#histFilterGroup .filter-status-btn');
            const dataRows     = document.querySelectorAll('.data-row');
            const offlineRows  = document.querySelectorAll('.downtime-row');

            // Hitung jumlah untuk badge (hanya dari table body agar tidak double count)
            const totalData    = document.querySelectorAll('tbody .data-row').length;
            const totalOffline = document.querySelectorAll('tbody .downtime-row').length;

            // Update label badge awal
            filterBtns.forEach(btn => {
                const f = btn.getAttribute('data-histfilter');
                if (f === 'all')     btn.textContent = `Semua (${totalData + totalOffline})`;
                if (f === 'valid')   btn.innerHTML   = `<span class="filter-dot online"></span>Valid (Ada Data) (${totalData})`;
                if (f === 'offline') btn.innerHTML   = `<span class="filter-dot offline"></span>Offline (${totalOffline})`;
            });

            function applyHistFilter(filter) {
                dataRows.forEach(row => {
                    row.style.display = (filter === 'offline') ? 'none' : '';
                });
                offlineRows.forEach(row => {
                    row.style.display = (filter === 'valid') ? 'none' : '';
                });
            }

            filterBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    filterBtns.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    applyHistFilter(btn.getAttribute('data-histfilter') || 'all');
                });
            });
        })();
    </script>
    <?php include __DIR__ . '/components/bottom_nav.php'; ?>
</body>
</html>
