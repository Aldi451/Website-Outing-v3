<?php
/**
 * ==========================================================================
 *  OUTING MANAGEMENT SYSTEM — includes/activity.php
 * --------------------------------------------------------------------------
 *  Pencatatan jejak aktivitas (audit trail) ke tabel activity_logs.
 *  Kegagalan mencatat TIDAK boleh menghentikan proses utama, jadi dibungkus
 *  try/catch dan tidak melempar error ke pengguna.
 * ==========================================================================
 */

defined('APP_STARTED') or exit('Direct access is not allowed.');

/** Ambil IP klien secara aman. */
function client_ip()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = '0.0.0.0';
    }

    return substr($ip, 0, 45);
}

/**
 * Catat aktivitas.
 *
 * @param string $aktivitas deskripsi singkat (mis. "Tambah pembelian")
 * @param string $tabel     nama tabel terkait (opsional)
 * @param int    $refId     id data terkait (opsional)
 * @return void
 */
function log_activity($aktivitas, $tabel = '', $refId = 0)
{
    try {
        $uid = current_uid();
        run_query(
            'INSERT INTO activity_logs (user_id, aktivitas, tabel_ref, ref_id, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [
                $uid > 0 ? $uid : null,
                substr((string) $aktivitas, 0, 255),
                substr((string) $tabel, 0, 50),
                (int) $refId,
                client_ip(),
            ]
        );
    } catch (Exception $ex) {
        // Abaikan: audit log tidak boleh merusak proses utama
    }
}
