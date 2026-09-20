<?php
/**
 * Classic Enzyme IoT - Component: History Table (Live Log)
 * Tabel ringkas telemetri terkini dengan indikator downtime/gap
 */
?>
<section class="glass table-section">
    <div class="table-header">
        <div>
            <h2>Log Telemetri Terkini</h2>
            <p style="font-size:0.75rem; color:var(--text-muted); margin-top:3px;">Menampilkan 10 rekaman terakhir dari perangkat</p>
        </div>

        <!-- Filter Baris Data: Semua / Valid (Ada Data) / Offline -->
        <div class="device-status-filter" id="realtimeTableFilter" role="group" aria-label="Filter baris telemetri">
            <button class="filter-status-btn active" data-tablefilter="all" title="Tampilkan semua rekaman dan periode offline">
                Semua
            </button>
            <button class="filter-status-btn" data-tablefilter="valid" title="Hanya rekaman dengan data sensor valid">
                <span class="filter-dot online"></span>Valid (Ada Data)
            </button>
            <button class="filter-status-btn" data-tablefilter="offline" title="Hanya periode perangkat terputus / offline">
                <span class="filter-dot offline"></span>Offline
            </button>
        </div>

        <div class="btn-group">
            <button id="btnRefresh" class="glass-btn" title="Refresh data sekarang">Refresh</button>
        </div>
    </div>

    <!-- Mobile Card View List (< 640px) -->
    <div id="historyCardList" class="table-card-list">
        <div class="table-card-item loading-placeholder" aria-label="Memuat data telemetri" role="status">
            <span class="skeleton-line skeleton-line-wide"></span>
            <span class="skeleton-line"></span>
            <span class="skeleton-line skeleton-line-short"></span>
        </div>
    </div>

    <!-- Desktop Table View (>= 640px) -->
    <div class="table-responsive">
        <table class="glass-table">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Suhu</th>
                    <th>pH</th>
                    <th>Alkohol</th>
                    <th>WiFi RSSI</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="historyTableBody">
                <tr>
                    <td colspan="6" style="text-align:center; color:var(--text-muted); padding:24px;">
                        Memuat data telemetri...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</section>
