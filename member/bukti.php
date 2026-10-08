<?php
/**
 * ==========================================================================
 *  MEMBER — member/bukti.php  (LIHAT BUKTI TRANSAKSI — VIEW ONLY)
 * --------------------------------------------------------------------------
 *  File bukti TIDAK dapat diakses langsung dari /uploads (diblok .htaccess).
 *  File disajikan lewat skrip ini setelah memverifikasi:
 *    1. Pengguna login sebagai MEMBER
 *    2. Pengguna benar-benar terdaftar sebagai peserta outing tsb
 *    3. Nama file divalidasi (basename + realpath harus di dalam folder outing)
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_member();

$outingId = get_int('outing_id', 0);
$purchaseId = get_int('id', 0);

/* --- 1. cek kepesertaan --- */
$partisipasi = fetch_one(
    'SELECT id FROM outing_participants WHERE outing_id = ? AND user_id = ? LIMIT 1',
    [$outingId, current_uid()]
);
if ($partisipasi === null) {
    log_activity('Percobaan akses bukti tanpa kepesertaan (outing=' . $outingId . ', purchase=' . $purchaseId . ')', 'purchases', $purchaseId);
    http_response_code(403);
    exit('Akses ditolak: Anda bukan peserta outing tersebut.');
}

/* --- 2. cek data pembelian --- */
$row = fetch_one(
    'SELECT id, outing_id, nama_barang, bukti_file FROM purchases WHERE id = ? AND outing_id = ? LIMIT 1',
    [$purchaseId, $outingId]
);
if ($row === null || empty($row['bukti_file'])) {
    http_response_code(404);
    exit('Bukti transaksi tidak ditemukan.');
}

/* --- 3. validasi path (anti path traversal) --- */
$path = bukti_path($outingId, $row['bukti_file']);
if ($path === false) {
    http_response_code(404);
    exit('File bukti sudah tidak tersedia di server.');
}

$mime = bukti_mime($row['bukti_file']);
$namaUnduh = 'bukti-' . preg_replace('/[^A-Za-z0-9\-]/', '-', $row['nama_barang']) . '-' . basename($path);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $namaUnduh . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=60');

readfile($path);
exit;
