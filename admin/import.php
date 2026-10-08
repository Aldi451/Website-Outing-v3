<?php
/**
 * ==========================================================================
 *  ADMIN — admin/import.php  (WIZARD IMPORT EXCEL .xlsx)
 * --------------------------------------------------------------------------
 *  Mendukung 3 jenis import: USER, PESERTA, PEMBELIAN.
 *
 *  Alur: pilih jenis + upload -> sistem validasi per baris -> HALAMAN PREVIEW
 *        (baris valid & error ditampilkan terpisah) -> "Konfirmasi Import"
 *        -> hanya baris VALID yang diproses, baris error DIABAIKAN.
 *
 *  Khusus pembelian: bila kategori tidak ditemukan, baris ditandai KUNING dan
 *  admin dapat memilih "Buat Kategori Otomatis" atau membatalkan baris tsb.
 *  Kolom TOTAL pada Excel SELALU diabaikan -> dihitung ulang Qty x Harga.
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();
require_once LIBS_PATH . '/Xlsx.php';

define('IMPORT_MAX_ROWS', 500);
define('IMPORT_SESSION_KEY', 'import_preview');

$JENIS = [
    'user'      => ['label' => 'Data Pengguna', 'icon' => 'bi-people', 'butuh_outing' => false],
    'peserta'   => ['label' => 'Peserta Outing', 'icon' => 'bi-person-plus', 'butuh_outing' => true],
    'pembelian' => ['label' => 'Pembelian / Pengeluaran', 'icon' => 'bi-receipt', 'butuh_outing' => true],
];

/* --------------------------------------------------------------------------
 * Normalisasi nilai status dari Excel (fleksibel huruf besar/kecil & sinonim)
 * -------------------------------------------------------------------------- */
function norm_attendance($value)
{
    $v = strtolower(trim(str_replace('_', ' ', (string) $value)));
    $map = [
        'ikut' => 'Ikut', 'ya' => 'Ikut', 'y' => 'Ikut', 'hadir' => 'Ikut', 'konfirmasi' => 'Ikut', '1' => 'Ikut',
        'batal' => 'Batal', 'cancel' => 'Batal', 'berhalangan' => 'Batal', '2' => 'Batal',
        'tidak ikut' => 'Tidak Ikut', 'tidak' => 'Tidak Ikut', 'n' => 'Tidak Ikut', 'no' => 'Tidak Ikut',
        'tidak hadir' => 'Tidak Ikut', 'absen' => 'Tidak Ikut', '0' => 'Tidak Ikut', '3' => 'Tidak Ikut',
    ];

    return isset($map[$v]) ? $map[$v] : null;
}

function norm_payment($value)
{
    $v = strtolower(trim((string) $value));
    $map = [
        'sudah' => 'Sudah', 'lunas' => 'Sudah', 'sudah bayar' => 'Sudah', 'bayar' => 'Sudah',
        'y' => 'Sudah', 'ya' => 'Sudah', '1' => 'Sudah', 'paid' => 'Sudah',
        'belum' => 'Belum', 'belum bayar' => 'Belum', 'n' => 'Belum', 'tidak' => 'Belum',
        '0' => 'Belum', 'unpaid' => 'Belum', '2' => 'Belum',
    ];

    return isset($map[$v]) ? $map[$v] : null;
}

function norm_role($value)
{
    $v = strtolower(trim((string) $value));
    if (in_array($v, ['admin', 'administrator', 'a', '1'], true)) {
        return 'admin';
    }
    if (in_array($v, ['member', 'anggota', 'm', '2', 'peserta'], true)) {
        return 'member';
    }

    return null;
}

function norm_user_status($value)
{
    $v = strtolower(trim((string) $value));
    if (in_array($v, ['aktif', 'active', 'a', '1', 'ya'], true)) {
        return 'aktif';
    }
    if (in_array($v, ['nonaktif', 'non-aktif', 'inactive', 'tidak aktif', 'n', '0'], true)) {
        return 'nonaktif';
    }

    return null;
}

/** Hapus payload preview dari session. */
function import_reset()
{
    unset($_SESSION[IMPORT_SESSION_KEY]);
}

$preview = isset($_SESSION[IMPORT_SESSION_KEY]) ? $_SESSION[IMPORT_SESSION_KEY] : null;

