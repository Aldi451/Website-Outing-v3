<?php
/**
 * ==========================================================================
 *  ADMIN — admin/template_excel.php  (DOWNLOAD TEMPLATE EXCEL .xlsx)
 * --------------------------------------------------------------------------
 *  Menghasilkan file .xlsx dengan 2 sheet:
 *    Sheet 1 "DATA"     -> baris 1 header kolom + 2 baris CONTOH
 *    Sheet 2 "PETUNJUK" -> aturan pengisian & daftar nilai status yang VALID
 *
 *  type = user | peserta | pembelian
 *  Library: libs/Xlsx.php (pure PHP, tanpa Composer)
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();
require_once LIBS_PATH . '/Xlsx.php';

$JENIS = ['user', 'peserta', 'pembelian'];
$type = in_enum(get_str('type', 'user'), $JENIS, 'user');

$outingId = get_int('outing_id', 0);
$outing = ($outingId > 0) ? get_outing($outingId) : null;

/* --------------------------------------------------------------------------
 * Definisi kolom & contoh data
 * -------------------------------------------------------------------------- */
if ($type === 'user') {
    $judul = 'TEMPLATE IMPORT DATA PENGGUNA';
    $headers = ['User ID', 'Nama Lengkap', 'Jabatan', 'Departemen', 'No HP', 'Email', 'Role', 'Status', 'Password'];
    $widths = [16, 26, 22, 20, 16, 30, 12, 12, 16];
    $contoh = [
        ['MBR011', 'Tono Suprapto', 'Staff Produksi', 'Produksi', '081234567013', 'tono@company.local', 'member', 'aktif', 'rahasia123'],
        ['ADMIN003', 'Vina Melati', 'Supervisor HR', 'Human Resource', '081234567014', 'vina@company.local', 'admin', 'aktif', 'rahasia123'],
    ];
    $petunjuk = [
        ['Kolom', 'Aturan Pengisian'],
        ['User ID', 'WAJIB. Unik, 3-30 karakter, hanya huruf/angka/titik/strip/underscore. Contoh: MBR011, ADMIN003. Dipakai untuk login.'],
        ['Nama Lengkap', 'WAJIB. Minimal 3 karakter, maksimal 100 karakter.'],
        ['Jabatan', 'Opsional. Contoh: Staff Keuangan, Supervisor.'],
        ['Departemen', 'Opsional. Contoh: Finance, Marketing, IT.'],
        ['No HP', 'Opsional. Angka, boleh diawali 0 atau +62. Contoh: 081234567013'],
        ['Email', 'Opsional (sistem TIDAK mengirim email verifikasi). Format: nama@domain.com'],
        ['Role', 'WAJIB. Hanya boleh: admin  ATAU  member.  (admin = full akses, member = lihat saja)'],
        ['Status', 'WAJIB. Hanya boleh: aktif  ATAU  nonaktif.  Akun nonaktif tidak dapat login.'],
        ['Password', 'WAJIB. 6-72 karakter. Disimpan sebagai hash bcrypt, tidak dapat dilihat kembali.'],
        ['', ''],
        ['CATATAN', 'Baris yang User ID-nya sudah terdaftar akan ditandai ERROR dan dilewati (data lama tidak ditimpa).'],
        ['CATATAN', 'Judul kolom pada baris 1 boleh memakai sinonim: UserID, Username, NIP. Jangan menghapus baris header.'],
        ['CATATAN', 'Hapus 2 baris contoh sebelum mengisi data Anda sendiri.'],
    ];
} elseif ($type === 'peserta') {
    $judul = 'TEMPLATE IMPORT PESERTA OUTING';
    $headers = ['User ID', 'Kehadiran', 'Pembayaran', 'Nominal Bayar', 'Tanggal Bayar', 'Catatan'];
    $widths = [16, 16, 16, 18, 18, 34];
    $contoh = [
        ['MBR001', 'Ikut', 'Sudah', 350000, '2026-09-10', 'Transfer BCA'],
        ['MBR002', 'Ikut', 'Belum', 0, '', 'Menunggu konfirmasi'],
        ['MBR003', 'Batal', 'Belum', 0, '', 'Sedang cuti'],
    ];
    $petunjuk = [
        ['Kolom', 'Aturan Pengisian'],
        ['User ID', 'WAJIB. Harus sudah terdaftar di menu Pengguna dan berstatus AKTIF. Contoh: MBR001'],
        ['Kehadiran', 'WAJIB. Hanya boleh: Ikut  |  Batal  |  Tidak Ikut'],
        ['Pembayaran', 'WAJIB. Hanya boleh: Sudah  |  Belum'],
        ['Nominal Bayar', 'Opsional. Angka tanpa titik ribuan (contoh 350000). Bila Pembayaran = Sudah dan kolom ini kosong, sistem mengisi otomatis dengan iuran standar outing.'],
        ['Tanggal Bayar', 'Opsional. Format TEKS: YYYY-MM-DD (contoh 2026-09-10). Bila Pembayaran = Sudah dan tanggal kosong, dipakai tanggal hari ini.'],
        ['Catatan', 'Opsional. Maksimal 255 karakter. Contoh: Transfer BCA, potong gaji.'],
        ['', ''],
        ['PENTING', 'Import peserta harus memilih OUTING tujuan terlebih dahulu di halaman Import.'],
        ['PENTING', 'Peserta yang sudah terdaftar pada outing tsb akan ditandai ERROR dan dilewati (tidak diduplikasi).'],
        ['PENTING', 'Satu pengguna boleh diimport ke banyak outing berbeda (jalankan import per outing).'],
        ['PENTING', 'Status kehadiran dan pembayaran adalah dua kolom TERPISAH.'],
    ];
} else {
    $judul = 'TEMPLATE IMPORT PEMBELIAN / PENGELUARAN';
    $headers = ['Kategori', 'Nama Barang', 'Qty', 'Satuan', 'Harga Satuan', 'Tanggal Beli', 'Total (diabaikan)', 'Keterangan'];
    $widths = [20, 34, 10, 12, 18, 16, 20, 34];
    $contoh = [
        ['Konsumsi', 'Makan Siang Panitia', 30, 'pax', 45000, '2026-09-10', 1350000, 'Menu prasmanan'],
        ['Transportasi', 'Sewa Bus Pariwisata 50 seat', 2, 'unit', 4500000, '2026-09-05', 9000000, 'Termasuk driver & BBM'],
        ['Perlengkapan', 'Kaos Peserta', 25, 'pcs', 85000, '2026-09-15', 2125000, 'Ukuran S-XXL'],
    ];
    $petunjuk = [
        ['Kolom', 'Aturan Pengisian'],
        ['Kategori', 'WAJIB. Harus sama persis dengan nama kategori di menu "Kategori Biaya". Contoh: Transportasi, Konsumsi, Akomodasi.'],
        ['Nama Barang', 'WAJIB. Minimal 3 karakter. Contoh: Sewa Bus Pariwisata 50 seat'],
        ['Qty', 'WAJIB. Angka lebih dari 0, boleh desimal (contoh 1.5 untuk 1,5 kg).'],
        ['Satuan', 'Opsional (default: pcs). Contoh: pcs, unit, pax, box, paket, org, kamar, hari, liter.'],
        ['Harga Satuan', 'WAJIB. Angka >= 0 tanpa pemisah ribuan (contoh 4500000).'],
        ['Tanggal Beli', 'Opsional. Format TEKS: YYYY-MM-DD. Bila kosong/tidak valid, dipakai tanggal hari ini.'],
        ['Total (diabaikan)', 'TIDAK DIPAKAI. Sistem SELALU menghitung ulang Total = Qty x Harga Satuan.'],
        ['Keterangan', 'Opsional. Maksimal 255 karakter.'],
        ['', ''],
        ['KATEGORI BARU', 'Bila kategori belum ada, baris ditandai KUNING pada halaman preview.'],
        ['KATEGORI BARU', 'Anda dapat mencentang "Buat Kategori Otomatis" agar kategori tsb dibuat, atau membatalkan baris tersebut.'],
        ['BUKTI TRANSKSI', 'File bukti (JPG/PNG/PDF) TIDAK dapat diimport lewat Excel. Upload manual setelah import selesai.'],
        ['PENTING', 'Import pembelian harus memilih OUTING tujuan terlebih dahulu di halaman Import.'],
    ];
}

