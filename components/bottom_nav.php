<?php
/**
 * Classic Enzyme IoT - Component: Bottom Navigation Dock (Mobile-First)
 * Hanya tampil di viewport mobile (< 640px)
 * Menyediakan akses 1-tap ke Dashboard, Riwayat, dan Admin Panel
 */
if (!function_exists('isAdminLoggedIn')) {
    require_once __DIR__ . '/../config/auth.php';
}

$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$isDashboard = ($currentScript === 'index.php' || $currentScript === '');
$isHistory   = ($currentScript === 'history.php');
$isAdmin     = ($currentScript === 'admin.php' || $currentScript === 'login.php');

$adminUrl = isAdminLoggedIn() ? 'admin.php' : 'login.php';
?>
<nav class="bottom-nav" aria-label="Navigasi Utama Mobile">
    <a href="index.php" class="bottom-nav-item <?= $isDashboard ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
            <polyline points="9 22 9 12 15 12 15 22"></polyline>
        </svg>
        <span>Dashboard</span>
    </a>

    <a href="history.php" class="bottom-nav-item <?= $isHistory ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
        </svg>
        <span>Riwayat</span>
    </a>

    <a href="<?= $adminUrl ?>" class="bottom-nav-item <?= $isAdmin ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3"></circle>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
        </svg>
        <span>Admin</span>
    </a>
</nav>
