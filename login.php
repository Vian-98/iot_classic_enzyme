<?php
/**
 * Classic Enzyme IoT - Admin Login Page
 * Dashboard publik tetap bisa diakses tanpa login.
 * Halaman ini hanya untuk akses Admin Panel konfigurasi lanjutan.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

// Jika sudah login, langsung redirect ke admin panel
if (isAdminLoggedIn()) {
    header('Location: admin.php');
    exit;
}

$error   = '';
$nextUrl = htmlspecialchars(trim($_GET['next'] ?? 'admin.php'), ENT_QUOTES, 'UTF-8');
// Sanitasi next URL — hanya boleh path relatif
if (!preg_match('/^\/?(admin|index|history)\.php/', $nextUrl)) {
    $nextUrl = 'admin.php';
}

// Proses Login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Username dan password wajib diisi.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare('SELECT id, username, password FROM admins WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id']   = (int)$admin['id'];
            $_SESSION['admin_name'] = $admin['username'];
            header('Location: ' . $nextUrl);
            exit;
        } else {
            // Delay untuk mencegah brute-force
            sleep(1);
            $error = 'Username atau password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin Login — Classic Enzyme IoT</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }

        .login-card {
            width: 100%;
            max-width: 400px;
            padding: 36px 32px 32px;
        }

        .login-logo {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 28px;
        }

        .login-logo-icon {
            width: 52px;
            height: 52px;
            min-width: 52px;
            border-radius: 16px;
            background: var(--teal);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.03em;
        }

        .login-title {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.02em;
            line-height: 1.2;
        }

        .login-subtitle {
            font-size: 0.72rem;
            color: var(--text-muted);
            font-weight: 500;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-top: 2px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            height: 48px;
            background: rgba(8, 14, 24, 0.60);
            border: 1.5px solid rgba(255, 255, 255, 0.24);
            border-radius: var(--radius-md);
            color: var(--text-main);
            font-family: var(--font-sans);
            font-size: 0.95rem;
            font-weight: 500;
            padding: 0 16px;
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
            font-size: 0.88rem;
        }

        .login-error {
            background: var(--pink-soft);
            border: 1px solid var(--pink-border);
            color: var(--pink-dark);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            font-size: 0.82rem;
            font-weight: 600;
            margin-bottom: 18px;
        }

        .login-btn {
            width: 100%;
            height: 48px;
            background: var(--teal);
            color: #fff;
            border: none;
            border-radius: var(--radius-md);
            font-family: var(--font-sans);
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            letter-spacing: 0.02em;
            transition: opacity 0.2s ease, transform 0.15s ease;
            margin-top: 4px;
        }

        .login-btn:hover {
            opacity: 0.88;
        }

        .login-btn:active {
            transform: scale(0.97);
        }

        .login-back {
            display: block;
            text-align: center;
            margin-top: 18px;
            font-size: 0.8rem;
            color: var(--text-muted);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .login-back:hover {
            color: var(--teal);
        }

        .login-note {
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid var(--glass-border-2);
            font-size: 0.72rem;
            color: var(--text-subtle);
            text-align: center;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="ambient-mesh">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
        <div class="orb orb-4"></div>
    </div>

    <div class="login-wrapper">
        <div class="glass login-card">

            <div class="login-logo">
                <div class="login-logo-icon">CE</div>
                <div>
                    <div class="login-title">Admin Panel</div>
                    <div class="login-subtitle">Classic Enzyme IoT</div>
                </div>
            </div>

            <?php if ($error): ?>
            <div class="login-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php<?= $nextUrl !== 'admin.php' ? '?next=' . urlencode($nextUrl) : '' ?>">
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input
                        id="username"
                        type="text"
                        name="username"
                        class="form-input"
                        placeholder="admin"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        autocomplete="username"
                        autofocus
                        required
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        class="form-input"
                        placeholder="••••••••"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button type="submit" class="login-btn" id="loginBtn">Masuk ke Panel Admin</button>
            </form>

            <a href="index.php" class="login-back">Kembali ke Dashboard Publik</a>

            <div class="login-note">
                Halaman dashboard dapat diakses publik tanpa login.<br>
                Login hanya diperlukan untuk konfigurasi lanjutan.
            </div>
        </div>
    </div>

    <script>
        // Sinkronkan tema dari localStorage (agar tidak flash)
        const t = localStorage.getItem('ce_theme') || 'dark';
        document.documentElement.setAttribute('data-theme', t);
    </script>
</body>
</html>