/* ==========================================================================
 * PROSES POST
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $task = post_str('task');

    /* ------------------------------------------------------------------
     * LANGKAH 1 -> PARSE & VALIDASI
     * ------------------------------------------------------------------ */
    if ($task === 'parse') {
        import_reset();

        $type = in_enum(post_str('type'), array_keys($JENIS), null);
        $outingId = post_int('outing_id', 0);

        if ($type === null) {
            flash('danger', 'Jenis import tidak valid.');
            redirect('admin/import.php');
        }
        if ($JENIS[$type]['butuh_outing']) {
            if ($outingId <= 0 || get_outing($outingId) === null) {
                flash('danger', 'Pilih outing tujuan import terlebih dahulu.');
                redirect('admin/import.php?type=' . $type);
            }
        } else {
            $outingId = 0;
        }

        /* validasi file */
        if (!isset($_FILES['excel_file']) || (int) $_FILES['excel_file']['error'] === UPLOAD_ERR_NO_FILE) {
            flash('danger', 'Pilih file Excel (.xlsx) terlebih dahulu.');
            redirect('admin/import.php?type=' . $type . ($outingId ? '&outing_id=' . $outingId : ''));
        }
        if ((int) $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            flash('danger', 'Gagal upload file (kode error ' . (int) $_FILES['excel_file']['error'] . ').');
            redirect('admin/import.php?type=' . $type . ($outingId ? '&outing_id=' . $outingId : ''));
        }
        if (!is_uploaded_file($_FILES['excel_file']['tmp_name'])) {
            flash('danger', 'File tidak valid.');
            redirect('admin/import.php?type=' . $type);
        }

        $ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            flash('danger', 'Format file harus <strong>.xlsx</strong> (Excel Workbook). File lama .xls tidak didukung.');
            redirect('admin/import.php?type=' . $type . ($outingId ? '&outing_id=' . $outingId : ''));
        }

        $cek = Xlsx::validateFile($_FILES['excel_file']['tmp_name'], IMPORT_MAX_SIZE);
        if (!$cek['ok']) {
            flash('danger', e($cek['message']));
            redirect('admin/import.php?type=' . $type . ($outingId ? '&outing_id=' . $outingId : ''));
        }

        try {
            $rows = Xlsx::readSheet($_FILES['excel_file']['tmp_name'], 'DATA', IMPORT_MAX_ROWS + 5);
        } catch (Exception $ex) {
            flash('danger', 'Gagal membaca file Excel: ' . e($ex->getMessage()));
            redirect('admin/import.php?type=' . $type . ($outingId ? '&outing_id=' . $outingId : ''));
        }

        /* buang baris kosong */
        $clean = [];
        foreach ($rows as $r) {
            if (!Xlsx::isEmptyRow($r)) {
                $clean[] = $r;
            }
        }
        if (count($clean) < 2) {
            flash('danger', 'File tidak berisi data. Pastikan baris pertama adalah HEADER dan minimal ada satu baris data.');
            redirect('admin/import.php?type=' . $type . ($outingId ? '&outing_id=' . $outingId : ''));
        }

        $header = $clean[0];
        array_shift($clean);

        $hasil = import_validate($type, $header, $clean, $outingId);

        $_SESSION[IMPORT_SESSION_KEY] = [
            'type'      => $type,
            'outing_id' => $outingId,
            'created'   => time(),
            'filename'  => basename($_FILES['excel_file']['name']),
            'columns'   => $hasil['columns'],
            'rows'      => $hasil['rows'],
            'ringkas'   => $hasil['ringkas'],
        ];

        log_activity('Parse file Excel untuk import ' . $JENIS[$type]['label'], 'import', $outingId);
        redirect('admin/import.php?step=2');
    }

    /* ------------------------------------------------------------------
     * LANGKAH 2 -> EKSEKUSI IMPORT (HANYA BARIS VALID)
     * ------------------------------------------------------------------ */
    if ($task === 'execute') {
        if (!is_array($preview)) {
            flash('danger', 'Sesi preview import berakhir. Silakan upload ulang file Excel.');
            redirect('admin/import.php');
        }
        if ((time() - (int) $preview['created']) > 3600) {
            import_reset();
            flash('danger', 'Sesi preview import kedaluwarsa (lebih dari 1 jam). Silakan upload ulang.');
            redirect('admin/import.php');
        }

        $autoCategory = (post_str('auto_category', '0') === '1');
        $hasil = import_execute($preview, $autoCategory);
        import_reset();

        log_activity('Import Excel ' . $JENIS[$preview['type']]['label'] . ': '
            . $hasil['sukses'] . ' berhasil, ' . $hasil['gagal'] . ' dilewati', 'import', (int) $preview['outing_id']);

        $pesan = '<strong>' . $hasil['sukses'] . '</strong> data berhasil diimport, '
            . '<strong>' . $hasil['gagal'] . '</strong> baris dilewati (error/kategori dibatalkan).';
        if (count($hasil['detail']) > 0) {
            $pesan .= '<ul class="mb-0 mt-1 ps-3">' . implode('', $hasil['detail']) . '</ul>';
        }
        if (count($hasil['password_baru']) > 0) {
            $pesan .= '<div class="mt-2 small">Password hasil import sudah tersimpan sebagai hash. '
                . 'Informasikan password awal kepada pengguna terkait.</div>';
        }

        flash($hasil['sukses'] > 0 ? 'success' : 'warning', $pesan);

        $target = 'admin/import.php?step=3&sukses=' . $hasil['sukses'] . '&gagal=' . $hasil['gagal'];
        redirect($target);
    }

    /* ------------------------------------------------------------------
     * BATAL
     * ------------------------------------------------------------------ */
    if ($task === 'cancel') {
        import_reset();
        flash('info', 'Import dibatalkan. Tidak ada data yang diubah.');
        redirect('admin/import.php');
    }

    flash('danger', 'Perintah tidak dikenali.');
    redirect('admin/import.php');
}

/* ==========================================================================
 * VALIDASI BARIS (LANGKAH 1 -> 2)
 * ========================================================================== */
/**
 * @return array ['columns'=>[], 'rows'=>[], 'ringkas'=>[]]
 */