/* --------------------------------------------------------------------------
 * Susun sheet
 * -------------------------------------------------------------------------- */
$sheetData = [];

// baris judul
$sheetData[] = [
    ['v' => $judul . ($outing !== null ? ' — ' . $outing['kode'] . ' ' . $outing['nama'] : ''), 's' => Xlsx::S_TITLE, 'h' => 22],
];
$sheetData[] = [['v' => 'Baris 3 adalah HEADER kolom (jangan diubah/dihapus). Baris 4 dst. adalah CONTOH — hapus sebelum mengisi data Anda.', 's' => Xlsx::S_NOTE]];
$sheetData[] = [];

// header kolom
$headerRow = [];
foreach ($headers as $h) {
    $headerRow[] = ['v' => $h, 's' => Xlsx::S_HEADER, 'h' => 26];
}
$sheetData[] = $headerRow;

// baris contoh
foreach ($contoh as $c) {
    $row = [];
    foreach ($c as $i => $val) {
        $cell = ['v' => $val, 's' => Xlsx::S_EXAMPLE];
        if (is_int($val) || is_float($val)) {
            $cell['n'] = true;
        }
        $row[] = $cell;
    }
    $sheetData[] = $row;
}

// baris kosong untuk diisi + 30 baris bergaris
for ($i = 0; $i < 30; $i++) {
    $row = [];
    for ($j = 0; $j < count($headers); $j++) {
        $row[] = ['v' => '', 's' => Xlsx::S_CELL];
    }
    $sheetData[] = $row;
}

