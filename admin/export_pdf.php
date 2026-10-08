<?php
/**
 * ==========================================================================
 *  ADMIN — admin/export_pdf.php  (LAPORAN PDF PER outing_id)
 * --------------------------------------------------------------------------
 *  Isi laporan:
 *   1. Header (nama outing, tanggal, lokasi/tujuan)
 *   2. Ringkasan Peserta & Keuangan
 *   3. Daftar Peserta (kehadiran + pembayaran)
 *   4. Rundown Acara
 *   5. Rekap Pengeluaran per Kategori
 *   6. Detail Pembelian
 *   7. Penutup & tanda tangan
 *
 *  Dapat dipakai untuk outing AKTIF maupun HISTORY.
 *  ?download=1 -> unduh file ; tanpa parameter -> tampil di browser.
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();
require_once LIBS_PATH . '/SimplePDF.php';

$outingId = get_int('outing_id', 0);
$download = (get_int('download', 0) === 1);

$outing = get_outing($outingId);
if ($outing === null) {
    flash('danger', 'Outing tidak ditemukan, laporan tidak dapat dibuat.');
    redirect('admin/outings.php');
}

/* ------------------------------------------------------------------ data */
$fin = outing_finance($outingId);
$perKategori = outing_by_category($outingId);
$pembuat = fetch_one('SELECT nama FROM users WHERE id = ? LIMIT 1', [(int) $outing['created_by']]);

$peserta = fetch_all(
    "SELECT u.user_id, u.nama, u.departemen, u.jabatan,
            op.attendance_status, op.payment_status, op.nominal_bayar, op.tanggal_bayar, op.catatan
     FROM outing_participants op
     JOIN users u ON u.id = op.user_id
     WHERE op.outing_id = ?
     ORDER BY FIELD(op.attendance_status, 'Ikut', 'Batal', 'Tidak Ikut'), u.nama ASC",
    [$outingId]
);

$rundown = fetch_all(
    'SELECT * FROM rundown WHERE outing_id = ? ORDER BY urutan ASC, id ASC',
    [$outingId]
);

$pembelian = fetch_all(
    'SELECT p.*, c.nama AS kategori
     FROM purchases p
     JOIN categories c ON c.id = p.category_id
     WHERE p.outing_id = ?
     ORDER BY p.tanggal_beli ASC, p.id ASC',
    [$outingId]
);

$sisa = (float) $outing['anggaran'] - $fin['total_pengeluaran'];

/* ------------------------------------------------------------------ PDF */
$pdf = new SimplePDF('P');
$pdf->title = 'Laporan Outing - ' . $outing['nama'];
$pdf->subject = 'Laporan peserta, rundown, dan keuangan outing ' . $outing['kode'];
$pdf->author = APP_NAME;
$pdf->SetMargins(36, 40, 36, 46);
$pdf->SetHeader(
    'LAPORAN OUTING: ' . strtoupper($outing['nama']),
    'Kode ' . $outing['kode'] . '  |  Tujuan: ' . $outing['tujuan']
        . '  |  ' . rentang_tanggal($outing['tanggal_mulai'], $outing['tanggal_selesai'])
        . '  |  Status: ' . $outing['status']
);
$pdf->SetFooter(APP_NAME . ' - ' . $outing['kode']);
$pdf->AddPage();

/* ------------------------------------------------- 1. INFORMASI OUTING */
$pdf->SectionTitle('A. Informasi Outing');
$pdf->KeyValue('Nama Outing', $outing['nama']);
$pdf->KeyValue('Kode Outing', $outing['kode']);
$pdf->KeyValue('Tujuan', $outing['tujuan']);
$pdf->KeyValue('Lokasi / Titik Kumpul', $outing['lokasi'] !== '' ? $outing['lokasi'] : '-');
$pdf->KeyValue('Tanggal Pelaksanaan', rentang_tanggal($outing['tanggal_mulai'], $outing['tanggal_selesai']));
$pdf->KeyValue('Status', $outing['status']);
$pdf->KeyValue('Anggaran', rupiah($outing['anggaran']));
$pdf->KeyValue('Iuran per Orang', rupiah($outing['iuran_per_orang']));
$pdf->KeyValue('Penanggung Jawab', $pembuat !== null ? $pembuat['nama'] : '-');
if (!empty($outing['keterangan'])) {
    $pdf->KeyValue('Keterangan', $outing['keterangan']);
}
$pdf->Ln(3);

