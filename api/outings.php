<?php
/**
 * ==========================================================================
 *  API — api/outings.php  (endpoint AJAX khusus ADMIN)
 * --------------------------------------------------------------------------
 *  action = set_status : ubah status outing (Planning/Active/Selesai/Cancelled)
 *  action = summary    : ambil ringkasan angka sebuah outing (untuk widget)
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();

$ENUM_STATUS = ['Planning', 'Active', 'Selesai', 'Cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = post_str('action');
    $id = post_int('id', post_int('outing_id', 0));
    $outing = get_outing($id);

    if ($outing === null) {
        json_response(['ok' => false, 'message' => 'Outing tidak ditemukan.'], 404);
    }

    if ($action === 'set_status') {
        $status = in_enum(post_str('status'), $ENUM_STATUS, null);
        if ($status === null) {
            json_response(['ok' => false, 'message' => 'Status tidak valid.'], 422);
        }
        run_query('UPDATE outings SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $id]);
        log_activity('Ubah status outing ' . $outing['kode'] . ' menjadi ' . $status, 'outings', $id);

        json_response([
            'ok' => true,
            'message' => 'Status outing diubah menjadi "' . $status . '".',
            'html' => badge_status_outing($status),
            'is_history' => in_array($status, ['Selesai', 'Cancelled'], true),
            'reload' => true,
        ]);
    }

    json_response(['ok' => false, 'message' => 'Aksi tidak dikenali.'], 400);
}

/* -------------------------------------------------- GET: ringkasan outing */
$action = get_str('action');
$id = get_int('outing_id', get_int('id', 0));
$outing = get_outing($id);
if ($outing === null) {
    json_response(['ok' => false, 'message' => 'Outing tidak ditemukan.'], 404);
}

if ($action === 'summary') {
    $fin = outing_finance($id);
    json_response([
        'ok' => true,
        'data' => [
            'kode' => $outing['kode'],
            'nama' => $outing['nama'],
            'status' => $outing['status'],
            'finance' => $fin,
            'total_pengeluaran_text' => rupiah($fin['total_pengeluaran']),
            'total_iuran_text' => rupiah($fin['total_iuran']),
        ],
    ]);
}

json_response(['ok' => false, 'message' => 'Aksi tidak dikenali.'], 400);
