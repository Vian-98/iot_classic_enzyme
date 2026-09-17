<?php
/**
 * Classic Enzyme IoT - Component: Header Navbar
 * Teks brand murni, filter status device, selector perangkat, status live, dan simbol toggle tema
 */
?>
<header class="navbar-dock glass">
    <div class="brand-section">
        <a href="index.php" style="text-decoration:none; display:flex; align-items:center;">
            <div>
                <h1 class="brand-title">Classic Enzyme</h1>
                <div class="brand-subtitle">IoT Fermentation Monitor</div>
            </div>
        </a>
    </div>

    <div class="dock-controls">

        <!-- Filter Status Device (Semua / Online / Offline) -->
        <div class="device-status-filter" id="deviceStatusFilter" role="group" aria-label="Filter status perangkat">
            <button class="filter-status-btn active" data-status="all" title="Tampilkan semua perangkat">Semua</button>
            <button class="filter-status-btn" data-status="online" title="Tampilkan perangkat online saja">
                <span class="filter-dot online"></span>Online
            </button>
            <button class="filter-status-btn" data-status="offline" title="Tampilkan perangkat offline saja">
                <span class="filter-dot offline"></span>Offline
            </button>
        </div>

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

        <div id="statusPill" class="status-pill offline" title="Status Koneksi Perangkat">
            <div style="display:flex; align-items:center; gap:7px; flex-shrink:0;">
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
        <button id="themeToggleBtn" class="glass-btn glass-btn-icon" aria-label="Toggle Theme" title="Beralih Tema (Gelap/Cerah)">
            <span id="themeIcon" style="display:inline-flex; align-items:center; justify-content:center;">
                <!-- SVG Icon diisi secara dinamis oleh app.js -->
            </span>
        </button>
    </div>
</header>
