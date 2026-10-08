<?php
/**
 * ==========================================================================
 *  API — api/users.php  (endpoint AJAX khusus ADMIN)
 * --------------------------------------------------------------------------
 *  action = toggle_status   : aktif / nonaktifkan akun
 *  action = reset_password  : reset password (acak atau sesuai input admin)
 *
 *  Pengaman: admin tidak dapat menonaktifkan akunnya sendiri, dan tidak dapat
 *  menonaktifkan admin terakhir yang masih aktif (mencegah terkunci).
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}
csrf_verify();

/**
 * Password acak yang mudah dibaca (tanpa karakter ambigu).
 * Memakai random_bytes bila tersedia.
 */
function generate_random_password($length = 10)
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $max = strlen($chars) - 1;
    $out = '';

    for ($i = 0; $i < $length; $i++) {
        if (function_exists('random_int')) {
            $out .= $chars[random_int(0, $max)];
        } else {
            $out .= $chars[mt_rand(0, $max)];
        }
    }

    return 'Oms-' . substr($out, 0, 4) . '-' . substr($out, 4);
}

$action = post_str('action');
$id = post_int('id', 0);

$user = fetch_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
if ($user === null) {
    json_response(['ok' => false, 'message' => 'Pengguna tidak ditemukan.'], 404);
}

/* ------------------------------------------------------- TOGGLE STATUS */
if ($action === 'toggle_status') {
    if ($id === current_uid()) {
        json_response(['ok' => false, 'message' => 'Anda tidak dapat menonaktifkan akun sendiri.'], 403);
    }

    $newStatus = ($user['status'] === 'aktif') ? 'nonaktif' : 'aktif';

    if ($newStatus === 'nonaktif' && $user['role'] === 'admin') {
        $adminAktif = (int) fetch_value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'aktif'", [], 0);
        if ($adminAktif <= 1) {
            json_response(['ok' => false, 'message' => 'Ditolak: harus ada minimal satu Administrator aktif.'], 403);
        }
    }

    run_query('UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?', [$newStatus, $id]);
    log_activity('Ubah status user ' . $user['user_id'] . ' menjadi ' . $newStatus, 'users', $id);

    json_response([
        'ok' => true,
        'message' => 'Status <strong>' . $user['nama'] . '</strong> kini ' . strtoupper($newStatus) . '.',
        'html' => badge_user_status($newStatus),
        'reload' => true,
    ]);
}

/* ----------------------------------------------------- RESET PASSWORD */
if ($action === 'reset_password') {
    $given = post_str('new_password', '');
    $isCustom = ($given !== '');
    $plain = $isCustom ? $given : generate_random_password();

    $valid = validate_password($plain);
    if ($valid !== true) {
        json_response(['ok' => false, 'message' => $valid], 422);
    }

    run_query('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?', [hash_password($plain), $id]);
    log_activity('Reset password user ' . $user['user_id'], 'users', $id);

    json_response([
        'ok' => true,
        'message' => 'Password ' . e($user['nama']) . ' berhasil direset.',
        'password' => $plain,
        'generated' => !$isCustom,
        'reload' => false,
    ]);
}

json_response(['ok' => false, 'message' => 'Aksi tidak dikenali.'], 400);