function import_validate($type, array $header, array $rows, $outingId)
{
    $aliases = import_aliases($type);
    $map = Xlsx::mapHeader($header, $aliases);
    $columns = import_columns($type);

    // kolom wajib yang tidak ditemukan di header
    $missing = [];
    foreach ($columns as $field => $meta) {
        if (!empty($meta['required']) && !isset($map[$field])) {
            $missing[] = $meta['label'];
        }
    }

    $result = [];
    $ok = 0;
    $warn = 0;
    $err = 0;
    $seenKeys = [];
    $existingUsers = [];
    $existingCategories = get_categories_map();
    $outing = ($outingId > 0) ? get_outing($outingId) : null;

    // cache data untuk validasi unik
    if ($type === 'user') {
        $all = fetch_all('SELECT id, user_id FROM users');
        foreach ($all as $u) {
            $existingUsers[strtoupper($u['user_id'])] = (int) $u['id'];
        }
    }
    $participantUsers = [];
    if ($type === 'peserta' && $outing !== null) {
        $all = fetch_all('SELECT user_id FROM outing_participants WHERE outing_id = ?', [$outingId]);
        foreach ($all as $p) {
            $participantUsers[(int) $p['user_id']] = true;
        }
        $all = fetch_all('SELECT id, user_id, nama, status FROM users');
        foreach ($all as $u) {
            $existingUsers[strtoupper($u['user_id'])] = $u;
        }
    }

    $no = 1;
    foreach ($rows as $row) {
        $no++; // nomor baris di Excel (header = baris 1)
        if (count($result) >= IMPORT_MAX_ROWS) {
            $result[] = [
                'no' => $no, 'status' => 'error', 'data' => [],
                'messages' => ['Batas maksimal ' . IMPORT_MAX_ROWS . ' baris per import terlampaui.'],
            ];
            $err++;
            continue;
        }

        $data = [];
        $errors = [];
        $warnings = [];

        foreach ($columns as $field => $meta) {
            $idx = isset($map[$field]) ? $map[$field] : null;
            $raw = Xlsx::cell($row, $idx, '');
            $data[$field] = $raw;

            if (!empty($meta['required']) && $raw === '') {
                if ($idx === null) {
                    if (!in_array($meta['label'] . ' (kolom tidak ditemukan di header)', $errors, true)) {
                        $errors[] = $meta['label'] . ' (kolom tidak ditemukan di header)';
                    }
                } else {
                    $errors[] = $meta['label'] . ' kosong';
                }
            }
        }

        /* --------------------------- aturan per jenis --------------------------- */
        if ($type === 'user') {
            $uid = strtoupper(trim($data['user_id']));
            $data['user_id'] = $uid;
            if ($uid !== '' && !valid_user_id($uid)) {
                $errors[] = 'User ID "' . e($uid) . '" tidak valid (3-30 karakter huruf/angka/titik/strip)';
            }
            if ($uid !== '' && isset($existingUsers[$uid])) {
                $errors[] = 'User ID "' . e($uid) . '" sudah terdaftar di database';
            }
            if ($uid !== '' && isset($seenKeys[$uid])) {
                $errors[] = 'User ID "' . e($uid) . '" duplikat di dalam file Excel ini';
            }
            if ($uid !== '') {
                $seenKeys[$uid] = true;
            }
            if (text_length($data['nama']) < 3 && $data['nama'] !== '') {
                $errors[] = 'Nama minimal 3 karakter';
            }
            if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $warnings[] = 'Format email diragukan: ' . e($data['email']);
            }
            $data['role'] = norm_role($data['role']);
            if ($data['role'] === null) {
                $errors[] = 'Role tidak valid (isi: admin / member)';
            }
            $data['status'] = norm_user_status($data['status']);
            if ($data['status'] === null) {
                $errors[] = 'Status tidak valid (isi: aktif / nonaktif)';
            }
            if (strlen((string) $data['password']) < 6) {
                $errors[] = 'Password minimal 6 karakter';
            }
            if (strlen((string) $data['password']) > 72) {
                $errors[] = 'Password maksimal 72 karakter';
            }
        }

        if ($type === 'peserta') {
            $uid = strtoupper(trim($data['user_id']));
            $data['user_id'] = $uid;
            $user = isset($existingUsers[$uid]) ? $existingUsers[$uid] : null;
            if ($uid === '') {
                $errors[] = 'User ID peserta kosong';
            } elseif ($user === null) {
                $errors[] = 'User ID "' . e($uid) . '" tidak ditemukan di database pengguna';
            } elseif ($user['status'] !== 'aktif') {
                $errors[] = 'Pengguna "' . e($user['nama']) . '" berstatus nonaktif';
            } elseif (isset($participantUsers[(int) $user['id']])) {
                $errors[] = '"' . e($user['nama']) . '" sudah terdaftar sebagai peserta outing ini';
            } elseif (isset($seenKeys[$uid])) {
                $errors[] = 'User ID "' . e($uid) . '" duplikat di dalam file Excel ini';
            }
            if ($user !== null) {
                $seenKeys[$uid] = true;
                $data['_user_db_id'] = (int) $user['id'];
                $data['_nama'] = $user['nama'];
            }

            $data['attendance_status'] = norm_attendance($data['attendance_status']);
            if ($data['attendance_status'] === null) {
                $errors[] = 'Status kehadiran tidak valid (isi: Ikut / Batal / Tidak Ikut)';
            }
            $data['payment_status'] = norm_payment($data['payment_status']);
            if ($data['payment_status'] === null) {
                $errors[] = 'Status pembayaran tidak valid (isi: Sudah / Belum)';
            }

            $nominal = parse_number($data['nominal_bayar']);
            if ($nominal < 0) {
                $errors[] = 'Nominal bayar tidak boleh negatif';
            }
            $data['_nominal'] = $nominal;
            if ($data['payment_status'] === 'Sudah' && $nominal <= 0 && $outing !== null) {
                $data['_nominal'] = (float) $outing['iuran_per_orang'];
                $warnings[] = 'Nominal kosong, otomatis diisi iuran standar ' . rupiah($outing['iuran_per_orang']);
            }

            $tgl = parse_date($data['tanggal_bayar']);
            if ($data['tanggal_bayar'] !== '' && $tgl === null) {
                $errors[] = 'Tanggal bayar tidak valid (gunakan YYYY-MM-DD)';
            }
            $data['_tanggal_bayar'] = $tgl;
        }

        if ($type === 'pembelian') {
            $namaKat = trim($data['kategori']);
            $categoryId = isset($existingCategories[$namaKat]) ? (int) $existingCategories[$namaKat] : 0;
            if ($namaKat === '') {
                $errors[] = 'Kategori kosong';
            } elseif ($categoryId === 0) {
                $warnings[] = 'Kategori "' . e($namaKat) . '" belum ada di database';
                $data['_kategori_baru'] = $namaKat;
            }
            $data['_category_id'] = $categoryId;

            if (text_length($data['nama_barang']) < 3 && $data['nama_barang'] !== '') {
                $errors[] = 'Nama barang minimal 3 karakter';
            }

            $qty = parse_number($data['qty']);
            if ($qty <= 0) {
                $errors[] = 'Qty harus lebih dari 0';
            }
            $harga = parse_number($data['harga_satuan']);
            if ($harga < 0) {
                $errors[] = 'Harga satuan tidak boleh negatif';
            }
            $data['_qty'] = $qty;
            $data['_harga'] = $harga;
            $data['_total'] = round($qty * $harga, 2);
            if (isset($data['total']) && trim((string) $data['total']) !== ''
                && abs(parse_number($data['total']) - $data['_total']) > 0.5) {
                $warnings[] = 'Kolom total Excel (' . e($data['total']) . ') diabaikan, sistem menghitung ulang '
                    . rupiah($data['_total'], false);
            }

            $tgl = parse_date($data['tanggal_beli']);
            if ($data['tanggal_beli'] !== '' && $tgl === null) {
                $warnings[] = 'Tanggal tidak valid, dipakai tanggal hari ini';
            }
            $data['_tanggal_beli'] = $tgl ? $tgl : date('Y-m-d');

            if ($data['satuan'] === '') {
                $data['satuan'] = 'pcs';
            }
        }

        if (count($missing) > 0 && $no === 2) {
            $errors[] = 'Kolom wajib tidak ditemukan di header: ' . implode(', ', $missing);
        }

        $status = 'ok';
        if (count($errors) > 0) {
            $status = 'error';
            $err++;
        } elseif (count($warnings) > 0) {
            $status = 'warn';
            $warn++;
        } else {
            $ok++;
        }

        $result[] = [
            'no' => $no,
            'status' => $status,
            'data' => $data,
            'messages' => array_merge($errors, $warnings),
        ];
    }

    return [
        'columns' => $columns,
        'rows' => $result,
        'ringkas' => ['ok' => $ok, 'warn' => $warn, 'error' => $err, 'total' => count($result)],
    ];
}

