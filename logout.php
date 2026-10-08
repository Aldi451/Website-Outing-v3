<?php
/**
 * ==========================================================================
 *  OUTING MANAGEMENT SYSTEM — logout.php
 * --------------------------------------------------------------------------
 *  Logout HANYA menerima method POST + token CSRF (mencegah logout paksa
 *  lewat gambar/link dari situs lain).
 * ==========================================================================
 */

require_once __DIR__ . '/includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Bukan request POST: kembalikan ke halaman semula
    if (is_logged_in()) {
        redirect(is_admin() ? 'admin/dashboard.php' : 'member/dashboard.php');
    }
    redirect('index.php');
}

csrf_verify();

$nama = current_nama();
$uid = current_uid();

if ($uid > 0) {
    log_activity('Logout', 'users', $uid);
}

do_logout();

// do_logout() menghancurkan session, mulai lagi untuk menaruh pesan
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
flash('info', 'Anda (' . e($nama) . ') telah keluar dari sistem. Sampai jumpa!');

redirect('index.php');
