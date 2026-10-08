<?php
/**
 * ==========================================================================
 *  API — api/rundown.php  (endpoint AJAX khusus ADMIN)
 * --------------------------------------------------------------------------
 *  action = reorder    : naik/turunkan urutan (swap dengan tetangga)
 *  action = set_urutan : set nomor urut langsung
 *  action = delete     : hapus satu baris rundown
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}
csrf_verify();

$action = post_str('action');
$id = post_int('id', 0);

$row = fetch_one('SELECT * FROM rundown WHERE id = ? LIMIT 1', [$id]);
if ($row === null) {
    json_response(['ok' => false, 'message' => 'Data rundown tidak ditemukan.'], 404);
}
$outingId = (int) $row['outing_id'];

/* ------------------------------------------------------------ REORDER */
if ($action === 'reorder') {
    $direction = post_str('direction', 'up');

    // Pastikan urutan terisi rapi (1..N) sebelum dipindah
    $list = fetch_all('SELECT id, urutan FROM rundown WHERE outing_id = ? ORDER BY urutan ASC, id ASC', [$outingId]);
    $n = 0;
    foreach ($list as $item) {
        $n++;
        if ((int) $item['urutan'] !== $n) {
            run_query('UPDATE rundown SET urutan = ? WHERE id = ?', [$n, (int) $item['id']]);
            if ((int) $item['id'] === $id) {
                $row['urutan'] = $n;
            }
        }
    }

    $current = 0;
    $orderedIds = [];
    foreach ($list as $item) {
        $orderedIds[] = (int) $item['id'];
    }
    $pos = array_search($id, $orderedIds, true);
    if ($pos === false) {
        json_response(['ok' => false, 'message' => 'Posisi rundown tidak ditemukan.'], 404);
    }

    $target = ($direction === 'up') ? $pos - 1 : $pos + 1;
    if ($target < 0 || $target >= count($orderedIds)) {
        json_response(['ok' => false, 'message' => 'Tidak dapat dipindah: sudah berada di posisi paling '
            . ($direction === 'up' ? 'atas' : 'bawah') . '.'], 422);
    }

    // Tukar urutan dua baris yang bersebelahan
    $idA = $orderedIds[$pos];
    $idB = $orderedIds[$target];
    $rowA = fetch_one('SELECT urutan FROM rundown WHERE id = ?', [$idA]);
    $rowB = fetch_one('SELECT urutan FROM rundown WHERE id = ?', [$idB]);
    if ($rowA === null || $rowB === null) {
        json_response(['ok' => false, 'message' => 'Data rundown berubah, muat ulang halaman.'], 409);
    }

    run_query('UPDATE rundown SET urutan = ? WHERE id = ?', [(int) $rowB['urutan'], $idA]);
    run_query('UPDATE rundown SET urutan = ? WHERE id = ?', [(int) $rowA['urutan'], $idB]);
    log_activity('Ubah urutan rundown id=' . $id . ' (' . $direction . ')', 'rundown', $id);

    json_response(['ok' => true, 'message' => 'Urutan rundown diperbarui.', 'reload' => true]);
}

/* -------------------------------------------------------- SET URUTAN */
if ($action === 'set_urutan') {
    $urutan = post_int('urutan', 0);
    if ($urutan < 1) {
        json_response(['ok' => false, 'message' => 'Nomor urut minimal 1.'], 422);
    }
    run_query('UPDATE rundown SET urutan = ?, updated_at = NOW() WHERE id = ?', [$urutan, $id]);
    log_activity('Set urutan rundown id=' . $id . ' menjadi ' . $urutan, 'rundown', $id);

    json_response(['ok' => true, 'message' => 'Urutan disimpan.', 'reload' => true]);
}

/* ------------------------------------------------------------- DELETE */
if ($action === 'delete') {
    run_query('DELETE FROM rundown WHERE id = ?', [$id]);
    log_activity('Hapus rundown "' . $row['acara'] . '" (id=' . $id . ')', 'rundown', $id);

    json_response(['ok' => true, 'message' => 'Rundown "' . $row['acara'] . '" dihapus.', 'reload' => true]);
}

json_response(['ok' => false, 'message' => 'Aksi tidak dikenali.'], 400);
