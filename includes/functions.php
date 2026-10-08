<?php
/**
 * ==========================================================================
 *  OUTING MANAGEMENT SYSTEM — includes/functions.php
 * --------------------------------------------------------------------------
 *  Kumpulan helper: escaping, flash, redirect, format rupiah/tanggal,
 *  validasi input, upload bukti (MIME + ekstensi), respon JSON.
 * ==========================================================================
 */

defined('APP_STARTED') or exit('Direct access is not allowed.');

/* ==========================================================================
 * 1. OUTPUT & ESCAPING (ANTI XSS)
 * ========================================================================== */

/**
 * Escape untuk dicetak ke HTML. WAJIB dipakai di semua output variabel.
 *
 * @param mixed $value
 * @return string
 */
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Panjang teks (multibyte-safe bila mbstring aktif). */
function text_length($text)
{
    $text = (string) $text;

    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

/** Potong teks panjang + titik tiga. */
function str_limit($text, $limit = 60, $end = '...')
{
    $text = trim((string) $text);
    if (function_exists('mb_strlen')) {
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }
        return mb_substr($text, 0, $limit, 'UTF-8') . $end;
    }
    if (strlen($text) <= $limit) {
        return $text;
    }
    return substr($text, 0, $limit) . $end;
}

/* ==========================================================================
 * 2. INPUT (TRIM + SANITASI)
 * ========================================================================== */

/** Ambil nilai POST sebagai string bersih. */
function post_str($key, $default = '')
{
    if (!isset($_POST[$key])) {
        return $default;
    }
    $value = $_POST[$key];
    if (is_array($value)) {
        return $default;
    }
    $value = trim((string) $value);
    $value = strip_tags($value);

    return $value === '' ? $default : $value;
}

/** Ambil nilai GET sebagai string bersih. */
function get_str($key, $default = '')
{
    if (!isset($_GET[$key])) {
        return $default;
    }
    $value = $_GET[$key];
    if (is_array($value)) {
        return $default;
    }
    $value = trim((string) $value);
    $value = strip_tags($value);

    return $value === '' ? $default : $value;
}

/** Ambil nilai POST/GET sebagai integer. */
function post_int($key, $default = 0)
{
    return isset($_POST[$key]) ? (int) $_POST[$key] : $default;
}

function get_int($key, $default = 0)
{
    return isset($_GET[$key]) ? (int) $_GET[$key] : $default;
}

/**
 * Ubah input angka bebas format menjadi float.
 * Mendukung: "1.500.000", "1,500,000", "1500000", "12.500", "Rp 12.000".
 */
function parse_number($value, $default = 0.0)
{
    $value = trim((string) $value);
    if ($value === '') {
        return (float) $default;
    }
    $value = preg_replace('/[^0-9,.\-]/', '', $value);
    if ($value === '' || $value === '-') {
        return (float) $default;
    }
    // "1.500.000" (titik ribuan) ATAU "1.500" desimal?
    if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
        if (strrpos($value, ',') > strrpos($value, '.')) {
            $value = str_replace('.', '', $value);   // 1.500.000,50
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);   // 1,500,000.50
        }
    } elseif (strpos($value, ',') !== false) {
        $value = str_replace(',', '.', $value);      // desimal koma
    } elseif (substr_count($value, '.') > 1) {
        $value = str_replace('.', '', $value);       // 1.500.000
    } elseif (substr_count($value, '.') === 1) {
        $parts = explode('.', $value);
        if (strlen($parts[1]) === 3 && strlen($parts[0]) >= 1 && (int) $parts[0] >= 1) {
            // pola "12.500" dianggap ribuan (umum di Indonesia)
            $value = str_replace('.', '', $value);
        }
    }

    return is_numeric($value) ? (float) $value : (float) $default;
}

/**
 * Validasi & normalisasi tanggal ke format Y-m-d.
 * Menerima: 2026-05-12, 12/05/2026, 12-05-2026, 12.05.2026.
 *
 * @return string|null null jika tidak valid
 */
function parse_date($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    // Excel serial date (angka murni 5 digit)
    if (ctype_digit($value) && (int) $value > 20000 && (int) $value < 80000) {
        return excel_serial_to_date((int) $value);
    }

    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m)) {
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
    } elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/', $value, $m)) {
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
    } else {
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d', $ts);
    }

    if (!checkdate($mo, $d, $y)) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