/* ------------------------------------------- 2. RINGKASAN PESERTA & KEU */
$pdf->SectionTitle('B. Ringkasan Peserta & Keuangan');
$pdf->SummaryBoxes([
    ['label' => 'Peserta Ikut', 'value' => (string) $fin['ikut'], 'color' => [229, 247, 236]],
    ['label' => 'Batal', 'value' => (string) $fin['batal'], 'color' => [255, 245, 224]],
    ['label' => 'Tidak Ikut', 'value' => (string) $fin['tidak_ikut'], 'color' => [238, 241, 246]],
    ['label' => 'Sudah Bayar', 'value' => (string) $fin['lunas'], 'color' => [227, 246, 251]],
    ['label' => 'Belum Bayar', 'value' => (string) $fin['belum_bayar'], 'color' => [255, 234, 234]],
]);
$pdf->SummaryBoxes([
    ['label' => 'Total Pengeluaran', 'value' => rupiah($fin['total_pengeluaran'], false), 'color' => [255, 234, 234]],
    ['label' => 'Iuran Terkumpul', 'value' => rupiah($fin['total_iuran'], false), 'color' => [229, 247, 236]],
    ['label' => 'Anggaran', 'value' => rupiah($outing['anggaran'], false), 'color' => [231, 240, 255]],
    ['label' => ($sisa >= 0 ? 'Sisa Anggaran' : 'Kelebihan'), 'value' => rupiah(abs($sisa), false),
        'color' => $sisa >= 0 ? [229, 247, 236] : [255, 245, 224]],
]);

$persenSerap = ((float) $outing['anggaran'] > 0) ? round($fin['total_pengeluaran'] / (float) $outing['anggaran'] * 100, 1) : 0;
$targetIuran = $fin['ikut'] * (float) $outing['iuran_per_orang'];
$persenIuran = ($targetIuran > 0) ? round($fin['total_iuran'] / $targetIuran * 100, 1) : 0;

$pdf->SetFont('helvetica', '', 9);
$pdf->MultiCell(0, 12,
    'Penyerapan anggaran: ' . $persenSerap . '% dari total anggaran.  '
    . 'Realisasi iuran: ' . $persenIuran . '% dari target iuran peserta ikut ('
    . rupiah($targetIuran, false) . ').', 0, 'L');
$pdf->Ln(4);

/* ------------------------------------------------- 3. DAFTAR PESERTA */
$pdf->SectionTitle('C. Daftar Peserta (' . count($peserta) . ' terdaftar)');
$rowsPeserta = [];
$no = 0;
foreach ($peserta as $p) {
    $no++;
    $rowsPeserta[] = [
        $no,
        $p['user_id'],
        $p['nama'] . (!empty($p['jabatan']) ? "\n(" . $p['jabatan'] . ')' : ''),
        $p['departemen'] !== '' ? $p['departemen'] : '-',
        $p['attendance_status'],
        $p['payment_status'],
        ($p['payment_status'] === 'Sudah') ? rupiah($p['nominal_bayar'], false) : '-',
        $p['tanggal_bayar'] ? date('d/m/Y', strtotime($p['tanggal_bayar'])) : '-',
    ];
}
$pdf->Table(
    ['No', 'User ID', 'Nama Peserta', 'Departemen', 'Kehadiran', 'Pembayaran', 'Nominal (Rp)', 'Tgl Bayar'],
    $rowsPeserta,
    [22, 58, 140, 88, 56, 56, 62, 52],
    ['C', 'L', 'L', 'L', 'C', 'C', 'R', 'C'],
    ['emptyText' => 'Belum ada peserta terdaftar pada outing ini.']
);

/* ------------------------------------------------------ 4. RUNDOWN */
$pdf->SectionTitle('D. Rundown Acara (' . count($rundown) . ' agenda)');
$rowsRundown = [];
foreach ($rundown as $r) {
    $waktu = ($r['waktu_mulai'] ? jam($r['waktu_mulai']) : '--:--') . ' - ' . ($r['waktu_selesai'] ? jam($r['waktu_selesai']) : '--:--');
    $tgl = '';
    if (!empty($r['hari'])) {
        $tgl .= $r['hari'];
    }
    if (!empty($r['tanggal'])) {
        $tgl .= ($tgl !== '' ? ', ' : '') . date('d/m/Y', strtotime($r['tanggal']));
    }
    $rowsRundown[] = [
        (int) $r['urutan'],
        $tgl !== '' ? $tgl : '-',
        $waktu,
        $r['acara'] . (!empty($r['keterangan']) ? "\n" . $r['keterangan'] : ''),
        $r['lokasi'] !== '' ? $r['lokasi'] : '-',
        $r['pic'] !== '' ? $r['pic'] : '-',
    ];
}
$pdf->Table(
    ['No', 'Hari / Tanggal', 'Waktu', 'Acara & Keterangan', 'Lokasi', 'PIC'],
    $rowsRundown,
    [24, 78, 66, 175, 92, 70],
    ['C', 'L', 'C', 'L', 'L', 'L'],
    ['emptyText' => 'Belum ada rundown untuk outing ini.']
);