// sheet petunjuk
$sheetPetunjuk = [];
$sheetPetunjuk[] = [['v' => 'PETUNJUK PENGISIAN — ' . $judul, 's' => Xlsx::S_TITLE, 'h' => 22]];
$sheetPetunjuk[] = [['v' => 'Baca seluruh petunjuk ini sebelum mengisi Sheet "DATA".', 's' => Xlsx::S_NOTE]];
$sheetPetunjuk[] = [];

foreach ($petunjuk as $i => $baris) {
    $isHeader = ($i === 0);
    $sheetPetunjuk[] = [
        ['v' => $baris[0], 's' => $isHeader ? Xlsx::S_HEADER : Xlsx::S_CELL, 'h' => $isHeader ? 22 : null],
        ['v' => $baris[1], 's' => $isHeader ? Xlsx::S_HEADER : Xlsx::S_CELL],
    ];
}

$sheetPetunjuk[] = [];
$sheetPetunjuk[] = [['v' => 'NILAI STATUS YANG VALID', 's' => Xlsx::S_TITLE, 'h' => 20]];
$statusValid = [
    ['Jenis Data', 'Kolom', 'Nilai yang Diterima'],
    ['Pengguna', 'Role', 'admin | member'],
    ['Pengguna', 'Status', 'aktif | nonaktif'],
    ['Peserta', 'Kehadiran', 'Ikut | Batal | Tidak Ikut'],
    ['Peserta', 'Pembayaran', 'Sudah | Belum'],
    ['Pembelian', 'Kategori', 'Harus sama dengan nama kategori di sistem (dinamis, dapat dibuat otomatis saat preview)'],
    ['Semua', 'Tanggal', 'YYYY-MM-DD (contoh 2026-09-10). Tulis sebagai TEKS agar tidak diubah Excel.'],
    ['Semua', 'Angka', 'Tanpa pemisah ribuan (contoh 4500000, bukan 4.500.000)'],
];
foreach ($statusValid as $i => $baris) {
    $row = [];
    foreach ($baris as $val) {
        $row[] = ['v' => $val, 's' => ($i === 0) ? Xlsx::S_HEADER : Xlsx::S_CELL];
    }
    $sheetPetunjuk[] = $row;
}

$sheetPetunjuk[] = [];
$sheetPetunjuk[] = [['v' => 'ALUR IMPORT DI SISTEM', 's' => Xlsx::S_TITLE, 'h' => 20]];
$alur = [
    '1. Simpan file ini, isi Sheet DATA (hapus baris contoh).',
    '2. Buka menu "Import Excel" -> pilih jenis data -> (pilih outing bila perlu) -> upload file ini.',
    '3. Sistem menampilkan HALAMAN PREVIEW: baris valid (hijau), perlu perhatian (kuning), error (merah).',
    '4. Perbaiki baris error di Excel bila perlu, atau lanjutkan import apa adanya.',
    '5. Klik "Konfirmasi Import". Hanya baris valid yang disimpan; baris error DIABAIKAN.',
    '6. Selesai - lihat hasilnya di menu Pengguna / Peserta / Pembelian.',
];
foreach ($alur as $teks) {
    $sheetPetunjuk[] = [['v' => $teks, 's' => Xlsx::S_CELL]];
}

$sheets = [
    ['name' => 'DATA', 'rows' => $sheetData, 'widths' => $widths, 'freeze' => 4],
    ['name' => 'PETUNJUK', 'rows' => $sheetPetunjuk, 'widths' => [24, 96, 60]],
];

$namaFile = 'template-import-' . $type . '-' . date('Ymd') . '.xlsx';

try {
    Xlsx::download($namaFile, $sheets);
} catch (Exception $ex) {
    log_activity('Gagal membuat template Excel: ' . $ex->getMessage(), 'import', 0);
    flash('danger', 'Gagal membuat template Excel: ' . e($ex->getMessage()));
    redirect('admin/import.php?type=' . $type);
}