/** Konversi nomor seri tanggal Excel (1900 date system) ke Y-m-d. */
function excel_serial_to_date($serial)
{
    $serial = (int) $serial;
    if ($serial < 1) {
        return null;
    }
    // Excel salah menganggap 1900 sebagai tahun kabisat -> kurangi 1 untuk serial > 59
    $days = $serial > 59 ? $serial - 25569 : $serial - 25568;
    $ts = $days * 86400;
    $date = gmdate('Y-m-d', $ts);

    return $date;
}

/* ==========================================================================
 * 3. FORMAT TAMPILAN
 * ========================================================================== */

/** Format Rupiah: 1500000 -> "Rp 1.500.000" */
function rupiah($value, $withSymbol = true, $decimals = 0)
{
    $value = (float) $value;
    $formatted = number_format($value, $decimals, ',', '.');

    return ($withSymbol ? 'Rp ' : '') . $formatted;
}

/** Format tanggal Indonesia: 2026-05-12 -> "12 Mei 2026" */
function tgl_indo($date, $withDay = false)
{
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return e($date);
    }
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    $out = (int) date('j', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    if ($withDay) {
        $out = $hari[(int) date('w', $ts)] . ', ' . $out;
    }

    return $out;
}

/** Rentang tanggal: "12 - 14 Mei 2026" */
function rentang_tanggal($mulai, $selesai)
{
    $mulai = parse_date($mulai);
    $selesai = parse_date($selesai);
    if (!$mulai) {
        return '-';
    }
    if (!$selesai || $selesai === $mulai) {
        return tgl_indo($mulai, true);
    }

    $tm = strtotime($mulai);
    $ts = strtotime($selesai);
    if (date('Y-m', $tm) === date('Y-m', $ts)) {
        return date('j', $tm) . ' - ' . tgl_indo($selesai);
    }

    return tgl_indo($mulai) . ' - ' . tgl_indo($selesai);
}

/** Waktu "08:30:00" -> "08:30" */
function jam($time)
{
    if (empty($time)) {
        return '-';
    }
    $parts = explode(':', $time);

    return isset($parts[1]) ? $parts[0] . ':' . $parts[1] : $time;
}

/** Format ukuran file */
function format_bytes($bytes)
{
    $bytes = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, $i === 0 ? 0 : 2) . ' ' . $units[$i];
}

/** Slug / nama file aman (huruf kecil, tanpa spasi & karakter aneh). */
function slugify($text)
{
    $text = (string) $text;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');

    return $text === '' ? 'file' : substr($text, 0, 40);
}

/* ==========================================================================
 * 4. BADGE STATUS
 * ========================================================================== */

/** Badge status outing: Planning / Active / Selesai / Cancelled */
function badge_status_outing($status)
{
    $map = [
        'Planning'  => ['secondary', 'bi-clipboard-check', 'Planning'],
        'Active'    => ['success', 'bi-broadcast', 'Active'],
        'Selesai'   => ['primary', 'bi-check2-circle', 'Selesai'],
        'Cancelled' => ['danger', 'bi-x-circle', 'Cancelled'],
    ];
    $s = isset($map[$status]) ? $map[$status] : ['light text-dark', 'bi-question-circle', e($status)];

    return '<span class="badge bg-' . $s[0] . '"><i class="bi ' . $s[1] . '"></i> ' . $s[2] . '</span>';
}

/** Badge kehadiran: Ikut / Batal / Tidak Ikut */
function badge_attendance($status)
{
    $map = [
        'Ikut'       => ['success', 'Ikut'],
        'Batal'      => ['warning text-dark', 'Batal'],
        'Tidak Ikut' => ['secondary', 'Tidak Ikut'],
    ];
    $s = isset($map[$status]) ? $map[$status] : ['light text-dark', e($status)];

    return '<span class="badge bg-' . $s[0] . '">' . $s[1] . '</span>';
}

/** Badge pembayaran: Sudah / Belum */
function badge_payment($status)
{
    $map = [
        'Sudah' => ['info text-dark', 'Sudah Bayar'],
        'Belum' => ['danger', 'Belum Bayar'],
    ];
    $s = isset($map[$status]) ? $map[$status] : ['light text-dark', e($status)];

    return '<span class="badge bg-' . $s[0] . '">' . $s[1] . '</span>';
}

/** Badge status user */
function badge_user_status($status)
{
    $s = ($status === 'aktif') ? '<span class="badge bg-success">Aktif</span>'
        : '<span class="badge bg-danger">Nonaktif</span>';

    return $s;
}