/** Definisi kolom per jenis import. */
function import_columns($type)
{
    if ($type === 'user') {
        return [
            'user_id'    => ['label' => 'User ID', 'required' => true],
            'nama'       => ['label' => 'Nama Lengkap', 'required' => true],
            'jabatan'    => ['label' => 'Jabatan', 'required' => false],
            'departemen' => ['label' => 'Departemen', 'required' => false],
            'no_hp'      => ['label' => 'No HP', 'required' => false],
            'email'      => ['label' => 'Email', 'required' => false],
            'role'       => ['label' => 'Role', 'required' => true],
            'status'     => ['label' => 'Status', 'required' => true],
            'password'   => ['label' => 'Password', 'required' => true],
        ];
    }
    if ($type === 'peserta') {
        return [
            'user_id'           => ['label' => 'User ID', 'required' => true],
            'attendance_status' => ['label' => 'Kehadiran', 'required' => true],
            'payment_status'    => ['label' => 'Pembayaran', 'required' => true],
            'nominal_bayar'     => ['label' => 'Nominal Bayar', 'required' => false],
            'tanggal_bayar'     => ['label' => 'Tanggal Bayar', 'required' => false],
            'catatan'           => ['label' => 'Catatan', 'required' => false],
        ];
    }

    return [
        'kategori'     => ['label' => 'Kategori', 'required' => true],
        'nama_barang'  => ['label' => 'Nama Barang', 'required' => true],
        'qty'          => ['label' => 'Qty', 'required' => true],
        'satuan'       => ['label' => 'Satuan', 'required' => false],
        'harga_satuan' => ['label' => 'Harga Satuan', 'required' => true],
        'tanggal_beli' => ['label' => 'Tanggal Beli', 'required' => false],
        'total'        => ['label' => 'Total (diabaikan)', 'required' => false],
        'keterangan'   => ['label' => 'Keterangan', 'required' => false],
    ];
}

/** Alias nama header yang diterima sistem. */
function import_aliases($type)
{
    if ($type === 'user') {
        return [
            'user_id'    => ['User ID', 'UserID', 'ID User', 'Username', 'NIP', 'NIK'],
            'nama'       => ['Nama', 'Nama Lengkap', 'Name', 'Nama User'],
            'jabatan'    => ['Jabatan', 'Posisi', 'Title'],
            'departemen' => ['Departemen', 'Divisi', 'Department', 'Unit'],
            'no_hp'      => ['No HP', 'NoHP', 'HP', 'Telepon', 'Phone', 'Whatsapp'],
            'email'      => ['Email', 'Surel', 'E-mail'],
            'role'       => ['Role', 'Peran', 'Hak Akses'],
            'status'     => ['Status', 'Status Akun'],
            'password'   => ['Password', 'Kata Sandi', 'Pass'],
        ];
    }
    if ($type === 'peserta') {
        return [
            'user_id'           => ['User ID', 'UserID', 'ID User', 'Username', 'NIP'],
            'attendance_status' => ['Kehadiran', 'Status Kehadiran', 'Attendance', 'Ikut'],
            'payment_status'    => ['Pembayaran', 'Status Pembayaran', 'Payment', 'Bayar'],
            'nominal_bayar'     => ['Nominal', 'Nominal Bayar', 'Jumlah Bayar', 'Nominal Pembayaran'],
            'tanggal_bayar'     => ['Tanggal Bayar', 'Tgl Bayar', 'Tanggal Pembayaran'],
            'catatan'           => ['Catatan', 'Keterangan', 'Note'],
        ];
    }

    return [
        'kategori'     => ['Kategori', 'Category', 'Kategori Biaya'],
        'nama_barang'  => ['Nama Barang', 'Item', 'Keperluan', 'Uraian', 'Nama Item'],
        'qty'          => ['Qty', 'Jumlah', 'Quantity', 'Banyaknya'],
        'satuan'       => ['Satuan', 'Unit', 'Uom'],
        'harga_satuan' => ['Harga Satuan', 'Harga', 'Price', 'Harga Unit'],
        'tanggal_beli' => ['Tanggal Beli', 'Tanggal', 'Tgl Beli', 'Date'],
        'total'        => ['Total', 'Subtotal', 'Jumlah Harga'],
        'keterangan'   => ['Keterangan', 'Catatan', 'Note'],
    ];
}

/* ==========================================================================
 * EKSEKUSI IMPORT (LANGKAH 2 -> 3)
 * ========================================================================== */
