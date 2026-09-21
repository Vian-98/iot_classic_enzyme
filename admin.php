<?php
/**
 * Classic Enzyme IoT - Admin Panel
 * Konfigurasi lanjutan: Threshold Alarm, Device, Alarm Management.
 * Halaman ini WAJIB LOGIN. Dashboard publik (index.php, history.php) tidak terpengaruh.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
requireAdmin();

$db       = getDB();
$adminName = htmlspecialchars($_SESSION['admin_name']);
$offlineTimeout = max(30, (int)getSetting('offline_timeout_seconds', 300));
$flash     = '';
$flashType = 'ok';

// -----------------------------------------------------------------------
// Proses aksi POST (Threshold save, Alarm ack, Change Password)
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    // --- Simpan Pengaturan Sistem (Batas Waktu Offline) ---
    if ($action === 'update_settings') {
        $offlineTimeout = max(30, (int)($_POST['offline_timeout_seconds'] ?? 300));
        setSetting('offline_timeout_seconds', (string)$offlineTimeout);
        $flash = 'Batas waktu toleransi offline berhasil diperbarui menjadi ' . ($offlineTimeout >= 60 ? floor($offlineTimeout / 60) . ' menit' : $offlineTimeout . ' detik') . '.';
    }

    // --- Simpan Threshold ---
    if ($action === 'save_threshold') {
        $deviceId = trim($_POST['device_id'] ?? 'esp32-ce-001');
        $params = ['temp', 'ph', 'alcohol'];
        foreach ($params as $param) {
            $minKey = "min_{$param}";
            $maxKey = "max_{$param}";
            $valMin = isset($_POST[$minKey]) && $_POST[$minKey] !== '' ? (float)$_POST[$minKey] : null;
            $valMax = isset($_POST[$maxKey]) && $_POST[$maxKey] !== '' ? (float)$_POST[$maxKey] : null;

            $stmt = $db->prepare("INSERT INTO thresholds (device_id, param, val_min, val_max)
                VALUES (?, ?, ?, ?)
                ON CONFLICT (device_id, param) DO UPDATE
                SET val_min = EXCLUDED.val_min, val_max = EXCLUDED.val_max, updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([$deviceId, $param, $valMin, $valMax]);
        }
        $flash = 'Threshold berhasil disimpan.';
    }

    // --- Acknowledge Alarm ---
    if ($action === 'ack_alarm') {
        $alarmId = (int)($_POST['alarm_id'] ?? 0);
        if ($alarmId > 0) {
        $stmt = $db->prepare("UPDATE alarms SET acknowledged = 1, ack_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$alarmId]);
            $flash = 'Alarm #' . $alarmId . ' ditandai selesai.';
        }
    }

    // --- Ack Semua Alarm ---
    if ($action === 'ack_all') {
        $db->exec("UPDATE alarms SET acknowledged = 1, ack_at = CURRENT_TIMESTAMP WHERE acknowledged = 0");
        $flash = 'Semua alarm aktif ditandai selesai.';
    }

    // --- Ganti Password ---
    if ($action === 'change_password') {
        $oldPass  = $_POST['old_password'] ?? '';
        $newPass  = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        $stmt = $db->prepare('SELECT password FROM admins WHERE id = ?');
        $stmt->execute([$_SESSION['admin_id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($oldPass, $row['password'])) {
            $flash = 'Password lama tidak sesuai.';
            $flashType = 'err';
        } elseif (strlen($newPass) < 6) {
            $flash = 'Password baru minimal 6 karakter.';
            $flashType = 'err';
        } elseif ($newPass !== $confirm) {
            $flash = 'Konfirmasi password tidak cocok.';
            $flashType = 'err';
        } else {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $stmt = $db->prepare('UPDATE admins SET password = ? WHERE id = ?');
            $stmt->execute([$newHash, $_SESSION['admin_id']]);
            $flash = 'Password berhasil diubah.';
        }
    }
}

// -----------------------------------------------------------------------
// Ambil data untuk tampilan
// -----------------------------------------------------------------------

// Devices dengan IP Pengirim Terakhir
$devices = $db->query("
    SELECT d.*, 
           (SELECT t.ip_address FROM telemetry t WHERE t.device_id = d.device_id ORDER BY t.received_at DESC, t.id DESC LIMIT 1) AS last_ip
    FROM devices d 
    ORDER BY d.id ASC
")->fetchAll();

// Thresholds (device pertama saja untuk V1)
$thresholdRows = $db->query("SELECT * FROM thresholds WHERE device_id = 'esp32-ce-001'")->fetchAll();
$thresholds = [];
foreach ($thresholdRows as $r) { $thresholds[$r['param']] = $r; }

// Alarm aktif
$alarmsActive = $db->query("SELECT * FROM alarms WHERE acknowledged = 0 ORDER BY triggered_at DESC LIMIT 50")->fetchAll();

// Alarm riwayat (ack)
$alarmsHistory = $db->query("SELECT * FROM alarms WHERE acknowledged = 1 ORDER BY ack_at DESC LIMIT 30")->fetchAll();

// Admins
$admins = $db->query("SELECT id, username, created_at FROM admins ORDER BY id ASC")->fetchAll();

?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin Panel — Classic Enzyme IoT</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* ---- Admin Layout ---- */
        .admin-layout { display: flex; flex-direction: column; gap: 0; }

        .admin-tabs {
            display: flex;
            gap: 4px;
            padding: 4px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 9999px;
            backdrop-filter: blur(12px);
            overflow-x: auto;
            scrollbar-width: none;
        }
        .admin-tabs::-webkit-scrollbar { display: none; }

        .admin-tab-btn {
            flex: 1;
            min-height: 38px;
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 0 16px;
            border-radius: 9999px;
            font-family: var(--font-sans);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s ease;
        }
        .admin-tab-btn.active {
            background: var(--teal);
            color: var(--accent-on-teal);
            font-weight: 700;
        }

        .admin-panel { display: none; }
        .admin-panel.active { display: block; }

        /* ---- Form Elements ---- */
        .form-section { padding: 22px 20px; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
            margin-bottom: 16px;
        }
        @media (min-width: 600px) {
            .form-row { grid-template-columns: 1fr 1fr 1fr; }
        }

        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-label {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .form-input {
            height: 44px;
            background: rgba(8, 14, 24, 0.60);
            border: 1.5px solid rgba(255, 255, 255, 0.24);
            border-radius: var(--radius-sm);
            color: var(--text-main);
            font-family: var(--font-sans);
            font-size: 0.9rem;
            font-weight: 500;
            padding: 0 14px;
            outline: none;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.25);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }
        [data-theme="light"] .form-input {
            background: #ffffff;
            border: 1.5px solid rgba(78, 191, 193, 0.50);
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.08);
            color: #1a2332;
        }
        .form-input:hover {
            border-color: rgba(255, 255, 255, 0.42);
        }
        [data-theme="light"] .form-input:hover {
            border-color: var(--teal);
        }
        .form-input:focus {
            border-color: var(--teal) !important;
            box-shadow: 0 0 0 3px var(--teal-soft), inset 0 1px 2px rgba(0, 0, 0, 0.15) !important;
            background: rgba(8, 14, 24, 0.85);
        }
        [data-theme="light"] .form-input:focus {
            background: #ffffff;
        }
        .form-input::placeholder {
            color: var(--text-subtle);
            font-size: 0.84rem;
        }
        .form-hint {
            font-size: 0.7rem;
            color: var(--text-subtle);
            margin-top: 2px;
        }

        .account-card {
            width: 100%;
            max-width: 460px;
            box-sizing: border-box;
            background: rgba(8, 14, 24, 0.45);
            border: 1.5px solid rgba(255, 255, 255, 0.16);
            border-radius: var(--radius-md);
            padding: 24px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        }
        [data-theme="light"] .account-card {
            background: rgba(255, 255, 255, 0.75);
            border: 1.5px solid rgba(78, 191, 193, 0.35);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
        }

        .btn-primary {
            height: 42px;
            padding: 0 20px;
            background: var(--teal);
            color: var(--accent-on-teal);
            border: none;
            border-radius: var(--radius-sm);
            font-family: var(--font-sans);
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: opacity 0.2s ease;
        }
        .btn-primary:hover { opacity: 0.85; }

        .btn-danger {
            height: 36px;
            padding: 0 14px;
            background: var(--pink-soft);
            color: var(--pink-dark);
            border: 1px solid var(--pink-border);
            border-radius: var(--radius-sm);
            font-family: var(--font-sans);
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .btn-danger:hover { background: var(--pink); color: var(--accent-on-pink); }

        .btn-teal-outline {
            height: 36px;
            padding: 0 14px;
            background: var(--teal-soft);
            color: var(--teal-text);
            border: 1px solid var(--teal-border);
            border-radius: var(--radius-sm);
            font-family: var(--font-sans);
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .btn-teal-outline:hover { background: var(--teal); color: var(--accent-on-teal); }

        /* ---- Flash Message ---- */
        .flash-ok {
            background: var(--teal-soft);
            border: 1px solid var(--teal-border);
            color: var(--teal-dark);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            font-size: 0.82rem;
            font-weight: 600;
            margin-bottom: 18px;
        }
        .flash-err {
            background: var(--pink-soft);
            border: 1px solid var(--pink-border);
            color: var(--pink-text);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            font-size: 0.82rem;
            font-weight: 600;
            margin-bottom: 18px;
        }

        /* ---- Stat Cards ---- */
        .stat-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-md);
            padding: 14px 16px;
        }
        .stat-label { font-size: 0.7rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-value { font-size: 1.8rem; font-weight: 800; font-family: var(--font-mono); margin: 4px 0 0; line-height: 1; }

        /* ---- Section Header ---- */
        .section-hdr {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }
        .section-title {
            font-size: 0.95rem;
            font-weight: 700;
        }

        /* Threshold panel: compact grouping so the controls stay scannable. */
        .threshold-panel .form-section { padding: 18px 20px; }
        .threshold-panel .threshold-intro { margin: 0 0 14px; font-size: 0.75rem; line-height: 1.4; }
        .threshold-panel .timeout-card {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding: 12px 14px; margin-bottom: 18px; background: var(--glass-bg);
            border: 1px solid var(--glass-border); border-radius: var(--radius-sm);
        }
        .threshold-panel .timeout-copy { min-width: 0; }
        .threshold-panel .timeout-copy strong { display: block; font-size: 0.78rem; color: var(--teal-text); }
        .threshold-panel .timeout-copy span { display: block; margin-top: 3px; font-size: 0.68rem; color: var(--text-muted); }
        .threshold-panel .timeout-form { display: flex; align-items: end; gap: 8px; flex-shrink: 0; }
        .threshold-panel .timeout-form .form-input { width: 112px; height: 38px; }
        .threshold-panel .timeout-form .form-hint { display: none; }
        .threshold-panel .sensor-threshold {
            padding: 12px 0 14px 12px; margin-bottom: 10px;
            border-left: 3px solid var(--teal); border-bottom: 1px solid var(--glass-border);
        }
        .threshold-panel .sensor-threshold:last-of-type { border-bottom: 0; }
        .threshold-panel .sensor-threshold-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 8px; }
        .threshold-panel .sensor-threshold-title { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
        .threshold-panel .sensor-threshold-current { font-size: 0.68rem; color: var(--text-muted); text-align: right; }
        .threshold-panel .sensor-threshold .form-row { gap: 10px; margin-bottom: 0; }
        .threshold-panel .sensor-threshold .form-hint { display: none; }
        .threshold-panel .threshold-submit { display: flex; justify-content: flex-end; margin-top: 6px; }
        @media (max-width: 600px) {
            .threshold-panel .timeout-card { align-items: stretch; flex-direction: column; gap: 10px; }
            .threshold-panel .timeout-form { width: 100%; }
            .threshold-panel .timeout-form .form-group { flex: 1; }
            .threshold-panel .timeout-form .form-input { width: 100%; }
            .threshold-panel .sensor-threshold-head { align-items: flex-start; flex-direction: column; gap: 4px; }
            .threshold-panel .sensor-threshold-current { text-align: left; }
        }

        /* ---- Severity badge ---- */
        .sev-critical { background: var(--pink-soft); color: var(--pink-text); border: 1px solid var(--pink-border); border-radius: 9999px; padding: 2px 8px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; }
        .sev-warning  { background: rgba(251,191,36,0.15); color: #b45309; border: 1px solid rgba(251,191,36,0.3); border-radius: 9999px; padding: 2px 8px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; }
        .sev-info     { background: var(--teal-soft); color: var(--teal-text); border: 1px solid var(--teal-border); border-radius: 9999px; padding: 2px 8px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; }

        .device-status-online {
            display: inline-block;
            background: var(--teal-soft);
            color: var(--teal-text);
            border: 1px solid var(--teal-border);
            border-radius: 9999px;
            padding: 2px 10px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.04em;
        }
        .device-status-offline {
            display: inline-block;
            background: var(--pink-soft);
            color: var(--pink-text);
            border: 1px solid var(--pink-border);
            border-radius: 9999px;
            padding: 2px 10px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.04em;
        }
    </style>
</head>
<body data-page="admin">
    <div class="ambient-mesh">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
        <div class="orb orb-4"></div>
    </div>

    <div class="app-wrapper">

        <!-- Navbar -->
        <header class="navbar-dock glass">
            <!-- Row 1: Brand & Actions -->
            <div class="navbar-row-1">
                <div class="brand-section">
                    <a href="admin.php" style="text-decoration:none; display:flex; align-items:center;">
                        <div>
                            <h1 class="brand-title">Admin Panel</h1>
                            <div class="brand-subtitle">Konfigurasi Lanjutan</div>
                        </div>
                    </a>
                </div>
                <div class="navbar-row-1-actions">
                    <a href="index.php" class="glass-btn nav-desktop-only">Dashboard</a>
                    <a href="history.php" class="glass-btn nav-desktop-only">Riwayat</a>
                    <span class="status-pill nav-desktop-only" style="width:auto; min-width:0; padding: 0 14px; gap:8px;">
                        <span style="font-size:0.75rem; color:var(--text-muted);">Admin:</span>
                        <span style="font-size:0.8rem; font-weight:700; color:var(--teal-text);"><?= $adminName ?></span>
                    </span>
                    <a href="logout.php" class="glass-btn nav-desktop-only" style="color:var(--pink-text); border-color:var(--pink-border);">Logout</a>
                    <button id="themeToggleBtn" class="glass-btn glass-btn-icon" aria-label="Toggle Theme" title="Beralih Tema">
                        <span id="themeIcon" style="display:inline-flex; align-items:center; justify-content:center;"></span>
                    </button>
                </div>
            </div>
            <!-- Row 2: Admin info + Logout (mobile) -->
            <div class="navbar-row-2">
                <span class="status-pill" style="width:auto; min-width:0; padding: 0 14px; gap:8px; flex:1;">
                    <span style="font-size:0.75rem; color:var(--text-muted);">Admin:</span>
                    <span style="font-size:0.8rem; font-weight:700; color:var(--teal-text);"><?= $adminName ?></span>
                </span>
                <a href="logout.php" class="glass-btn" style="color:var(--pink-text); border-color:var(--pink-border);">Logout</a>
            </div>
        </header>

        <?php if ($flash): ?>
        <div class="flash-<?= $flashType ?>">
            <?= htmlspecialchars($flash) ?>
        </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="glass" style="padding: 6px;">
            <div class="admin-tabs" id="adminTabs">
                <button class="admin-tab-btn active" data-tab="tab-threshold">Threshold Alarm</button>
                <button class="admin-tab-btn" data-tab="tab-alarms">
                    Alarm Aktif
                    <?php if (count($alarmsActive)): ?>
                    <span style="margin-left:4px; background:var(--pink); color:var(--accent-on-pink); border-radius:9999px; padding:1px 7px; font-size:0.65rem;"><?= count($alarmsActive) ?></span>
                    <?php endif; ?>
                </button>
                <button class="admin-tab-btn" data-tab="tab-devices">Devices</button>
                <button class="admin-tab-btn" data-tab="tab-account">Akun</button>
            </div>
        </div>

        <!-- ================================================================
             TAB 1: THRESHOLD ALARM
        ================================================================ -->
        <section class="glass admin-panel active threshold-panel" id="tab-threshold">
            <div class="form-section">
                <div class="section-hdr">
                    <div class="section-title">Batas Threshold Sensor</div>
                    <div style="font-size:0.72rem; color:var(--text-muted);">Device: esp32-ce-001</div>
                </div>
                <p class="threshold-intro" style="color:var(--text-muted);">
                    Tentukan rentang ideal sensor. Nilai ini dipakai Dashboard dan alarm otomatis.
                </p>

                <div class="timeout-card">
                    <div class="timeout-copy">
                        <strong>Timeout status device</strong>
                        <span>Device offline jika tidak ada telemetry melewati batas ini.</span>
                    </div>
                    <form method="POST" class="timeout-form">
                        <input type="hidden" name="_action" value="update_settings">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" for="offline_timeout_seconds">Batas offline (detik)</label>
                            <input id="offline_timeout_seconds" type="number" name="offline_timeout_seconds" class="form-input" min="30" max="86400" step="1" required value="<?= htmlspecialchars((string)$offlineTimeout) ?>">
                            <div class="form-hint">Contoh: 300 = 5 menit, 900 = 15 menit.</div>
                        </div>
                        <button type="submit" class="glass-btn" style="background:var(--teal); color:var(--accent-on-teal); font-weight:700;">Simpan Timeout</button>
                    </form>
                </div>

                <form method="POST" class="threshold-form">
                    <input type="hidden" name="_action" value="save_threshold">
                    <input type="hidden" name="device_id" value="esp32-ce-001">

                    <!-- Suhu -->
                    <div class="sensor-threshold" style="border-left-color:var(--pink);">
                        <div class="sensor-threshold-head">
                            <div class="sensor-threshold-title" style="color:var(--pink-text);">Suhu Fermentasi</div>
                            <span class="sensor-threshold-current">Dashboard: <strong><?= ($thresholds['temp']['val_min'] ?? '20') . ' – ' . ($thresholds['temp']['val_max'] ?? '40') ?> °C</strong></span>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Min (°C)</label>
                                <input type="number" step="0.1" name="min_temp" class="form-input"
                                    value="<?= $thresholds['temp']['val_min'] ?? '' ?>"
                                    placeholder="Cth: 30.0">
                                <div class="form-hint">Di bawah batas ini → Alarm Suhu Rendah</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Max (°C)</label>
                                <input type="number" step="0.1" name="max_temp" class="form-input"
                                    value="<?= $thresholds['temp']['val_max'] ?? '' ?>"
                                    placeholder="Cth: 38.0">
                                <div class="form-hint">Di atas batas ini → Alarm Suhu Berlebih</div>
                            </div>
                        </div>
                    </div>

                    <!-- pH -->
                    <div class="sensor-threshold">
                        <div class="sensor-threshold-head">
                            <div class="sensor-threshold-title" style="color:var(--teal-text);">Keasaman pH</div>
                            <span class="sensor-threshold-current">Dashboard: <strong><?= ($thresholds['ph']['val_min'] ?? '3.0') . ' – ' . ($thresholds['ph']['val_max'] ?? '4.5') ?> pH</strong></span>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Min pH</label>
                                <input type="number" step="0.1" name="min_ph" class="form-input"
                                    value="<?= $thresholds['ph']['val_min'] ?? '' ?>"
                                    placeholder="Cth: 3.2">
                                <div class="form-hint">Di bawah batas ini → Alarm Terlalu Asam</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Max pH</label>
                                <input type="number" step="0.1" name="max_ph" class="form-input"
                                    value="<?= $thresholds['ph']['val_max'] ?? '' ?>"
                                    placeholder="Cth: 4.5">
                                <div class="form-hint">Di atas batas ini → Alarm Terlalu Basa</div>
                            </div>
                        </div>
                    </div>

                    <!-- Alkohol -->
                    <div class="sensor-threshold" style="border-left-color:var(--violet);">
                        <div class="sensor-threshold-head">
                            <div class="sensor-threshold-title" style="color:var(--violet-text);">Uap Gas Alkohol</div>
                            <?php
                                $hasAlcMin = isset($thresholds['alcohol']['val_min']) && $thresholds['alcohol']['val_min'] !== null && $thresholds['alcohol']['val_min'] !== '';
                                $hasAlcMax = isset($thresholds['alcohol']['val_max']) && $thresholds['alcohol']['val_max'] !== null && $thresholds['alcohol']['val_max'] !== '';
                                if ($hasAlcMin && $hasAlcMax) {
                                    $dashAlc = $thresholds['alcohol']['val_min'] . ' – ' . $thresholds['alcohol']['val_max'] . ' ADC';
                                } elseif ($hasAlcMax) {
                                    $dashAlc = '≤ ' . $thresholds['alcohol']['val_max'] . ' ADC';
                                } elseif ($hasAlcMin) {
                                    $dashAlc = '≥ ' . $thresholds['alcohol']['val_min'] . ' ADC';
                                } else {
                                    $dashAlc = '≤ 800 ADC (Default)';
                                }
                            ?>
                            <span class="sensor-threshold-current">Dashboard: <strong><?= htmlspecialchars($dashAlc) ?></strong></span>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Min ADC <span style="font-weight:400; text-transform:none;">(opsional)</span></label>
                                <input type="number" step="1" name="min_alcohol" class="form-input"
                                    value="<?= $thresholds['alcohol']['val_min'] ?? '' ?>"
                                    placeholder="Kosongkan jika tidak ada batas bawah">
                                <div class="form-hint">Opsional (umumnya kosong untuk gas fermentasi)</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Max ADC</label>
                                <input type="number" step="1" name="max_alcohol" class="form-input"
                                    value="<?= $thresholds['alcohol']['val_max'] ?? '' ?>"
                                    placeholder="Cth: 500">
                                <div class="form-hint">Melebihi batas ini → Alarm Gas Terlalu Tinggi</div>
                            </div>
                        </div>
                    </div>

                    <div class="threshold-submit"><button type="submit" class="btn-primary">Simpan Threshold</button></div>
                </form>
            </div>
        </section>

        <!-- ================================================================
             TAB 2: ALARM AKTIF
        ================================================================ -->
        <section class="glass admin-panel" id="tab-alarms">
            <div class="form-section">
                <div class="section-hdr">
                    <div class="section-title">Alarm Aktif (<?= count($alarmsActive) ?>)</div>
                    <?php if (count($alarmsActive) > 0): ?>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="_action" value="ack_all">
                        <button type="submit" class="btn-teal-outline">Selesaikan Semua</button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if (empty($alarmsActive)): ?>
                <div style="text-align:center; color:var(--text-muted); padding:32px 0; font-size:0.85rem;">
                    Tidak ada alarm aktif. Semua sistem normal.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="glass-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Waktu</th>
                                <th>Tipe</th>
                                <th>Severity</th>
                                <th>Nilai</th>
                                <th>Pesan</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alarmsActive as $alarm): ?>
                            <tr>
                                <td style="font-family:var(--font-mono); font-size:0.75rem;">#<?= $alarm['id'] ?></td>
                                <td style="font-size:0.75rem; color:var(--text-muted); font-family:var(--font-mono);"><?= htmlspecialchars($alarm['triggered_at']) ?></td>
                                <td style="font-weight:600; font-size:0.78rem;"><?= htmlspecialchars($alarm['alarm_type']) ?></td>
                                <td><span class="sev-<?= htmlspecialchars($alarm['severity']) ?>"><?= htmlspecialchars($alarm['severity']) ?></span></td>
                                <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--pink-text);">
                                    <?= $alarm['actual_val'] !== null ? htmlspecialchars($alarm['actual_val']) : '--' ?>
                                </td>
                                <td style="font-size:0.75rem; max-width:220px;"><?= htmlspecialchars($alarm['message']) ?></td>
                                <td>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="_action" value="ack_alarm">
                                        <input type="hidden" name="alarm_id" value="<?= (int)$alarm['id'] ?>">
                                        <button type="submit" class="btn-teal-outline">Selesai</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if (!empty($alarmsHistory)): ?>
                <div style="margin-top:28px;">
                    <div class="section-hdr"><div class="section-title" style="color:var(--text-muted);">Riwayat Alarm (<?= count($alarmsHistory) ?>)</div></div>
                    <div class="table-responsive">
                        <table class="glass-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Waktu Trigger</th>
                                    <th>Tipe</th>
                                    <th>Severity</th>
                                    <th>Nilai</th>
                                    <th>Diselesaikan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alarmsHistory as $alarm): ?>
                                <tr style="opacity:0.65;">
                                    <td style="font-size:0.73rem;">#<?= $alarm['id'] ?></td>
                                    <td style="font-size:0.73rem; font-family:var(--font-mono);"><?= htmlspecialchars($alarm['triggered_at']) ?></td>
                                    <td style="font-size:0.75rem;"><?= htmlspecialchars($alarm['alarm_type']) ?></td>
                                    <td><span class="sev-<?= htmlspecialchars($alarm['severity']) ?>"><?= htmlspecialchars($alarm['severity']) ?></span></td>
                                    <td style="font-size:0.73rem;"><?= $alarm['actual_val'] ?? '--' ?></td>
                                    <td style="font-size:0.73rem; color:var(--teal-text); font-family:var(--font-mono);"><?= htmlspecialchars($alarm['ack_at'] ?? '--') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ================================================================
             TAB 3: DEVICES
        ================================================================ -->
        <section class="glass admin-panel" id="tab-devices">
            <div class="form-section">
                <div class="section-hdr">
                    <div class="section-title">Device Terdaftar (<?= count($devices) ?>)</div>
                </div>
                <div class="table-responsive">
                    <table class="glass-table">
                        <thead>
                            <tr>
                                <th>Device ID</th>
                                <th>Nama</th>
                                <th>Lokasi</th>
                                <th>IP Pengirim (Origin)</th>
                                <th>API Key</th>
                                <th>Status</th>
                                <th>Terakhir Online</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($devices as $dev): ?>
                            <tr data-device-id="<?= htmlspecialchars($dev['device_id']) ?>">
                                <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--teal-text);"><?= htmlspecialchars($dev['device_id']) ?></td>
                                <td style="font-weight:600; font-size:0.82rem;"><?= htmlspecialchars($dev['device_name']) ?></td>
                                <td style="font-size:0.78rem; color:var(--text-muted);"><?= htmlspecialchars($dev['location']) ?></td>
                                <td style="font-family:var(--font-mono); font-size:0.76rem; color:var(--pink-text); font-weight:600;">
                                    <?= htmlspecialchars($dev['last_ip'] ?? 'Belum ada') ?>
                                </td>
                                <td>
                                    <span style="font-family:var(--font-mono); font-size:0.7rem; background:var(--glass-bg); border:1px solid var(--glass-border); padding:3px 8px; border-radius:6px; user-select:all;">
                                        <?= !empty($dev['api_key_hash']) ? '•••••••• (tersimpan aman)' : 'Perlu rotasi ke format hash' ?>
                                    </span>
                                </td>
                                <?php 
                                    $devLastSeen = $dev['last_seen'];
                                    $devDiff = $devLastSeen ? (time() - strtotime($devLastSeen)) : null;
                                    $isDevOnline = ($devDiff !== null && $devDiff <= $offlineTimeout);
                                ?>
                                <td>
                                    <span class="admin-device-status device-status-<?= $isDevOnline ? 'online' : 'offline' ?>">
                                        <?= $isDevOnline ? 'ONLINE' : 'OFFLINE' ?>
                                    </span>
                                </td>
                                <td class="admin-device-last-seen" style="font-size:0.75rem; font-family:var(--font-mono); color:var(--text-muted);">
                                    <?= $dev['last_seen'] ? htmlspecialchars(formatRelativeTime($dev['last_seen'])) : 'Belum pernah' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p style="margin-top:16px; font-size:0.73rem; color:var(--text-subtle); line-height:1.5;">
                    Untuk menambah device baru, gunakan <code>device_id</code> dan API key unik. Nilai key hanya boleh diberikan saat provisioning/rotasi dan tidak ditampilkan ulang di panel.
                </p>
            </div>
        </section>

        <!-- ================================================================
             TAB 4: AKUN ADMIN
        ================================================================ -->
        <section class="glass admin-panel" id="tab-account">
            <div class="form-section">
                <div class="section-hdr">
                    <div class="section-title">Ganti Password Admin</div>
                </div>
                <div class="account-card">
                    <form method="POST">
                        <input type="hidden" name="_action" value="change_password">
                        <div class="form-group" style="margin-bottom:16px;">
                            <label class="form-label">Password Lama</label>
                            <input type="password" name="old_password" class="form-input" required autocomplete="current-password" placeholder="Masukkan password lama saat ini...">
                            <div class="form-hint">Verifikasi kredensial akun Anda</div>
                        </div>
                        <div class="form-group" style="margin-bottom:16px;">
                            <label class="form-label">Password Baru (min. 6 karakter)</label>
                            <input type="password" name="new_password" class="form-input" required autocomplete="new-password" placeholder="Ketik password baru minimal 6 karakter...">
                            <div class="form-hint">Gunakan kombinasi huruf & angka</div>
                        </div>
                        <div class="form-group" style="margin-bottom:22px;">
                            <label class="form-label">Konfirmasi Password Baru</label>
                            <input type="password" name="confirm_password" class="form-input" required autocomplete="new-password" placeholder="Ulangi password baru untuk konfirmasi...">
                        </div>
                        <button type="submit" class="btn-primary" style="width:100%;">Ubah Password</button>
                    </form>
                </div>

                <div style="margin-top:28px; padding-top:22px; border-top:1px solid var(--glass-border-2);">
                    <div class="section-title" style="margin-bottom:14px;">Admin Terdaftar</div>
                    <div class="table-responsive">
                        <table class="glass-table">
                            <thead><tr><th>ID</th><th>Username</th><th>Dibuat</th></tr></thead>
                            <tbody>
                                <?php foreach ($admins as $a): ?>
                                <tr>
                                    <td>#<?= $a['id'] ?></td>
                                    <td style="font-weight:600; color:var(--teal-text);"><?= htmlspecialchars($a['username']) ?></td>
                                    <td style="font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars($a['created_at']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <footer class="footer-bar glass">
            <div><strong>Classic Enzyme IoT</strong> · Admin Panel</div>
            <div style="font-size:0.73rem; color:var(--text-muted);">Login sebagai <strong style="color:var(--teal-text);"><?= $adminName ?></strong></div>
        </footer>

    </div><!-- /.app-wrapper -->

    <script>
        // Theme toggle — gunakan ikon dan state yang sama dengan dashboard.
        const theme = localStorage.getItem('ce_theme') || 'dark';
        const icon = document.getElementById('themeIcon');

        function applyTheme(nextTheme) {
            document.documentElement.setAttribute('data-theme', nextTheme);
            localStorage.setItem('ce_theme', nextTheme);

            if (!icon) return;
            icon.innerHTML = nextTheme === 'dark'
                ? '<svg class="icon-theme" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>'
                : '<svg class="icon-theme" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>';
        }

        applyTheme(theme);

        document.getElementById('themeToggleBtn')?.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme') || 'dark';
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });

        // Tab switcher
        const tabs    = document.querySelectorAll('.admin-tab-btn');
        const panels  = document.querySelectorAll('.admin-panel');
        const hashMap = { '#threshold': 'tab-threshold', '#alarms': 'tab-alarms', '#devices': 'tab-devices', '#account': 'tab-account' };

        function switchTab(targetId) {
            tabs.forEach(b => b.classList.toggle('active', b.dataset.tab === targetId));
            panels.forEach(p => p.classList.toggle('active', p.id === targetId));
            history.replaceState(null, '', '#' + targetId.replace('tab-', ''));
        }

        tabs.forEach(btn => btn.addEventListener('click', () => switchTab(btn.dataset.tab)));

        // Restore tab from hash
        const initTab = hashMap[location.hash] || 'tab-threshold';
        switchTab(initTab);

        // Sinkronkan status device dan timeout yang sama dengan dashboard/API.
        async function refreshAdminDeviceStatus() {
            try {
                const response = await fetch('api/devices.php', { cache: 'no-store', headers: { 'Accept': 'application/json' } });
                const payload = await response.json();
                if (!response.ok || payload.status !== 'ok') return;
                (payload.devices || []).forEach((device) => {
                    const row = document.querySelector(`tr[data-device-id="${CSS.escape(device.device_id)}"]`);
                    if (!row) return;
                    const status = row.querySelector('.admin-device-status');
                    const lastSeen = row.querySelector('.admin-device-last-seen');
                    if (status) {
                        status.textContent = device.is_online ? 'ONLINE' : 'OFFLINE';
                        status.classList.toggle('online', device.is_online);
                        status.classList.toggle('offline', !device.is_online);
                    }
                    if (lastSeen) lastSeen.textContent = device.last_seen ? device.relative_time : 'Belum pernah';
                });
            } catch (error) {
                console.warn('Gagal menyinkronkan status device admin:', error);
            }
        }
        refreshAdminDeviceStatus();
        window.setInterval(refreshAdminDeviceStatus, 10000);

        // Auto-focus flash message if present
        const flash = document.querySelector('.flash-ok, .flash-err');
        if (flash) flash.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    </script>
    <?php include __DIR__ . '/components/bottom_nav.php'; ?>
</body>
</html>
