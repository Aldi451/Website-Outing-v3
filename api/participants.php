<?php
/**
 * ==========================================================================
 *  API — api/participants.php  (endpoint AJAX khusus ADMIN)
 * --------------------------------------------------------------------------
 *  action = set_attendance : ubah status kehadiran (Ikut/Batal/Tidak Ikut)
 *  action = set_payment    : ubah status pembayaran (Sudah/Belum)
 *  action = update         : ubah keduanya sekaligus
 *  action = remove         : hapus peserta dari outing
 *
 *  Keamanan: require_admin() + method POST + token CSRF + prepared statement
 *  + verifikasi bahwa peserta benar-benar milik outing yang dikirim.
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}
csrf_verify();

$ENUM_KEHADIRAN = ['Ikut', 'Batal', 'Tidak Ikut'];
$ENUM_BAYAR = ['Sudah', 'Belum'];

$action = post_str('action');
$id = post_int('id', 0);
$outingId = post_int('outing_id', 0);

$row = fetch_one('SELECT * FROM outing_participants WHERE id = ? LIMIT 1', [$id]);
if ($row === null) {
    json_response(['ok' => false, 'message' => 'Data peserta tidak ditemukan.'], 404);
}
if ($outingId > 0 && (int) $row['outing_id'] !== $outingId) {
    json_response(['ok' => false, 'message' => 'Peserta tidak berada pada outing tersebut.'], 403);
}
$outingId = (int) $row['outing_id'];

$outing = get_outing($outingId);
if ($outing === null) {
    json_response(['ok' => false, 'message' => 'Outing tidak ditemukan.'], 404);
}

/* --------------------------------------------------------------------------
 * HAPUS PESERTA
 * -------------------------------------------------------------------------- */
if ($action === 'remove') {
    $nama = fetch_value('SELECT nama FROM users WHERE id = ?', [(int) $row['user_id']], '(tidak diketahui)');
    run_query('DELETE FROM outing_participants WHERE id = ?', [$id]);
    log_activity('Hapus peserta ' . $nama . ' (id=' . $id . ') dari outing ' . $outing['kode'], 'outing_participants', $id);

    json_response(['ok' => true, 'message' => 'Peserta ' . $nama . ' dihapus dari outing.', 'reload' => true]);
}

/* --------------------------------------------------------------------------
 * PERBARUI KEHADIRAN / PEMBAYARAN
 * -------------------------------------------------------------------------- */
$doAttendance = ($action === 'set_attendance' || $action === 'update');
$doPayment = ($action === 'set_payment' || $action === 'update');

if (!$doAttendance && !$doPayment) {
    json_response(['ok' => false, 'message' => 'Aksi tidak dikenali.'], 400);
}

$attendanceNow = $row['attendance_status'];
$paymentNow = $row['payment_status'];
$nominal = (float) $row['nominal_bayar'];
$tanggalBayar = $row['tanggal_bayar'];
$pesan = [];

if ($doAttendance) {
    $attendance = in_enum(post_str('attendance_status'), $ENUM_KEHADIRAN, null);
    if ($attendance === null) {
        json_response(['ok' => false, 'message' => 'Nilai kehadiran tidak valid.'], 422);
    }
    run_query('UPDATE outing_participants SET attendance_status = ?, updated_at = NOW() WHERE id = ?', [$attendance, $id]);
    $attendanceNow = $attendance;
    $pesan[] = 'Kehadiran: <strong>' . e($attendance) . '</strong>';
    log_activity('Ubah kehadiran peserta id=' . $id . ' menjadi ' . $attendance, 'outing_participants', $id);
}

if ($doPayment) {
    $payment = in_enum(post_str('payment_status'), $ENUM_BAYAR, null);
    if ($payment === null) {
        json_response(['ok' => false, 'message' => 'Nilai pembayaran tidak valid.'], 422);
    }

    if ($payment === 'Sudah') {
        if ($nominal <= 0) {
            $nominal = (float) $outing['iuran_per_orang'];   // isi otomatis iuran standar
        }
        if (empty($tanggalBayar)) {
            $tanggalBayar = date('Y-m-d');
        }
    } else {
        $tanggalBayar = null;
    }

    run_query(
        'UPDATE outing_participants SET payment_status = ?, nominal_bayar = ?, tanggal_bayar = ?, updated_at = NOW()
         WHERE id = ?',
        [$payment, $nominal, $tanggalBayar, $id]
    );
    $paymentNow = $payment;
    $pesan[] = 'Pembayaran: <strong>' . e($payment) . '</strong>' . ($payment === 'Sudah' ? ' (' . rupiah($nominal) . ')' : '');
    log_activity('Ubah pembayaran peserta id=' . $id . ' menjadi ' . $payment, 'outing_participants', $id);
}

/* Peserta "Tidak Ikut" tidak mungkin berstatus lunas */
if ($attendanceNow === 'Tidak Ikut' && $paymentNow === 'Sudah') {
    run_query("UPDATE outing_participants SET payment_status = 'Belum', nominal_bayar = 0, tanggal_bayar = NULL WHERE id = ?", [$id]);
    $paymentNow = 'Belum';
    $nominal = 0.0;
    $tanggalBayar = null;
    $pesan[] = 'Pembayaran otomatis dikoreksi menjadi <strong>Belum</strong> karena peserta tidak ikut.';
}

json_response([
    'ok' => true,
    'message' => implode(' &middot; ', $pesan),
    'html' => badge_attendance($attendanceNow),
    'payment_html' => badge_payment($paymentNow),
    'attendance' => $attendanceNow,
    'payment' => $paymentNow,
    'nominal' => rupiah($nominal, false),
    'tanggal_bayar' => $tanggalBayar ? date('d/m/Y', strtotime($tanggalBayar)) : '-',
    'reload' => true,
]);