function import_execute(array $preview, $autoCategory)
{
    $type = $preview['type'];
    $outingId = (int) $preview['outing_id'];
    $sukses = 0;
    $gagal = 0;
    $detail = [];
    $passwordBaru = [];
    $kategoriDibuat = [];

    $outing = ($outingId > 0) ? get_outing($outingId) : null;

    foreach ($preview['rows'] as $row) {
        if ($row['status'] === 'error') {
            $gagal++;
            continue;
        }
        $data = $row['data'];

        try {
            if ($type === 'user') {
                insert_get_id(
                    'INSERT INTO users (user_id, nama, jabatan, departemen, no_hp, email, password, role, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [
                        strtoupper(trim($data['user_id'])),
                        $data['nama'],
                        isset($data['jabatan']) ? $data['jabatan'] : '',
                        isset($data['departemen']) ? $data['departemen'] : '',
                        isset($data['no_hp']) ? $data['no_hp'] : '',
                        isset($data['email']) ? $data['email'] : '',
                        hash_password($data['password']),
                        $data['role'],
                        $data['status'],
                    ]
                );
                $sukses++;
            }

            if ($type === 'peserta') {
                $uid = isset($data['_user_db_id']) ? (int) $data['_user_db_id'] : 0;
                if ($uid === 0 || $outing === null) {
                    $gagal++;
                    continue;
                }
                $nominal = isset($data['_nominal']) ? (float) $data['_nominal'] : 0.0;
                $tglBayar = isset($data['_tanggal_bayar']) ? $data['_tanggal_bayar'] : null;
                if ($data['payment_status'] === 'Sudah' && $tglBayar === null) {
                    $tglBayar = date('Y-m-d');
                }
                if ($data['payment_status'] === 'Belum') {
                    $tglBayar = null;
                    $nominal = 0.0;
                }

                $cek = fetch_one('SELECT id FROM outing_participants WHERE outing_id = ? AND user_id = ? LIMIT 1', [$outingId, $uid]);
                if ($cek !== null) {
                    $gagal++;
                    $detail[] = '<li>Baris ' . (int) $row['no'] . ': peserta sudah terdaftar, dilewati.</li>';
                    continue;
                }

                insert_get_id(
                    'INSERT INTO outing_participants (outing_id, user_id, attendance_status, payment_status,
                                                      nominal_bayar, tanggal_bayar, catatan, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                    [
                        $outingId, $uid, $data['attendance_status'], $data['payment_status'],
                        $nominal, $tglBayar, isset($data['catatan']) ? $data['catatan'] : '',
                    ]
                );
                $sukses++;
            }

            if ($type === 'pembelian') {
                $categoryId = isset($data['_category_id']) ? (int) $data['_category_id'] : 0;

                if ($categoryId === 0) {
                    if ($autoCategory && !empty($data['_kategori_baru'])) {
                        $namaBaru = $data['_kategori_baru'];
                        if (!isset($kategoriDibuat[$namaBaru])) {
                            $kategoriDibuat[$namaBaru] = insert_get_id(
                                'INSERT INTO categories (nama, keterangan, is_aktif, created_at)
                                 VALUES (?, ?, 1, NOW())',
                                [$namaBaru, 'Dibuat otomatis dari import Excel']
                            );
                            log_activity('Buat kategori otomatis "' . $namaBaru . '" dari import Excel', 'categories', $kategoriDibuat[$namaBaru]);
                        }
                        $categoryId = $kategoriDibuat[$namaBaru];
                    } else {
                        $gagal++;
                        $detail[] = '<li>Baris ' . (int) $row['no'] . ': kategori "' . e($data['kategori'])
                            . '" tidak ditemukan dan tidak dibuat otomatis &rarr; dilewati.</li>';
                        continue;
                    }
                }

                if ($outing === null) {
                    $gagal++;
                    continue;
                }

                $qty = isset($data['_qty']) ? (float) $data['_qty'] : 0;
                $harga = isset($data['_harga']) ? (float) $data['_harga'] : 0;
                $total = round($qty * $harga, 2);   // SELALU hitung ulang

                insert_get_id(
                    'INSERT INTO purchases (outing_id, category_id, nama_barang, qty, satuan, harga_satuan,
                                            total_harga, tanggal_beli, bukti_file, keterangan, created_by, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, "", ?, ?, NOW())',
                    [
                        $outingId, $categoryId, $data['nama_barang'], $qty,
                        (isset($data['satuan']) && $data['satuan'] !== '') ? $data['satuan'] : 'pcs',
                        $harga, $total,
                        isset($data['_tanggal_beli']) ? $data['_tanggal_beli'] : date('Y-m-d'),
                        isset($data['keterangan']) ? $data['keterangan'] : '',
                        current_uid(),
                    ]
                );
                $sukses++;
            }
        } catch (Exception $ex) {
            $gagal++;
            $detail[] = '<li>Baris ' . (int) $row['no'] . ': ' . e($ex->getMessage()) . '</li>';
        }
    }

    if (count($kategoriDibuat) > 0) {
        $detail[] = '<li>Kategori baru dibuat otomatis: <strong>'
            . e(implode(', ', array_keys($kategoriDibuat))) . '</strong></li>';
    }
    if (count($detail) > 12) {
        $sisa = count($detail) - 12;
        $detail = array_slice($detail, 0, 12);
        $detail[] = '<li>...dan ' . $sisa . ' catatan lainnya.</li>';
    }

    return [
        'sukses' => $sukses,
        'gagal' => $gagal,
        'detail' => $detail,
        'password_baru' => $passwordBaru,
    ];
}

/* ==========================================================================
 * TAMPILAN
 * ========================================================================== */
$type = in_enum(get_str('type'), array_keys($JENIS), '');
$step = get_int('step', 1);
$outings = fetch_all("SELECT id, kode, nama, tujuan, status FROM outings ORDER BY tanggal_mulai DESC LIMIT 100");

