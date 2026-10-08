<?php
/**
 * ==========================================================================
 *  API — api/purchases.php  (endpoint AJAX khusus ADMIN)
 * --------------------------------------------------------------------------
 *  action = calc        : hitung ulang total (Qty x Harga) di server
 *  action = delete      : hapus pembelian beserta file buktinya
 *  action = delete_bukti: hapus file bukti saja (data pembelian tetap ada)
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}
csrf_verify();

$action = post_str('action');

/* ---------------------------------------------------------------- CALC */
if ($action === 'calc') {
    $qty = parse_number(post_str('qty', '0'));
    $harga = parse_number(post_str('harga', '0'));
    $total = round($qty * $harga, 2);

    json_response([
        'ok' => true,
        'qty' => $qty,
        'harga' => $harga,
        'total' => $total,
        'total_text' => rupiah($total, false),
    ]);
}

$id = post_int('id', 0);
$row = fetch_one('SELECT p.*, o.kode AS outing_kode FROM purchases p JOIN outings o ON o.id = p.outing_id WHERE p.id = ? LIMIT 1', [$id]);
if ($row === null) {
    json_response(['ok' => false, 'message' => 'Data pembelian tidak ditemukan.'], 404);
}

/* ------------------------------------------------------------- DELETE */
if ($action === 'delete') {
    if (!empty($row['bukti_file'])) {
        delete_bukti((int) $row['outing_id'], $row['bukti_file']);
    }
    run_query('DELETE FROM purchases WHERE id = ?', [$id]);
    log_activity('Hapus pembelian "' . $row['nama_barang'] . '" (id=' . $id . ') outing ' . $row['outing_kode'], 'purchases', $id);

    json_response([
        'ok' => true,
        'message' => 'Pembelian "' . $row['nama_barang'] . '" berhasil dihapus.',
        'reload' => true,
    ]);
}

/* -------------------------------------------------------- DELETE BUKTI */
if ($action === 'delete_bukti') {
    if (empty($row['bukti_file'])) {
        json_response(['ok' => false, 'message' => 'Item ini tidak memiliki file bukti.'], 404);
    }
    $deleted = delete_bukti((int) $row['outing_id'], $row['bukti_file']);
    run_query('UPDATE purchases SET bukti_file = "", updated_at = NOW() WHERE id = ?', [$id]);
    log_activity('Hapus file bukti pembelian id=' . $id, 'purchases', $id);

    json_response([
        'ok' => true,
        'message' => $deleted ? 'File bukti berhasil dihapus dari server.' : 'Referensi bukti dihapus (file sudah tidak ada di server).',
        'reload' => true,
    ]);
}

json_response(['ok' => false, 'message' => 'Aksi tidak dikenali.'], 400);