/** Badge role */
function badge_role($role)
{
    return ($role === 'admin')
        ? '<span class="badge bg-dark"><i class="bi bi-shield-lock"></i> Admin</span>'
        : '<span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle"><i class="bi bi-person"></i> Member</span>';
}

/* ==========================================================================
 * 5. FLASH MESSAGE & REDIRECT (PRG PATTERN)
 * ========================================================================== */

/**
 * Simpan pesan sekali-tampil.
 *
 * @param string $type    success|danger|warning|info
 * @param string $message
 */
function flash($type, $message)
{
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Ambil & hapus semua flash. */
function flash_all()
{
    $list = isset($_SESSION['flash']) ? $_SESSION['flash'] : [];
    unset($_SESSION['flash']);

    return $list;
}

/** Cetak semua flash sebagai alert Bootstrap. */
function render_flash()
{
    $icon = [
        'success' => 'bi-check-circle-fill',
        'danger'  => 'bi-exclamation-octagon-fill',
        'warning' => 'bi-exclamation-triangle-fill',
        'info'    => 'bi-info-circle-fill',
    ];
    $html = '';
    foreach (flash_all() as $f) {
        $type = isset($icon[$f['type']]) ? $f['type'] : 'info';
        $html .= '<div class="alert alert-' . $type . ' alert-dismissible fade show d-flex align-items-start" role="alert">'
            . '<i class="bi ' . $icon[$type] . ' me-2 fs-5"></i><div class="flex-grow-1">' . $f['message'] . '</div>'
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button></div>';
    }

    return $html;
}

/**
 * Redirect lalu hentikan eksekusi (mencegah double submit).
 *
 * @param string $path path relatif dari BASE_URL, atau URL absolut
 */
function redirect($path)
{
    $url = (strpos($path, 'http') === 0) ? $path : base_url($path);
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }
    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    exit;
}

/** Kembali ke halaman sebelumnya (dengan fallback). */
function redirect_back($fallback = 'index.php')
{
    $ref = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    if ($ref !== '' && strpos($ref, base_url('')) === 0) {
        header('Location: ' . $ref);
        exit;
    }
    redirect($fallback);
}

/* ==========================================================================
 * 6. JSON (UNTUK ENDPOINT AJAX)
 * ========================================================================== */

/**
 * Kirim respon JSON lalu exit.
 *
 * @param array $data
 * @param int   $code
 */
function json_response($data, $code = 200)
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data);
    exit;
}