/* ------------------------------------------------- LANGKAH 2: PREVIEW */
if ($step === 2 && is_array($preview)) {
    $type = $preview['type'];
    $ringkas = $preview['ringkas'];
    $pageTitle = 'Preview Import Excel';
    $pageSubtitle = 'Periksa hasil validasi <strong>' . e($JENIS[$type]['label']) . '</strong> dari file '
        . e($preview['filename']) . '. Hanya baris valid yang akan diproses.';
    $activeMenu = 'import';
    require_once INCLUDES_PATH . '/header.php';

    $adaKategoriHilang = false;
    if ($type === 'pembelian') {
        foreach ($preview['rows'] as $r) {
            if (!empty($r['data']['_kategori_baru'])) {
                $adaKategoriHilang = true;
                break;
            }
        }
    }
    ?>
    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3">
        <div class="card"><div class="card-body stat-card py-3">
          <span class="stat-icon bg-soft-primary"><i class="bi bi-file-earmark-spreadsheet"></i></span>
          <div><div class="stat-label">Baris Dibaca</div><div class="stat-value"><?php echo (int) $ringkas['total']; ?></div></div>
        </div></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card"><div class="card-body stat-card py-3">
          <span class="stat-icon bg-soft-success"><i class="bi bi-check2-circle"></i></span>
          <div><div class="stat-label">Siap Import</div><div class="stat-value text-success"><?php echo (int) $ringkas['ok']; ?></div></div>
        </div></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card"><div class="card-body stat-card py-3">
          <span class="stat-icon bg-soft-warning"><i class="bi bi-exclamation-triangle"></i></span>
          <div><div class="stat-label">Perlu Perhatian</div><div class="stat-value text-warning"><?php echo (int) $ringkas['warn']; ?></div></div>
        </div></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card"><div class="card-body stat-card py-3">
          <span class="stat-icon bg-soft-danger"><i class="bi bi-x-octagon"></i></span>
          <div><div class="stat-label">Error (diabaikan)</div><div class="stat-value text-danger"><?php echo (int) $ringkas['error']; ?></div></div>
        </div></div>
      </div>
    </div>

    <?php if ($preview['outing_id'] > 0 && $outingRow = get_outing($preview['outing_id'])): ?>
      <div class="alert alert-light border py-2 small">
        <i class="bi bi-calendar2-heart me-1 text-primary"></i>
        Data akan diimport ke outing <strong><?php echo e($outingRow['nama']); ?></strong>
        (<span class="badge badge-soft"><?php echo e($outingRow['kode']); ?></span>)
        <?php echo badge_status_outing($outingRow['status']); ?>
      </div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <span class="card-title-sm me-auto"><i class="bi bi-list-check me-1 text-primary"></i>Hasil Validasi per Baris</span>
        <label class="fs-8 text-muted d-flex align-items-center gap-1">
          <input type="radio" name="previewFilter" value="" checked class="form-check-input mt-0"> Semua
        </label>
        <label class="fs-8 text-success d-flex align-items-center gap-1">
          <input type="radio" name="previewFilter" value="ok" class="form-check-input mt-0"> Valid
        </label>
        <label class="fs-8 text-warning d-flex align-items-center gap-1">
          <input type="radio" name="previewFilter" value="warn" class="form-check-input mt-0"> Peringatan
        </label>
        <label class="fs-8 text-danger d-flex align-items-center gap-1">
          <input type="radio" name="previewFilter" value="error" class="form-check-input mt-0"> Error
        </label>
      </div>
      <div class="card-body p-0">
        <div class="table-wrap" style="max-height:520px;overflow-y:auto">
          <table class="table table-sm align-middle mb-0" id="tblPreview">
            <thead>
              <tr>
                <th style="width:60px" class="text-center">Baris</th>
                <th style="width:120px" class="text-center">Status</th>
                <?php foreach (import_columns($type) as $meta): ?>
                  <th><?php echo e($meta['label']); ?></th>
                <?php endforeach; ?>
                <th>Keterangan Validasi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($preview['rows'] as $r):
                  $d = $r['data'];
                  $cls = ($r['status'] === 'ok') ? 'row-status-ok' : (($r['status'] === 'error') ? 'row-status-err' : 'row-status-warn');
                  $badge = ($r['status'] === 'ok')
                      ? '<span class="badge bg-success">SIAP IMPORT</span>'
                      : (($r['status'] === 'error') ? '<span class="badge bg-danger">ERROR</span>'
                          : '<span class="badge bg-warning text-dark">PERIKSA</span>');
              ?>
              <tr class="<?php echo $cls; ?>" data-status="<?php echo e($r['status']); ?>">
                <td class="text-center text-muted"><?php echo (int) $r['no']; ?></td>
                <td class="text-center"><?php echo $badge; ?></td>
                <?php foreach (import_columns($type) as $field => $meta):
                    $val = isset($d[$field]) ? $d[$field] : '';
                    if ($field === 'password') {
                        $val = ($val !== '') ? str_repeat('*', min(12, strlen((string) $val))) : '';
                    }
                    if ($type === 'pembelian' && $field === 'total') {
                        $val = isset($d['_total']) ? rupiah($d['_total'], false) . ' (hitung ulang)' : '';
                    }
                ?>
                  <td class="fs-8"><?php echo e(str_limit($val, 40)); ?></td>
                <?php endforeach; ?>
                <td class="fs-8">
                  <?php if (count($r['messages']) === 0): ?>
                    <span class="text-success"><i class="bi bi-check2"></i> Data valid</span>
                  <?php else: ?>
                    <ul class="mb-0 ps-3">
                      <?php foreach ($r['messages'] as $m): ?>
                        <li class="<?php echo ($r['status'] === 'error') ? 'text-danger' : 'text-warning'; ?>"><?php echo $m; ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card">
          <div class="card-header"><span class="card-title-sm"><i class="bi bi-play-circle me-1 text-success"></i>Konfirmasi Import</span></div>
          <div class="card-body">
            <form method="post" action="<?php echo base_url('admin/import.php'); ?>" id="formExecute"
                  data-confirm="Import <strong><?php echo (int) ($ringkas['ok'] + $ringkas['warn']); ?></strong> baris valid ke database?<br>Baris error (<?php echo (int) $ringkas['error']; ?>) akan diabaikan. Data yang sudah ada tidak ditimpa."
                  data-confirm-title="Konfirmasi Import" data-confirm-text="Ya, Import Sekarang" data-confirm-class="btn-success">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="task" value="execute">

              <?php if ($adaKategoriHilang): ?>
              <div class="alert alert-warning py-2">
                <div class="fw-semibold mb-1"><i class="bi bi-tags me-1"></i>Ada kategori yang belum terdaftar</div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" value="1" id="autoCategory" name="auto_category" checked>
                  <label class="form-check-label small" for="autoCategory">
                    <strong>Buat Kategori Otomatis</strong> untuk kategori yang belum ada.
                    <span class="d-block text-muted">Matikan pilihan ini bila baris dengan kategori baru ingin dibatalkan (tidak diimport).</span>
                  </label>
                </div>
              </div>
              <?php endif; ?>

              <ul class="small text-muted mb-3">
                <li>Baris <span class="badge bg-success">SIAP IMPORT</span> dan <span class="badge bg-warning text-dark">PERIKSA</span> akan diproses.</li>
                <li>Baris <span class="badge bg-danger">ERROR</span> diabaikan dan tidak membatalkan baris lain.</li>
                <?php if ($type === 'pembelian'): ?>
                  <li>Kolom total pada Excel diabaikan &mdash; sistem menghitung ulang <strong>Qty &times; Harga</strong>.</li>
                <?php endif; ?>
                <?php if ($type === 'user'): ?>
                  <li>Password akan disimpan sebagai hash bcrypt, bukan teks asli.</li>
                <?php endif; ?>
              </ul>

              <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-success" <?php echo (($ringkas['ok'] + $ringkas['warn']) === 0) ? 'disabled' : ''; ?>>
                  <i class="bi bi-check2-circle me-1"></i>Konfirmasi Import (<?php echo (int) ($ringkas['ok'] + $ringkas['warn']); ?> baris)
                </button>
                <button type="button" class="btn btn-light" onclick="document.getElementById('formBatal').submit();">
                  <i class="bi bi-x-circle me-1"></i>Batalkan Import
                </button>
              </div>
            </form>

            <form method="post" action="<?php echo base_url('admin/import.php'); ?>" id="formBatal" class="d-none">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="task" value="cancel">
            </form>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="card">
          <div class="card-header"><span class="card-title-sm"><i class="bi bi-question-circle me-1 text-primary"></i>Cara Memperbaiki Baris Error</span></div>
          <div class="card-body small">
            <?php if ($type === 'user'): ?>
              <ul class="mb-0 ps-3">
                <li class="mb-1"><strong>User ID</strong>: unik, 3-30 karakter (huruf/angka/titik/strip), belum terdaftar.</li>
                <li class="mb-1"><strong>Role</strong>: hanya <code>admin</code> atau <code>member</code>.</li>
                <li class="mb-1"><strong>Status</strong>: hanya <code>aktif</code> atau <code>nonaktif</code>.</li>
                <li><strong>Password</strong>: 6-72 karakter (disimpan sebagai hash).</li>
              </ul>
            <?php elseif ($type === 'peserta'): ?>
              <ul class="mb-0 ps-3">
                <li class="mb-1"><strong>User ID</strong> harus sudah ada di menu Pengguna dan berstatus aktif.</li>
                <li class="mb-1"><strong>Kehadiran</strong>: <code>Ikut</code> / <code>Batal</code> / <code>Tidak Ikut</code>.</li>
                <li class="mb-1"><strong>Pembayaran</strong>: <code>Sudah</code> / <code>Belum</code>.</li>
                <li class="mb-1"><strong>Tanggal</strong> format <code>YYYY-MM-DD</code>.</li>
                <li>Peserta yang sudah terdaftar pada outing ini otomatis dilewati.</li>
              </ul>
            <?php else: ?>
              <ul class="mb-0 ps-3">
                <li class="mb-1"><strong>Kategori</strong> harus sama persis dengan nama kategori di sistem (atau centang buat otomatis).</li>
                <li class="mb-1"><strong>Qty</strong> angka &gt; 0, <strong>Harga Satuan</strong> angka &ge; 0.</li>
                <li class="mb-1">Pisahkan ribuan dengan titik (1.500.000) atau tulis angka polos (1500000).</li>
                <li>Kolom <strong>Total</strong> diabaikan; sistem menghitung ulang Qty &times; Harga.</li>
                <li>Bukti transaksi tidak dapat diimport &mdash; upload manual setelah import.</li>
              </ul>
            <?php endif; ?>
            <hr>
            <a href="<?php echo base_url('admin/template_excel.php?type=' . $type); ?>" class="btn btn-sm btn-outline-success w-100">
              <i class="bi bi-download me-1"></i>Download Ulang Template Excel
            </a>
          </div>
        </div>
      </div>
    </div>
    <?php
    $extraScripts = <<<'HTML'
<script>
(function () {
  var radios = document.querySelectorAll('input[name="previewFilter"]');
  radios.forEach(function (r) {
    r.addEventListener('change', function () {
      var v = r.value;
      document.querySelectorAll('#tblPreview tbody tr[data-status]').forEach(function (tr) {
        tr.style.display = (v === '' || tr.getAttribute('data-status') === v) ? '' : 'none';
      });
    });
  });
})();
</script>
HTML;
    require_once INCLUDES_PATH . '/footer.php';
    exit;
}

