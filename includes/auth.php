<?php
/**
 * ==========================================================================
 *  OUTING MANAGEMENT SYSTEM — includes/auth.php
 * --------------------------------------------------------------------------
 *  Autentikasi (User ID + Password) dan GUARD HAK AKSES.
 *
 *  PENTING: proteksi dilakukan di SISI SERVER pada baris awal setiap file.
 *  Menyembunyikan tombol di HTML BUKAN proteksi.
 * ==========================================================================
 */

defined('APP_STARTED') or exit('Direct access is not allowed.');

/* ==========================================================================
 * 1. SESSION USER
 * ========================================================================== */

/** Data user yang sedang login (atau null). */
function current_user()
{
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

/** ID (primary key) user yang sedang login, 0 jika belum login. */
function current_uid()
{
    return isset($_SESSION['user']['uid']) ? (int) $_SESSION['user']['uid'] : 0;
}

function current_nama()
{
    return isset($_SESSION['user']['nama']) ? $_SESSION['user']['nama'] : '';
}

function current_user_id_login()
{
    return isset($_SESSION['user']['user_id']) ? $_SESSION['user']['user_id'] : '';
}

function is_logged_in()
{
    return isset($_SESSION['user']['uid']);
}

function is_admin()
{
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';
}

function is_member()
{
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'member';
}

/* ==========================================================================
 * 2. PROSES LOGIN
 * ========================================================================== */

/**
 * Verifikasi kredensial User ID + Password.
 *
 * @param string $userIdLogin
 * @param string $password
 * @return array ['ok'=>bool,'message'=>string,'user'=>array|null]
 */
function attempt_login($userIdLogin, $password)
{
    $out = ['ok' => false, 'message' => '', 'user' => null];

    $userIdLogin = trim((string) $userIdLogin);
    $password = (string) $password;

    if ($userIdLogin === '' || $password === '') {
        $out['message'] = 'User ID dan Password wajib diisi.';
        return $out;
    }
    if (!valid_user_id($userIdLogin)) {
        $out['message'] = 'Format User ID tidak valid.';
        return $out;
    }

    $user = fetch_one(
        'SELECT id, user_id, nama, password, role, status FROM users WHERE user_id = ? LIMIT 1',
        [$userIdLogin]
    );

    // Hash dummy supaya waktu respon tidak membocorkan ada/tidaknya user (timing attack)
    $dummyHash = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30Ry1uAe6cSfB4nJzY6u';

    if ($user === null) {
        password_verify($password, $dummyHash);
        $out['message'] = 'User ID atau Password salah.';
        return $out;
    }

    if (!password_verify($password, $user['password'])) {
        $out['message'] = 'User ID atau Password salah.';
        return $out;
    }

    if ($user['status'] !== 'aktif') {
        $out['message'] = 'Akun Anda berstatus NONAKTIF. Hubungi Administrator.';
        return $out;
    }

    // Upgrade hash bila algoritma/biaya berubah di versi PHP baru
    if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 12])) {
        run_query('UPDATE users SET password = ? WHERE id = ?', [
            password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            (int) $user['id'],
        ]);
    }

    $out['ok'] = true;
    $out['message'] = 'Login berhasil.';
    $out['user'] = [
        'uid'     => (int) $user['id'],
        'user_id' => $user['user_id'],
        'nama'    => $user['nama'],
        'role'    => $user['role'],
    ];

    return $out;
}

/** Tulis session login (dengan regenerasi ID session: anti session fixation). */
function do_login(array $userData)
{
    session_regenerate_id(true);

    $_SESSION['user'] = $userData;
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    csrf_token();
}

/** Hapus session (logout). */
function do_logout()
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

/**
 * Idle timeout: logout otomatis bila tidak ada aktivitas > 2 jam.
 * Dipanggil oleh require_login().
 */
function check_idle_timeout($maxSeconds = 7200)
{
    if (!is_logged_in()) {
        return;
    }
    $last = isset($_SESSION['last_activity']) ? (int) $_SESSION['last_activity'] : time();
    if ((time() - $last) > $maxSeconds) {
        do_logout();
        session_start();
        flash('warning', 'Sesi Anda berakhir karena tidak aktif. Silakan login kembali.');
        redirect('index.php');
    }
    $_SESSION['last_activity'] = time();
}

/* ==========================================================================
 * 3. GUARD HAK AKSES
 * ========================================================================== */

/** Wajib login (semua role). */
function require_login()
{
    check_idle_timeout();

    if (!is_logged_in()) {
        flash('warning', 'Silakan login terlebih dahulu.');
        redirect('index.php');
    }
}

/**
 * Wajib ADMIN.
 * - Belum login  -> index.php
 * - Login member -> /member/dashboard.php + pesan "Akses ditolak"
 */
function require_admin()
{
    require_login();

    if (!is_admin()) {
        if (is_ajax()) {
            json_response(['ok' => false, 'message' => 'Akses ditolak: halaman khusus Administrator.'], 403);
        }
        log_activity('Percobaan akses halaman admin ditolak', '', 0);
        flash('danger', 'Akses ditolak: halaman tersebut khusus Administrator.');
        redirect('member/dashboard.php');
    }
}

/**
 * Wajib MEMBER (halaman khusus member).
 * Admin yang membuka halaman member diarahkan ke dashboard admin.
 */
function require_member()
{
    require_login();

    if (!is_member()) {
        flash('info', 'Halaman tersebut khusus Member. Anda diarahkan ke Dashboard Admin.');
        redirect('admin/dashboard.php');
    }
}

/**
 * Guard untuk member membuka detail outing: harus benar-benar terdaftar
 * sebagai peserta outing tsb (mencegah member mengintip outing lain via ?id=).
 *
 * @param int $outingId
 * @return array baris outing_participants
 */
function require_participant($outingId)
{
    require_member();

    $outingId = (int) $outingId;
    if ($outingId <= 0) {
        flash('danger', 'Outing tidak ditemukan.');
        redirect('member/dashboard.php');
    }

    $outing = get_outing($outingId);
    if ($outing === null) {
        flash('danger', 'Outing tidak ditemukan.');
        redirect('member/dashboard.php');
    }

    $participant = fetch_one(
        'SELECT * FROM outing_participants WHERE outing_id = ? AND user_id = ? LIMIT 1',
        [$outingId, current_uid()]
    );
    if ($participant === null) {
        log_activity('Percobaan akses outing bukan peserta', 'outings', $outingId);
        flash('danger', 'Akses ditolak: Anda bukan peserta outing tersebut.');
        redirect('member/dashboard.php');
    }

    return ['outing' => $outing, 'participant' => $participant];
}

/* ==========================================================================
 * 4. PASSWORD ADMIN
 * ========================================================================== */

/** Hash password baru (bcrypt cost 12). */
function hash_password($plain)
{
    return password_hash((string) $plain, PASSWORD_BCRYPT, ['cost' => 12]);
}

/** Validasi kekuatan password minimal. */
function validate_password($plain)
{
    $plain = (string) $plain;
    if (strlen($plain) < 6) {
        return 'Password minimal 6 karakter.';
    }
    if (strlen($plain) > 72) {
        return 'Password maksimal 72 karakter.';
    }

    return true;
}