/** Deteksi request AJAX. */
function is_ajax()
{
    return (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
}

/* ==========================================================================
 * 7. VALIDASI UMUM
 * ========================================================================== */

/** Cek nilai termasuk daftar enum yang diizinkan (whitelist). */
function in_enum($value, array $allowed, $default = null)
{
    return in_array($value, $allowed, true) ? $value : $default;
}

/** Whitelist kolom ORDER BY (mencegah SQL Injection lewat parameter sort). */
function safe_sort($value, array $allowed, $default)
{
    return in_array($value, $allowed, true) ? $value : $default;
}

/** Validasi format User ID login: huruf/angka/titik/strip/underscore, 3-30 char. */
function valid_user_id($value)
{
    return (bool) preg_match('/^[A-Za-z0-9._\-]{3,30}$/', (string) $value);
}

/* ==========================================================================
 * 8. UPLOAD BUKTI (JPG / PNG / PDF) — VALIDASI EKSTENSI + MIME
 * ========================================================================== */

/** Folder upload khusus satu outing: /uploads/outing/{outing_id}/ */
function outing_upload_dir($outingId)
{
    $outingId = (int) $outingId;
    $dir = OUTING_UPLOAD_PATH . '/' . $outingId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

/**
 * Proses upload bukti pembelian.
 *
 * @param array $file     elemen $_FILES['nama_field']
 * @param int   $outingId
 * @return array ['ok'=>bool,'message'=>string,'filename'=>string]
 */
function upload_bukti($file, $outingId)
{
    $result = ['ok' => false, 'message' => '', 'filename' => ''];

    if (!isset($file) || !is_array($file)) {
        $result['message'] = 'File tidak diterima.';
        return $result;
    }

    $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        $result['message'] = 'Tidak ada file yang dipilih.';
        return $result;
    }
    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'Ukuran file melebihi batas php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'Ukuran file melebihi batas form.',
            UPLOAD_ERR_PARTIAL    => 'File hanya terupload sebagian.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara tidak ada.',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload diblokir ekstensi PHP.',
        ];
        $result['message'] = isset($messages[$error]) ? $messages[$error] : 'Gagal upload (kode ' . $error . ').';
        return $result;
    }

    $tmp = $file['tmp_name'];
    $size = (int) $file['size'];

    if (!is_uploaded_file($tmp)) {
        $result['message'] = 'File tidak valid.';
        return $result;
    }
    if ($size <= 0) {
        $result['message'] = 'Ukuran file kosong.';
        return $result;
    }
    if ($size > UPLOAD_MAX_SIZE) {
        $result['message'] = 'Ukuran file maksimal ' . format_bytes(UPLOAD_MAX_SIZE) . ' (file Anda ' . format_bytes($size) . ').';
        return $result;
    }

    // (a) Validasi EKSTENSI dari nama file
    $original = isset($file['name']) ? basename($file['name']) : '';
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowedExt = explode(',', UPLOAD_ALLOWED_EXT);
    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
        $result['message'] = 'Ekstensi tidak diizinkan. Gunakan: ' . strtoupper(UPLOAD_ALLOWED_EXT) . '.';
        return $result;
    }

    // (b) Validasi MIME ASLI memakai finfo (bukan menebak dari ekstensi)
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string) finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    }
    $allowedMime = explode(',', UPLOAD_ALLOWED_MIME);
    if ($mime === '' || !in_array($mime, $allowedMime, true)) {
        $result['message'] = 'Tipe file tidak valid (terdeteksi: ' . e($mime ?: 'tidak diketahui') . '). Hanya JPG/PNG/PDF.';
        return $result;
    }

    // (c) Validasi silang: ekstensi harus cocok dengan MIME
    $pair = [
        'image/jpeg'        => ['jpg', 'jpeg'],
        'image/png'         => ['png'],
        'application/pdf'   => ['pdf'],
    ];
    if (!isset($pair[$mime]) || !in_array($ext, $pair[$mime], true)) {
        $result['message'] = 'Ekstensi file tidak cocok dengan isi file.';
        return $result;
    }

    // (d) Verifikasi tambahan isi file
    if ($mime === 'application/pdf') {
        $head = @file_get_contents($tmp, false, null, 0, 5);
        if (strpos($head, '%PDF') !== 0) {
            $result['message'] = 'File PDF rusak atau bukan PDF asli.';
            return $result;
        }
    } else {
        $info = @getimagesize($tmp);
        if ($info === false) {
            $result['message'] = 'File gambar rusak atau bukan gambar asli.';
            return $result;
        }
    }

    // (e) Simpan dengan NAMA FILE AMAN & unik di folder per-outing
    $dir = outing_upload_dir($outingId);
    if (!is_dir($dir) || !is_writable($dir)) {
        $result['message'] = 'Folder upload tidak dapat ditulis. Periksa permission folder /uploads.';
        return $result;
    }

    $unique = function_exists('random_bytes') ? bin2hex(random_bytes(5)) : substr(md5(uniqid('', true)), 0, 10);
    $filename = date('Ymd-His') . '-' . slugify(pathinfo($original, PATHINFO_FILENAME)) . '-' . $unique . '.' . $ext;

    if (!@move_uploaded_file($tmp, $dir . '/' . $filename)) {
        $result['message'] = 'Gagal menyimpan file ke server.';
        return $result;
    }
    @chmod($dir . '/' . $filename, 0644);

    $result['ok'] = true;
    $result['filename'] = $filename;
    $result['message'] = 'Bukti berhasil diupload.';

    return $result;
}

/**
 * Hapus file bukti dengan aman (anti path traversal).
 *
 * @param int    $outingId
 * @param string $filename
 * @return bool
 */
function delete_bukti($outingId, $filename)
{
    $filename = basename((string) $filename);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return false;
    }
    $dir = realpath(outing_upload_dir($outingId));
    $path = realpath($dir . '/' . $filename);
    if ($path === false || $dir === false || strpos($path, $dir) !== 0) {
        return false;
    }
    if (is_file($path)) {
        return @unlink($path);
    }

    return false;
}

/**
 * Ambil path absolut bukti (tervalidasi) untuk di-stream ke browser.
 *
 * @return string|false
 */
