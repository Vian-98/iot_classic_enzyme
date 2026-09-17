<?php
/**
 * Classic Enzyme IoT - Component: Metric Cards
 * 4 Kartu Sensor Utama: Suhu, pH, Alkohol (dengan indikator Halal Zone), dan Diagnostik
 */
?>
<section class="metric-grid">

    <!-- Card 1: Suhu -->
    <div class="glass glass-interactive sensor-card card-temp">
        <div class="sensor-header">
            <div class="sensor-meta">
                <span class="sensor-title">Suhu Bioreaktor</span>
                <span class="sensor-hardware">MAX6675 · Termokopel K</span>
            </div>
            <span class="sensor-badge badge-pink" id="badgeTemp">SUHU</span>
        </div>
        <div class="sensor-value-area">
            <span class="sensor-value" id="valTemp">--</span>
            <span class="sensor-unit">°C</span>
        </div>
        <div class="sensor-footer">
            <span>Ambang Batas</span>
            <span class="range-pill" id="footerTemp">20 – 40 °C</span>
        </div>
    </div>

    <!-- Card 2: pH -->
    <div class="glass glass-interactive sensor-card card-ph">
        <div class="sensor-header">
            <div class="sensor-meta">
                <span class="sensor-title">Derajat Keasaman</span>
                <span class="sensor-hardware">PH-110 · Probe Analog</span>
            </div>
            <span class="sensor-badge badge-teal" id="badgePh">pH</span>
        </div>
        <div class="sensor-value-area">
            <span class="sensor-value" id="valPh">--</span>
            <span class="sensor-unit">pH</span>
        </div>
        <div class="sensor-footer">
            <span>Ambang Batas</span>
            <span class="range-pill" id="footerPh">3.0 – 4.5</span>
        </div>
    </div>

    <!-- Card 3: Alkohol (MQ-3) -->
    <div class="glass glass-interactive sensor-card card-alcohol">
        <div class="sensor-header">
            <div class="sensor-meta">
                <span class="sensor-title">Uap Gas Alkohol</span>
                <span class="sensor-hardware">MQ-3 · Headspace Ethanol</span>
            </div>
            <span class="sensor-badge badge-amber" id="badgeAlcohol">MQ-3</span>
        </div>
        <div class="sensor-value-area">
            <span class="sensor-value" id="valAlcohol">--</span>
            <span class="sensor-unit">ADC</span>
        </div>
        <div class="sensor-footer">
            <span>Zona Fiqih / Halal</span>
            <span class="range-pill" id="footerAlcohol">Batas: ≤ 800 ADC</span>
        </div>
    </div>

    <!-- Card 4: Koneksi & Diagnostik -->
    <div class="glass glass-interactive sensor-card">
        <div class="sensor-header">
            <div class="sensor-meta">
                <span class="sensor-title">Koneksi &amp; RSSI</span>
                <span class="sensor-hardware" id="valFirmware">fw v1.0.0</span>
            </div>
            <span class="sensor-badge badge-slate">SINYAL</span>
        </div>
        <div class="sensor-value-area">
            <span class="sensor-value" id="valRssi" style="color:var(--text-main);">--</span>
            <span class="sensor-unit">dBm</span>
        </div>
        <div class="sensor-footer">
            <span>Kualitas</span>
            <span class="range-pill" id="statusChip">—</span>
        </div>
    </div>

</section>
