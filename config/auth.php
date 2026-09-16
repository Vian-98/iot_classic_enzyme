<?php
/**
 * Classic Enzyme IoT - Auth Helper
 * Session-based authentication untuk Admin Panel saja.
 * Dashboard publik (index.php, history.php) TIDAK memerlukan login.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 3600 * 8,   // Session 8 jam
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Cek apakah admin sudah login
 */
function isAdminLoggedIn(): bool {
    return !empty($_SESSION['admin_id']) && !empty($_SESSION['admin_name']);
}

/**
 * Wajib login — redirect ke login.php jika belum.
 * Hanya digunakan di admin.php dan api/admin/*.
 */
function requireAdmin(): void {
    if (!isAdminLoggedIn()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/admin.php');
        header("Location: /login.php?next={$redirect}");
        exit;
    }
}

/**
 * Logout: hancurkan session
 */
function adminLogout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
    session_destroy();
}