/* ------------------------------------------------- LANGKAH 3: HASIL */
if ($step === 3) {
    $sukses = get_int('sukses', 0);
    $gagal = get_int('gagal', 0);
    $pageTitle = 'Hasil Import';
    $pageSubtitle = 'Proses import selesai.';
    $activeMenu = 'import';
    require_once INCLUDES_PATH . '/header.php';
    ?>
    <div class="card">
      <div class="card-body text-center py-5">
        <i class="bi <?php echo $sukses > 0 ? 'bi-check-circle-fill text-success' : 'bi-exclamation-circle-fill text-warning'; ?>" style="font-size:3.4rem"></i>
        <h4 class="mt-3 mb-1">Import Selesai</h4>
        <p class="text-muted mb-4">
          <span class="badge bg-success fs-6"><?php echo $sukses; ?> berhasil</span>
          <span class="badge bg-secondary fs-6"><?php echo $gagal; ?> dilewati</span>
        </p>
        <div class="d-flex flex-wrap justify-content-center gap-2">
          <a href="<?php echo base_url('admin/import.php'); ?>" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Import Lagi</a>
          <a href="<?php echo base_url('admin/users.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-people me-1"></i>Data Pengguna</a>
          <a href="<?php echo base_url('admin/outings.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-calendar2-heart me-1"></i>Data Outing</a>
        </div>
      </div>
    </div>
    <?php
    require_once INCLUDES_PATH . '/footer.php';
    exit;
}

/* ------------------------------------------------- LANGKAH 1: UPLOAD */
$pageTitle = 'Import Data Excel';
$pageSubtitle = 'Import massal <strong>Pengguna</strong>, <strong>Peserta</strong>, atau <strong>Pembelian</strong> dari file .xlsx. '
    . 'Sistem memvalidasi per baris sebelum data disimpan.';