function bukti_path($outingId, $filename)
{
    $filename = basename((string) $filename);
    $dir = realpath(outing_upload_dir($outingId));
    if ($dir === false || $filename === '') {
        return false;
    }
    $path = realpath($dir . '/' . $filename);
    if ($path === false || strpos($path, $dir) !== 0 || !is_file($path)) {
        return false;
    }

    return $path;
}

/** MIME untuk preview bukti di browser. */
function bukti_mime($filename)
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];

    return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
}

/* ==========================================================================
 * 9. DATA HELPER
 * ========================================================================== */

/** Ambil data outing by id atau null. */
function get_outing($outingId)
{
    return fetch_one('SELECT * FROM outings WHERE id = ? LIMIT 1', [(int) $outingId]);
}

/** Daftar kategori (id => nama). */
function get_categories_map()
{
    $rows = fetch_all('SELECT id, nama FROM categories ORDER BY nama ASC');
    $map = [];
    foreach ($rows as $r) {
        $map[$r['nama']] = (int) $r['id'];
    }

    return $map;
}

/** Ringkasan keuangan sebuah outing. */
function outing_finance($outingId)
{
    $outingId = (int) $outingId;

    $totalBelanja = (float) fetch_value(
        'SELECT COALESCE(SUM(total_harga),0) FROM purchases WHERE outing_id = ?',
        [$outingId],
        0
    );
    $totalIuran = (float) fetch_value(
        "SELECT COALESCE(SUM(nominal_bayar),0) FROM outing_participants
         WHERE outing_id = ? AND payment_status = 'Sudah'",
        [$outingId],
        0
    );
    $jmlPeserta = (int) fetch_value(
        "SELECT COUNT(*) FROM outing_participants WHERE outing_id = ? AND attendance_status = 'Ikut'",
        [$outingId],
        0
    );
    $jmlBatal = (int) fetch_value(
        "SELECT COUNT(*) FROM outing_participants WHERE outing_id = ? AND attendance_status = 'Batal'",
        [$outingId],
        0
    );
    $jmlTidak = (int) fetch_value(
        "SELECT COUNT(*) FROM outing_participants WHERE outing_id = ? AND attendance_status = 'Tidak Ikut'",
        [$outingId],
        0
    );
    $jmlLunas = (int) fetch_value(
        "SELECT COUNT(*) FROM outing_participants WHERE outing_id = ? AND payment_status = 'Sudah'",
        [$outingId],
        0
    );
    $jmlBelum = (int) fetch_value(
        "SELECT COUNT(*) FROM outing_participants WHERE outing_id = ? AND payment_status = 'Belum'",
        [$outingId],
        0
    );
    $totalPeserta = $jmlPeserta + $jmlBatal + $jmlTidak;

    return [
        'total_pengeluaran' => $totalBelanja,
        'total_iuran'       => $totalIuran,
        'ikut'              => $jmlPeserta,
        'batal'             => $jmlBatal,
        'tidak_ikut'        => $jmlTidak,
        'lunas'             => $jmlLunas,
        'belum_bayar'       => $jmlBelum,
        'total_peserta'     => $totalPeserta,
    ];
}

/** Pengeluaran per kategori sebuah outing. */
function outing_by_category($outingId)
{
    return fetch_all(
        'SELECT c.id, c.nama, COUNT(p.id) AS jumlah_item,
                COALESCE(SUM(p.total_harga),0) AS total
         FROM categories c
         LEFT JOIN purchases p ON p.category_id = c.id AND p.outing_id = ?
         GROUP BY c.id, c.nama
         ORDER BY total DESC, c.nama ASC',
        [(int) $outingId]
    );
}

/** Apakah user merupakan peserta outing tsb? */
function is_participant($userId, $outingId)
{
    $row = fetch_one(
        'SELECT id FROM outing_participants WHERE user_id = ? AND outing_id = ? LIMIT 1',
        [(int) $userId, (int) $outingId]
    );

    return $row !== null;
}

/** Buat kode outing otomatis: OUT-2026-001 */
function generate_outing_code()
{
    $year = date('Y');
    $last = fetch_value(
        "SELECT kode FROM outings WHERE kode LIKE ? ORDER BY id DESC LIMIT 1",
        ['OUT-' . $year . '-%'],
        ''
    );
    $next = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        $next = (int) $m[1] + 1;
    }

    return sprintf('OUT-%s-%03d', $year, $next);
}
