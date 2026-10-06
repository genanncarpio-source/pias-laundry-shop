<?php
/**
 * Authentication & session bootstrap.
 * Require this at the top of every page that needs session access.
 */

if (session_status() === PHP_SESSION_NONE) {
    /*
     * Configure the session cookie so login works in two situations:
     *
     * 1. Local Laragon / localhost over HTTP  ->  normal cookie (SameSite=Lax).
     * 2. Behind a reverse proxy (the Arena live preview, served over HTTPS
     *    and embedded in an iframe on another origin) -> the cookie must be
     *    SameSite=None + Secure, otherwise the browser drops it and the
     *    login silently fails.
     */
    $https = false;
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $https = true;
    } elseif (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        $https = true;
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
              && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $https = true;
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_SSL'])
              && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        $https = true;
    } elseif (isset($_SERVER['HTTP_FORWARDED'])
              && stripos((string)$_SERVER['HTTP_FORWARDED'], 'proto=https') !== false) {
        $https = true;
    }

    if ($https) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'None',
        ]);
    } else {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

date_default_timezone_set('Asia/Manila');

/** Return the currently logged-in user row, or null. */
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

/** Require a logged-in user; redirect to login otherwise. */
function require_login(): void
{
    if (!current_user()) {
        redirect('index.php');
    }
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

/** Require an admin user; redirect with an error otherwise. */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        flash_set('error', 'You do not have permission to access that page.');
        redirect('dashboard.php');
    }
}