$activeMenu = 'import';
require_once INCLUDES_PATH . '/header.php';
?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-cloud-arrow-up text-primary"></i>
        <span class="card-title-sm me-auto">Langkah 1 &mdash; Pilih Jenis &amp; Upload File</span>
        <span class="badge bg-light text-dark border">1 / 3</span>
      </div>
      <div class="card-body">
        <form method="post" action="<?php echo base_url('admin/import.php'); ?>" enctype="multipart/form-data" id="formImport">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="task" value="parse">

          <label class="form-label">Jenis Data yang Diimport <span class="required-mark">*</span></label>
          <div class="row g-2 mb-3">
            <?php foreach ($JENIS as $key => $meta): ?>
            <div class="col-md-4">
              <input type="radio" class="btn-check" name="type" id="tipe_<?php echo $key; ?>" value="<?php echo $key; ?>"
                     autocomplete="off" <?php echo ($type === $key) ? 'checked' : ''; ?> required>
              <label class="btn btn-outline-primary w-100 text-start py-2" for="tipe_<?php echo $key; ?>">
                <i class="bi <?php echo $meta['icon']; ?> me-1"></i>
                <span class="d-block small fw-semibold"><?php echo $meta['label']; ?></span>
                <span class="d-block fs-8 text-muted"><?php echo $meta['butuh_outing'] ? 'perlu pilih outing' : 'master data'; ?></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="mb-3" id="wrapOuting">
            <label class="form-label" for="outing_id">Outing Tujuan <span class="required-mark">*</span></label>
            <select class="form-select" id="outing_id" name="outing_id">
              <option value="">- Pilih Outing -</option>
              <?php foreach ($outings as $o): ?>
                <option value="<?php echo (int) $o['id']; ?>"
                        data-butuh="1"
                        <?php echo (get_int('outing_id', 0) === (int) $o['id']) ? 'selected' : ''; ?>>
                  <?php echo e($o['kode'] . ' — ' . $o['nama'] . ' (' . $o['tujuan'] . ')'); ?>
                  <?php echo in_array($o['status'], ['Selesai', 'Cancelled'], true) ? '[HISTORY]' : ''; ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Wajib untuk import <strong>Peserta</strong> dan <strong>Pembelian</strong>.</div>
          </div>

          <label class="form-label" for="excel_file">File Excel (.xlsx) <span class="required-mark">*</span></label>
          <div class="import-drop" id="dropZone">
            <i class="bi bi-file-earmark-spreadsheet d-block mb-2"></i>
            <div class="fw-semibold">Klik untuk memilih file atau tarik file ke sini</div>
            <div class="fs-8 text-muted mb-2">Format: <strong>.xlsx</strong> &middot; maksimal <?php echo format_bytes(IMPORT_MAX_SIZE); ?> &middot; maksimal <?php echo IMPORT_MAX_ROWS; ?> baris</div>
            <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".xlsx" required
                   data-preview-target="#infoFile">
            <div id="infoFile" class="mt-2 text-start"></div>
          </div>

          <div class="d-flex flex-wrap gap-2 mt-3">
            <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Upload &amp; Validasi Data</button>
            <a href="<?php echo base_url('admin/template_excel.php?type=' . ($type !== '' ? $type : 'user')); ?>" class="btn btn-outline-success" id="btnTemplate">
              <i class="bi bi-download me-1"></i>Download Template Excel
            </a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><span class="card-title-sm"><i class="bi bi-signpost-split me-1 text-primary"></i>Alur Import</span></div>
      <div class="card-body">
        <ol class="small mb-0 ps-3">
          <li class="mb-1">Download template Excel sesuai jenis data.</li>
          <li class="mb-1">Isi data pada <strong>Sheet "DATA"</strong> (jangan ubah nama kolom baris pertama).</li>
          <li class="mb-1">Baca <strong>Sheet "PETUNJUK"</strong> untuk format &amp; nilai status yang valid.</li>
          <li class="mb-1">Upload file &rarr; sistem memvalidasi setiap baris.</li>
          <li class="mb-1">Periksa <strong>halaman preview</strong>: baris valid, peringatan, dan error.</li>
          <li>Klik <strong>Konfirmasi Import</strong> &rarr; hanya baris valid yang disimpan.</li>
        </ol>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title-sm"><i class="bi bi-shield-check me-1 text-success"></i>Jaminan Keamanan Import</span></div>
      <div class="card-body small text-muted">
        <ul class="mb-0 ps-3">
          <li class="mb-1">File divalidasi: ekstensi <code>.xlsx</code>, magic byte ZIP, dan ukuran maksimal.</li>
          <li class="mb-1">Semua nilai disanitasi dan disimpan lewat <strong>prepared statement</strong>.</li>
          <li class="mb-1">Password dari Excel langsung di-hash bcrypt (tidak disimpan mentah).</li>
          <li class="mb-1">Data duplikat dilewati, data lama tidak ditimpa.</li>
          <li>Baris error tidak membatalkan baris lain yang valid.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php
$extraScripts = <<<'HTML'
<script>
(function () {
  var radios = document.querySelectorAll('input[name="type"]');
  var wrapOuting = document.getElementById('wrapOuting');
  var selectOuting = document.getElementById('outing_id');
  var btnTemplate = document.getElementById('btnTemplate');

  function butuhOuting() {
    var tipe = document.querySelector('input[name="type"]:checked');
    if (!tipe) { return true; }
    return (tipe.value === 'peserta' || tipe.value === 'pembelian');
  }

  function refresh() {
    var perlu = butuhOuting();
    wrapOuting.style.display = perlu ? '' : 'none';
    selectOuting.required = perlu;
    var tipe = document.querySelector('input[name="type"]:checked');
    if (tipe && btnTemplate) {
      btnTemplate.href = btnTemplate.href.split('?')[0] + '?type=' + tipe.value;
    }
  }

  radios.forEach(function (r) { r.addEventListener('change', refresh); });
  refresh();

  // drag & drop visual
  var dz = document.getElementById('dropZone');
  var fileInput = document.getElementById('excel_file');
  if (dz && fileInput) {
    ['dragenter', 'dragover'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('is-drag'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('is-drag'); });
    });
    dz.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        fileInput.dispatchEvent(new Event('change'));
      }
    });
  }
})();
</script>
HTML;

require_once INCLUDES_PATH . '/footer.php';
