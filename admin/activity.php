<?php
/**
 * ==========================================================================
 *  ADMIN — admin/activity.php  (LOG AKTIVITAS / AUDIT TRAIL)
 * --------------------------------------------------------------------------
 *  Menampilkan jejak aktivitas: siapa, apa, kapan, dari IP mana.
 *  Fitur: filter user/rentang tanggal/kata kunci + paginasi + bersihkan log lama.
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();

/* ------------------------------------------------------- bersihkan log */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (post_str('task') === 'purge') {
        $batasHari = post_int('hari', 30);
        if ($batasHari < 1) {
            $batasHari = 30;
        }
        $dihapus = run_query('DELETE FROM activity_logs WHERE created_at < (NOW() - INTERVAL ? DAY)', [$batasHari]);
        log_activity('Bersihkan log aktivitas lebih dari ' . $batasHari . ' hari (' . $dihapus . ' baris)', 'activity_logs', 0);
        flash('success', '<strong>' . (int) $dihapus . '</strong> baris log lebih dari ' . $batasHari . ' hari berhasil dihapus.');
        redirect('admin/activity.php');
    }
    flash('danger', 'Perintah tidak dikenali.');
    redirect('admin/activity.php');
}

/* ------------------------------------------------------------- filter */
$cari = get_str('q');
$filterUser = get_int('user_id', 0);
$dari = parse_date(get_str('dari'));
$sampai = parse_date(get_str('sampai'));
$page = max(1, get_int('page', 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = ['1 = 1'];
$params = [];
if ($cari !== '') {
    $where[] = '(l.aktivitas LIKE ? OR l.tabel_ref LIKE ? OR l.ip_address LIKE ?)';
    array_push($params, '%' . $cari . '%', '%' . $cari . '%', '%' . $cari . '%');
}
if ($filterUser > 0) {
    $where[] = 'l.user_id = ?';
    $params[] = $filterUser;
}
if ($dari !== null) {
    $where[] = 'DATE(l.created_at) >= ?';
    $params[] = $dari;
}
if ($sampai !== null) {
    $where[] = 'DATE(l.created_at) <= ?';
    $params[] = $sampai;
}
$whereSql = implode(' AND ', $where);

$total = (int) fetch_value("SELECT COUNT(*) FROM activity_logs l WHERE $whereSql", $params, 0);
$totalPage = max(1, (int) ceil($total / $perPage));
if ($page > $totalPage) {
    $page = $totalPage;
    $offset = ($page - 1) * $perPage;
}

$logs = fetch_all(
    "SELECT l.*, u.nama AS user_nama, u.user_id AS user_login, u.role
     FROM activity_logs l
     LEFT JOIN users u ON u.id = l.user_id
     WHERE $whereSql
     ORDER BY l.id DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

$daftarUser = fetch_all('SELECT id, nama, user_id FROM users ORDER BY nama ASC');

$qs = array_filter([
    'q' => $cari, 'user_id' => $filterUser ?: '', 'dari' => $dari ?: '', 'sampai' => $sampai ?: '',
], function ($v) {
    return $v !== '' && $v !== null;
});

$pageTitle = 'Log Aktivitas';
$pageSubtitle = 'Jejak audit seluruh tindakan pada sistem (' . $total . ' baris tercatat).';
$activeMenu = 'activity';

require_once INCLUDES_PATH . '/header.php';
?>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" action="<?php echo base_url('admin/activity.php'); ?>" class="row g-2 align-items-end">
      <div class="col-6 col-md-3">
        <label class="form-label mb-1" for="q">Cari aktivitas</label>
        <input type="text" class="form-control form-control-sm" id="q" name="q" value="<?php echo e($cari); ?>" placeholder="kata kunci / tabel / IP">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label mb-1" for="user_id">Pengguna</label>
        <select class="form-select form-select-sm" id="user_id" name="user_id">
          <option value="0">Semua Pengguna</option>
          <?php foreach ($daftarUser as $du): ?>
            <option value="<?php echo (int) $du['id']; ?>" <?php echo ($filterUser === (int) $du['id']) ? 'selected' : ''; ?>>
              <?php echo e($du['nama'] . ' (' . $du['user_id'] . ')'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-5 col-md-2">
        <label class="form-label mb-1" for="dari">Dari Tanggal</label>
        <input type="date" class="form-control form-control-sm" id="dari" name="dari" value="<?php echo e($dari ?: ''); ?>">
      </div>
      <div class="col-5 col-md-2">
        <label class="form-label mb-1" for="sampai">Sampai Tanggal</label>
        <input type="date" class="form-control form-control-sm" id="sampai" name="sampai" value="<?php echo e($sampai ?: ''); ?>">
      </div>
      <div class="col-2 col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i></button>
        <a href="<?php echo base_url('admin/activity.php'); ?>" class="btn btn-sm btn-light">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex flex-wrap align-items-center gap-2">
    <span class="card-title-sm me-auto"><i class="bi bi-clock-history me-1 text-success"></i>Riwayat Aktivitas</span>
    <form method="post" action="<?php echo base_url('admin/activity.php'); ?>" class="d-flex gap-2 align-items-center"
          data-confirm="Hapus seluruh log aktivitas yang lebih tua dari jumlah hari yang dipilih?<br>Tindakan ini tidak dapat dibatalkan."
          data-confirm-title="Bersihkan Log Lama" data-confirm-text="Ya, Hapus Log">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="task" value="purge">
      <select name="hari" class="form-select form-select-sm" style="max-width:130px">
        <option value="7">7 hari</option>
        <option value="30" selected>30 hari</option>
        <option value="90">90 hari</option>
        <option value="365">1 tahun</option>
      </select>
      <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-eraser me-1"></i>Bersihkan</button>
    </form>
  </div>

  <div class="card-body p-0">
    <?php if (count($logs) === 0): ?>
      <div class="table-empty"><i class="bi bi-journal-x"></i>Tidak ada log yang cocok dengan filter.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table align-middle">
        <thead>
          <tr>
            <th style="width:150px" class="nowrap">Waktu</th>
            <th style="width:220px">Pengguna</th>
            <th>Aktivitas</th>
            <th style="width:170px">Referensi</th>
            <th style="width:130px">IP Address</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $l): ?>
          <tr>
            <td class="fs-8 nowrap">
              <div class="text-strong"><?php echo date('d/m/Y', strtotime($l['created_at'])); ?></div>
              <div class="text-muted"><?php echo date('H:i:s', strtotime($l['created_at'])); ?></div>
            </td>
            <td>
              <?php if ($l['user_nama'] !== null): ?>
                <div class="text-strong"><?php echo e($l['user_nama']); ?></div>
                <div class="fs-8 text-muted"><?php echo e($l['user_login']); ?> &middot; <?php echo badge_role($l['role']); ?></div>
              <?php else: ?>
                <span class="text-muted fs-8"><i class="bi bi-person-dash"></i> Sistem / tamu</span>
              <?php endif; ?>
            </td>
            <td><?php echo e($l['aktivitas']); ?></td>
            <td class="fs-8">
              <?php if (!empty($l['tabel_ref'])): ?>
                <span class="badge badge-soft"><?php echo e($l['tabel_ref']); ?></span>
                <?php if ((int) $l['ref_id'] > 0): ?>
                  <span class="text-muted">#<?php echo (int) $l['ref_id']; ?></span>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
            <td class="fs-8 text-muted"><i class="bi bi-hdd-network"></i> <?php echo e($l['ip_address']); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($totalPage > 1): ?>
  <div class="card-footer bg-white d-flex flex-wrap align-items-center gap-2">
    <span class="small text-muted me-auto">
      Halaman <strong><?php echo $page; ?></strong> dari <strong><?php echo $totalPage; ?></strong> &middot; <?php echo $total; ?> baris
    </span>
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
          <a class="page-link" href="<?php echo base_url('admin/activity.php?' . http_build_query(array_merge($qs, ['page' => $page - 1]))); ?>">&laquo;</a>
        </li>
        <?php
        $mulaiP = max(1, $page - 2);
        $akhirP = min($totalPage, $page + 2);
        for ($i = $mulaiP; $i <= $akhirP; $i++):
        ?>
          <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
            <a class="page-link" href="<?php echo base_url('admin/activity.php?' . http_build_query(array_merge($qs, ['page' => $i]))); ?>"><?php echo $i; ?></a>
          </li>
        <?php endfor; ?>
        <li class="page-item <?php echo ($page >= $totalPage) ? 'disabled' : ''; ?>">
          <a class="page-link" href="<?php echo base_url('admin/activity.php?' . http_build_query(array_merge($qs, ['page' => $page + 1]))); ?>">&raquo;</a>
        </li>
      </ul>
    </nav>
  </div>
  <?php endif; ?>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
