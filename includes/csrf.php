<?php
/**
 * ==========================================================================
 *  OUTING MANAGEMENT SYSTEM — includes/csrf.php
 * --------------------------------------------------------------------------
 *  Proteksi Cross-Site Request Forgery.
 *  Token dibuat sekali per session dan WAJIB diverifikasi pada semua request
 *  yang mengubah data (POST), baik form biasa maupun AJAX.
 * ==========================================================================
 */

defined('APP_STARTED') or exit('Direct access is not allowed.');

/** Ambil (atau buat) token CSRF session ini. */
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = function_exists('random_bytes')
            ? bin2hex(random_bytes(32))
            : bin2hex(openssl_random_pseudo_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/** Hidden input untuk dipasang di setiap <form>. */
function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Atribut untuk dipasang di <meta> agar JS bisa mengirim header X-CSRF-Token. */
function csrf_meta()
{
    return '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
}

/**
 * Verifikasi token CSRF.
 * Jika gagal: AJAX mendapat respon JSON 403, form biasa di-redirect balik.
 *
 * @param bool $dieWhenInvalid hentikan eksekusi bila tidak valid (default true)
 * @return bool
 */
function csrf_verify($dieWhenInvalid = true)
{
    $token = '';
    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        $token = $_POST['csrf_token'];
    } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    }

    $sessionToken = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
    $valid = ($token !== '' && $sessionToken !== '' && hash_equals($sessionToken, $token));

    if (!$valid && $dieWhenInvalid) {
        if (is_ajax()) {
            json_response(['ok' => false, 'message' => 'Token keamanan (CSRF) tidak valid. Muat ulang halaman.'], 403);
        }
        http_response_code(403);
        flash('danger', 'Token keamanan (CSRF) tidak valid atau sesi berakhir. Silakan ulangi.');
        redirect_back('index.php');
    }

    return $valid;
}
