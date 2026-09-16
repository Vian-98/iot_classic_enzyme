<?php
/**
 * Classic Enzyme IoT - Realtime Liquid Glass Dashboard
 * Pure Native PHP + HTML + CSS + JS (Zero-dependency)
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php'; // Auth helper (opsional untuk dashboard)

$db = getDB();

// Ambil list devices untuk selector
try {
    $devices = $db->query("SELECT device_id, device_name FROM devices ORDER BY id ASC")->fetchAll();
} catch (Exception $e) {
    $devices = [];
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Classic Enzyme IoT — Bioreactor Monitor</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <!-- Chart.js via CDN (Zero-build) -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body data-page="dashboard">
    <div class="ambient-mesh">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
        <div class="orb orb-4"></div>
    </div>

    <div class="app-wrapper">

        <header class="navbar-dock glass">
            <div class="brand-section">
                <div class="brand-logo-glass">CE</div>
                <div>
                    <h1 class="brand-title">Classic Enzyme</h1>
                    <div class="brand-subtitle">IoT Fermentation Monitor</div>
                </div>
            </div>

            <div class="dock-controls">
                <div class="device-select-wrapper">
                    <select id="deviceSelect" class="glass-select" aria-label="Pilih Bioreaktor">
                        <?php if (!empty($devices)): ?>
                            <?php foreach ($devices as $d): ?>
                                <option value="<?= htmlspecialchars($d['device_id']) ?>">
                                    <?= htmlspecialchars($d['device_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="esp32-ce-001">Bioreaktor 01</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div id="statusPill" class="status-pill offline">
                    <div style="display:flex; align-items:center; gap:7px;">
                        <span class="status-indicator-dot"></span>
                        <span id="statusText">--</span>
                    </div>
                    <span class="status-last-seen" id="statusLastSeen">--</span>
                </div>

                <a href="history.php" class="glass-btn">Riwayat</a>
                <?php if (isAdminLoggedIn()): ?>
                <a href="admin.php" class="glass-btn" style="color:var(--teal); border-color:var(--teal-border);">Admin</a>
                <?php else: ?>
                <a href="login.php" class="glass-btn" style="color:var(--text-muted); font-size:0.75rem;">Admin</a>
                <?php endif; ?>
                <button id="themeToggleBtn" class="glass-btn glass-btn-icon" aria-label="Toggle Theme">
                    <span id="themeIcon">D</span>
                </button>
            </div>
        </header>

        <section class="metric-grid">

            <div class="glass glass-interactive sensor-card card-temp">
                <div class="sensor-header">
                    <div class="sensor-meta">
                        <span class="sensor-title">Suhu</span>
                        <span class="sensor-hardware">MAX6675 · GPIO 18/19/5</span>
                    </div>
                    <span class="sensor-badge badge-pink" id="badgeTemp">TEMP</span>
                </div>
                <div class="sensor-value-area">
                    <span class="sensor-value" id="valTemp">--</span>
                    <span class="sensor-unit">°C</span>
                </div>
                <div class="sensor-footer">
                    <span>Status</span>
                    <span class="range-pill" id="footerTemp">30 – 38 °C</span>
                </div>
            </div>

            <div class="glass glass-interactive sensor-card card-ph">
                <div class="sensor-header">
                    <div class="sensor-meta">
                        <span class="sensor-title">Keasaman</span>
                        <span class="sensor-hardware">PH-110 · GPIO 16/17</span>
                    </div>
                    <span class="sensor-badge badge-teal" id="badgePh">pH</span>
                </div>
                <div class="sensor-value-area">
                    <span class="sensor-value" id="valPh">--</span>
                    <span class="sensor-unit">pH</span>
                </div>
                <div class="sensor-footer">
                    <span>Status</span>
                    <span class="range-pill" id="footerPh">Belum Terpasang</span>
                </div>
            </div>

            <div class="glass glass-interactive sensor-card card-alcohol">
                <div class="sensor-header">
                    <div class="sensor-meta">
                        <span class="sensor-title">Alkohol</span>
                        <span class="sensor-hardware">MQ-3 · GPIO 34</span>
                    </div>
                    <span class="sensor-badge badge-violet" id="badgeAlcohol">GAS</span>
                </div>
                <div class="sensor-value-area">
                    <span class="sensor-value" id="valAlcohol">--</span>
                    <span class="sensor-unit">ADC</span>
                </div>
                <div class="sensor-footer">
                    <span>Status</span>
                    <span class="range-pill" id="footerAlcohol">Belum Terpasang</span>
                </div>
            </div>

            <div class="glass glass-interactive sensor-card">
                <div class="sensor-header">
                    <div class="sensor-meta">
                        <span class="sensor-title">Koneksi</span>
                        <span class="sensor-hardware" id="valFirmware">fw --</span>
                    </div>
                    <span class="sensor-badge badge-slate">RSSI</span>
                </div>
                <div class="sensor-value-area">
                    <span class="sensor-value" id="valRssi" style="color:var(--text-main);">--</span>
                    <span class="sensor-unit">dBm</span>
                </div>
                <div class="sensor-footer">
                    <span>Status</span>
                    <span class="range-pill" id="statusChip">—</span>
                </div>
            </div>

        </section>

        <section class="glass chart-section">
            <div class="chart-header">
                <div class="chart-title-group">
                    <h2>Suhu &amp; pH — Realtime</h2>
                    <p>Diperbarui tiap 15 detik · Kiri: Suhu · Kanan: pH</p>
                </div>
                <div class="chart-controls">
                    <button class="chart-tab-btn active" data-range="1h">1 Jam</button>
                    <button class="chart-tab-btn" data-range="6h">6 Jam</button>
                    <button class="chart-tab-btn" data-range="24h">24 Jam</button>
                    <button class="chart-tab-btn" data-range="all">Semua</button>
                </div>
            </div>
            <div class="chart-container-box">
                <canvas id="telemetryChart"></canvas>
            </div>
        </section>

        <section class="glass table-section">
            <div class="table-header">
                <div>
                    <h2>Log Telemetri</h2>
                </div>
                <div class="btn-group">
                    <button id="btnRefresh" class="glass-btn">Refresh</button>
                    <button id="btnSimulate" class="glass-btn">Simulasi</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="glass-table">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Suhu</th>
                            <th>pH</th>
                            <th>Alkohol</th>
                            <th>RSSI</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="historyTableBody">
                        <tr>
                            <td colspan="6" style="text-align:center; color:var(--text-muted); padding:24px;">
                                Memuat...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <footer class="footer-bar glass">
            <div><strong>Classic Enzyme IoT</strong> · v1.0</div>
            <div id="footerTime" style="font-family:var(--font-mono);"></div>
        </footer>

    </div>

    <div id="toastContainer" class="toast-container"></div>
    <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