/* ------------------------------------- 5. REKAP PENGELUARAN PER KATEGORI */
$pdf->SectionTitle('E. Rekap Pengeluaran per Kategori');
$rowsKategori = [];
$no = 0;
foreach ($perKategori as $k) {
    $total = (float) $k['total'];
    if ($total <= 0) {
        continue;
    }
    $no++;
    $share = ($fin['total_pengeluaran'] > 0) ? round($total / $fin['total_pengeluaran'] * 100, 1) : 0;
    $rowsKategori[] = [
        $no,
        $k['nama'],
        (int) $k['jumlah_item'],
        rupiah($total, false),
        $share . '%',
    ];
}
$pdf->Table(
    ['No', 'Kategori', 'Jumlah Item', 'Total (Rp)', 'Porsi'],
    $rowsKategori,
    [28, 220, 70, 110, 60],
    ['C', 'L', 'C', 'R', 'R'],
    ['emptyText' => 'Belum ada pengeluaran tercatat.']
);
if (count($rowsKategori) > 0) {
    $pdf->TotalRow('TOTAL PENGELUARAN', rupiah($fin['total_pengeluaran'], false));
}

/* -------------------------------------------- 6. DETAIL PEMBELIAN */
$pdf->SectionTitle('F. Detail Pembelian (' . count($pembelian) . ' transaksi)');
$rowsBeli = [];
$no = 0;
$grandTotal = 0.0;
foreach ($pembelian as $p) {
    $no++;
    $grandTotal += (float) $p['total_harga'];
    $qtyText = rtrim(rtrim(number_format((float) $p['qty'], 2, ',', '.'), '0'), ',');
    $rowsBeli[] = [
        $no,
        $p['tanggal_beli'] ? date('d/m/Y', strtotime($p['tanggal_beli'])) : '-',
        $p['nama_barang'] . (!empty($p['keterangan']) ? "\n" . $p['keterangan'] : ''),
        $p['kategori'],
        $qtyText . ' ' . $p['satuan'],
        rupiah($p['harga_satuan'], false),
        rupiah($p['total_harga'], false),
        !empty($p['bukti_file']) ? 'Ada' : '-',
    ];
}
$pdf->Table(
    ['No', 'Tanggal', 'Nama Barang / Keterangan', 'Kategori', 'Qty', 'Harga Satuan', 'Total (Rp)', 'Bukti'],
    $rowsBeli,
    [22, 52, 150, 74, 46, 62, 66, 30],
    ['C', 'C', 'L', 'L', 'C', 'R', 'R', 'C'],
    ['emptyText' => 'Belum ada transaksi pembelian.']
);
if (count($rowsBeli) > 0) {
    $pdf->TotalRow('TOTAL SELURUH PEMBELIAN', rupiah($grandTotal, false));
}

/* ------------------------------------------------------- 7. PENUTUP */
$pdf->SectionTitle('G. Catatan & Pengesahan');
$pdf->SetFont('helvetica', '', 9);
$pdf->MultiCell(0, 12,
    'Laporan ini dihasilkan otomatis oleh ' . APP_NAME . ' berdasarkan data outing "' . $outing['nama']
    . '" pada saat dicetak. Seluruh nominal dihitung sistem (Qty x Harga Satuan) dan dapat diverifikasi '
    . 'pada bukti transaksi yang diunggah di aplikasi.', 0, 'J');

$pdf->Ln(6);
$pdf->SignatureBlock(
    'Mengetahui,',
    $pembuat !== null ? $pembuat['nama'] : 'Penanggung Jawab Outing',
    'Dicetak oleh,',
    current_nama()
);

/* ------------------------------------------------------- OUTPUT */
$namaFile = 'Laporan-Outing-' . preg_replace('/[^A-Za-z0-9\-]/', '-', $outing['kode']) . '-' . date('Ymd') . '.pdf';

log_activity('Export PDF laporan outing ' . $outing['kode'], 'outings', $outingId);

$pdf->Output($namaFile, $download ? 'D' : 'I');
