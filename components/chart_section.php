<?php
/**
 * Classic Enzyme IoT - Component: Chart Section
 * Area grafik interaktif 3 parameter (Suhu, pH, Alkohol) dengan multi-sumbu & filter range
 */
?>
<section class="glass chart-section">
    <div class="chart-header">
        <div class="chart-title-group">
            <h2>Suhu, pH &amp; Alkohol — Telemetri Realtime</h2>
        </div>
        <div class="chart-controls">
            <button class="chart-tab-btn active" data-range="1h">1 Jam</button>
            <button class="chart-tab-btn" data-range="6h">6 Jam</button>
            <button class="chart-tab-btn" data-range="24h">24 Jam</button>
            <button class="chart-tab-btn" data-range="7d">7 Hari</button>
            <button class="chart-tab-btn" data-range="all">Semua</button>
        </div>
    </div>
    <div class="chart-container-box">
        <div class="chart-loading-skeleton" id="chartLoadingSkeleton" aria-label="Memuat grafik" role="status">
            <span class="skeleton-line skeleton-line-wide"></span>
            <span class="skeleton-line"></span>
            <span class="skeleton-line skeleton-line-short"></span>
        </div>
        <canvas id="telemetryChart"></canvas>
    </div>
</section>
