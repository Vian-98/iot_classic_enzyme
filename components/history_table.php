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
        <div class="btn-group">
            <button id="btnRefresh" class="glass-btn" title="Refresh data sekarang">Refresh</button>
            <button id="btnSimulate" class="glass-btn" title="Kirim data simulasi lokal">Simulasi Ingest</button>
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
