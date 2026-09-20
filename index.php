<?php
/**
 * Classic Enzyme IoT - Realtime Liquid Glass Dashboard
 * Modular Component Architecture (Pure Native PHP + Partials)
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

$db = getDB();

// Ambil daftar perangkat untuk selector dropdown
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
    <title>Classic Enzyme IoT — Fermentation Monitor</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <!-- Chart.js via CDN (Zero-build) -->
    <script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body data-page="dashboard">
    <div class="ambient-mesh">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
        <div class="orb orb-4"></div>
    </div>

    <div class="app-wrapper">
        <!-- 1. Header & Navigation Dock -->
        <?php include __DIR__ . '/components/header.php'; ?>

        <!-- 2. Kartu Metrik Sensor Utama (Suhu, pH, Alkohol, RSSI) -->
        <?php include __DIR__ . '/components/metric_cards.php'; ?>

        <!-- 3. Area Grafik Multi-Metrik Realtime (Suhu, pH, Alkohol) -->
        <?php include __DIR__ . '/components/chart_section.php'; ?>

        <!-- 4. Tabel Log Telemetri Terkini -->
        <?php include __DIR__ . '/components/history_table.php'; ?>

        <!-- 5. Footer Bar -->
        <?php include __DIR__ . '/components/footer.php'; ?>
    </div>

    <div id="toastContainer" class="toast-container"></div>
    <?php include __DIR__ . '/components/bottom_nav.php'; ?>
    <script defer src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
