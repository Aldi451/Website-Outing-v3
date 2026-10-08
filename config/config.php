<?php
/**
 * ==========================================================================
 *  OUTING MANAGEMENT SYSTEM — config/config.php
 * --------------------------------------------------------------------------
 *  Konstanta aplikasi, base URL otomatis, session hardening, error handling.
 *  File ini WAJIB di-include paling awal oleh setiap halaman (via includes/init.php).
 *
 *  Kredit database berada di file TERPISAH: config/database.php
 * ==========================================================================
 */

defined('APP_STARTED') or define('APP_STARTED', true);

/* --------------------------------------------------------------------------
 * 1. Konstanta aplikasi
 * -------------------------------------------------------------------------- */
define('APP_NAME', 'Outing Management System');
define('APP_SHORT', 'OMS');
define('APP_VERSION', '3.0.0');

/** Set true hanya saat development. DI PRODUKSI HARUS false. */
define('APP_DEBUG', false);

/** Zona waktu (InfinityFree biasanya UTC, kita kunci ke WIB). */
date_default_timezone_set('Asia/Jakarta');

/* --------------------------------------------------------------------------
 * 2. Error reporting
 * -------------------------------------------------------------------------- */
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('log_errors', '1');
}

/* --------------------------------------------------------------------------
 * 3. Path aplikasi
 * -------------------------------------------------------------------------- */
define('ROOT_PATH', dirname(__DIR__));                 // .../public_html
define('CONFIG_PATH', ROOT_PATH . '/config');
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('LIBS_PATH', ROOT_PATH . '/libs');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('OUTING_UPLOAD_PATH', UPLOAD_PATH . '/outing');

/* --------------------------------------------------------------------------
 * 4. BASE_URL otomatis (mendukung install di root maupun sub-folder)
 * -------------------------------------------------------------------------- */
if (!defined('BASE_URL')) {
    $scheme = 'http';
    if ((!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)) {
        $scheme = 'https';
    }
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

    $basePath = '';
    $docRoot  = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    if ($docRoot && strpos(ROOT_PATH, $docRoot) === 0) {
        $basePath = str_replace('\\', '/', substr(ROOT_PATH, strlen($docRoot)));
    }
    $basePath = rtrim($basePath, '/');

    define('BASE_URL', $scheme . '://' . $host . $basePath);
}

/** URL asset / halaman helper */
function base_url($path = '')
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/* --------------------------------------------------------------------------
 * 5. Batasan upload bukti (JPG / PNG / PDF)
 * -------------------------------------------------------------------------- */
define('UPLOAD_MAX_SIZE', 3 * 1024 * 1024);            // 3 MB per file
define('UPLOAD_ALLOWED_EXT', 'jpg,jpeg,png,pdf');
define('UPLOAD_ALLOWED_MIME', 'image/jpeg,image/png,application/pdf');
define('IMPORT_MAX_SIZE', 2 * 1024 * 1024);            // 2 MB untuk .xlsx

/* --------------------------------------------------------------------------
 * 6. Session hardening
 * -------------------------------------------------------------------------- */
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    session_name('OMSSESSION');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    if ($secure) {
        ini_set('session.cookie_secure', '1');
    }
    ini_set('session.gc_maxlifetime', '7200');

    // PHP >= 7.3 mendukung opsi cookie array (SameSite)
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/');
    }

    session_start();
}

/* --------------------------------------------------------------------------
 * 7. Header keamanan dasar
 * -------------------------------------------------------------------------- */
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: same-origin');
}

/* --------------------------------------------------------------------------
 * 8. Muat helper & guard
 * -------------------------------------------------------------------------- */
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/csrf.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/activity.php';
