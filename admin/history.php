<?php
/**
 * ==========================================================================
 *  ADMIN — admin/history.php  (DASHBOARD HISTORY OUTING)
 * --------------------------------------------------------------------------
 *  Outing berstatus "Selesai" dan "Cancelled" OTOMATIS masuk ke halaman ini.
 *  Default bersifat READ-ONLY (hanya lihat + export PDF), tetapi Admin diberi
 *  tombol "Edit History" dengan dialog konfirmasi bila perlu koreksi.
 *  Data lama TIDAK PERNAH tertimpa oleh pembuatan outing baru.
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();

$filterStatus = in_enum(get_str('status'), ['Selesai', 'Cancelled', 'Semua'], 'Semua');
$filterTahun = get_str('tahun');
$filterTujuan = get_str('tujuan');
$cari = get_str('q');

$where = ["o.status IN ('Selesai','Cancelled')"];
$params = [];

if ($filterStatus !== 'Semua') {
    $where[] = 'o.status = ?';
    $params[] = $filterStatus;
}
if ($filterTahun !== '' && preg_match('/^\d{4}$/', $filterTahun)) {
    $where[] = '(YEAR(o.tanggal_mulai) = ? OR YEAR(o.tanggal_selesai) = ?)';
    $params[] = (int) $filterTahun;
    $params[] = (int) $filterTahun;
}
if ($filterTujuan !== '') {
    $where[] = 'o.tujuan = ?';
    $params[] = $filterTujuan;
}
if ($cari !== '') {
    $where[] = '(o.nama LIKE ? OR o.kode LIKE ? OR o.tujuan LIKE ? OR o.lokasi LIKE ?)';
    $like = '%' . $cari . '%';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$history = fetch_all(
    "SELECT o.*,
            (SELECT COUNT(*) FROM outing_participants op WHERE op.outing_id = o.id) AS jml_peserta,
            (SELECT COUNT(*) FROM outing_participants op WHERE op.outing_id = o.id AND op.attendance_status = 'Ikut') AS jml_ikut,
            (SELECT COUNT(*) FROM outing_participants op WHERE op.outing_id = o.id AND op.payment_status = 'Sudah') AS jml_lunas,
            (SELECT COALESCE(SUM(p.total_harga),0) FROM purchases p WHERE p.outing_id = o.id) AS total_belanja,
            (SELECT COALESCE(SUM(op.nominal_bayar),0) FROM outing_participants op
              WHERE op.outing_id = o.id AND op.payment_status = 'Sudah') AS total_iuran
     FROM outings o
     WHERE $whereSql
     ORDER BY o.tanggal_mulai DESC, o.id DESC
     LIMIT 300",
    $params
);

$daftarTahun = fetch_all(
    "SELECT DISTINCT YEAR(tanggal_mulai) AS th FROM outings
     WHERE status IN ('Selesai','Cancelled') ORDER BY th DESC"
);
$daftarTujuan = fetch_all(
    "SELECT DISTINCT tujuan FROM outings
     WHERE status IN ('Selesai','Cancelled') ORDER BY tujuan ASC"
);

$ringkas = [
    'jumlah' => count($history),
    'peserta' => 0,
    'belanja' => 0.0,
    'iuran' => 0.0,
    'selesai' => 0,
    'cancelled' => 0,
];
foreach ($history as $h) {
    $ringkas['peserta'] += (int) $h['jml_ikut'];
    $ringkas['belanja'] += (float) $h['total_belanja'];
    $ringkas['iuran'] += (float) $h['total_iuran'];
    if ($h['status'] === 'Selesai') {
        $ringkas['selesai']++;
    } else {
        $ringkas['cancelled']++;
    }
}

$pageTitle = 'History Outing';
$pageSubtitle = 'Arsip outing <strong>Selesai</strong> &amp; <strong>Cancelled</strong> — read-only secara default, dapat dikoreksi Administrator.';
$activeMenu = 'history';

require_once INCLUDES_PATH . '/header.php';
?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-primary"><i class="bi bi-archive"></i></span>
      <div><div class="stat-label">Total History</div><div class="stat-value"><?php echo $ringkas['jumlah']; ?></div>
        <div class="stat-sub"><?php echo $ringkas['selesai']; ?> selesai &middot; <?php echo $ringkas['cancelled']; ?> batal</div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-success"><i class="bi bi-people"></i></span>
      <div><div class="stat-label">Total Peserta Ikut</div><div class="stat-value"><?php echo $ringkas['peserta']; ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-danger"><i class="bi bi-cash-stack"></i></span>
      <div><div class="stat-label">Total Pengeluaran</div><div class="stat-value fs-5"><?php echo rupiah($ringkas['belanja'], false); ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-info"><i class="bi bi-wallet2"></i></span>
      <div><div class="stat-label">Total Iuran</div><div class="stat-value fs-5"><?php echo rupiah($ringkas['iuran'], false); ?></div></div>
    </div></div>
  </div>
</div>

<!-- ============================== FILTER ============================== -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" action="<?php echo base_url('admin/history.php'); ?>" class="row g-2 align-items-end">
      <div class="col-6 col-md-3">
        <label class="form-label mb-1" for="q">Cari</label>
        <input type="text" class="form-control form-control-sm" id="q" name="q" value="<?php echo e($cari); ?>" placeholder="nama / kode / tujuan">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label mb-1" for="tahun">Tahun</label>
        <select class="form-select form-select-sm" id="tahun" name="tahun">
          <option value="">Semua Tahun</option>
          <?php foreach ($daftarTahun as $t): ?>
            <option value="<?php echo (int) $t['th']; ?>" <?php echo ($filterTahun === (string) $t['th']) ? 'selected' : ''; ?>><?php echo (int) $t['th']; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label mb-1" for="status">Status</label>
        <select class="form-select form-select-sm" id="status" name="status">
          <?php foreach (['Semua', 'Selesai', 'Cancelled'] as $s): ?>
            <option value="<?php echo $s; ?>" <?php echo ($filterStatus === $s) ? 'selected' : ''; ?>><?php echo ($s === 'Semua') ? 'Semua Status' : $s; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label mb-1" for="tujuan">Tujuan</label>
        <select class="form-select form-select-sm" id="tujuan" name="tujuan">
          <option value="">Semua Tujuan</option>
          <?php foreach ($daftarTujuan as $t): ?>
            <option value="<?php echo e($t['tujuan']); ?>" <?php echo ($filterTujuan === $t['tujuan']) ? 'selected' : ''; ?>><?php echo e($t['tujuan']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
        <a href="<?php echo base_url('admin/history.php'); ?>" class="btn btn-sm btn-light">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center gap-2">
    <span class="card-title-sm me-auto"><i class="bi bi-archive me-1 text-primary"></i>Arsip Outing</span>
    <span class="badge bg-light text-dark border"><?php echo count($history); ?> data</span>
  </div>
  <div class="card-body p-0">
    <?php if (count($history) === 0): ?>
      <div class="table-empty">
        <i class="bi bi-archive"></i> Belum ada outing berstatus <strong>Selesai</strong> atau <strong>Cancelled</strong>.
        <div class="mt-2"><a href="<?php echo base_url('admin/outings.php'); ?>" class="btn btn-sm btn-outline-primary">Lihat Outing Aktif</a></div>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>Kode</th>
            <th>Nama &amp; Tujuan</th>
            <th class="nowrap">Tanggal</th>
            <th class="text-center">Status</th>
            <th class="text-center">Peserta</th>
            <th class="text-center">Lunas</th>
            <th class="text-end">Pengeluaran</th>
            <th class="text-end">Iuran</th>
            <th class="text-center nowrap">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): $oid = (int) $h['id']; ?>
          <tr>
            <td><span class="badge badge-soft"><?php echo e($h['kode']); ?></span></td>
            <td>
              <a href="<?php echo base_url('admin/outings.php?action=view&outing_id=' . $oid); ?>" class="text-strong"><?php echo e($h['nama']); ?></a>
              <div class="fs-8 text-muted"><i class="bi bi-geo-alt"></i> <?php echo e($h['tujuan']); ?><?php echo $h['lokasi'] ? ' &middot; ' . e(str_limit($h['lokasi'], 26)) : ''; ?></div>
            </td>
            <td class="fs-8 nowrap"><?php echo rentang_tanggal($h['tanggal_mulai'], $h['tanggal_selesai']); ?></td>
            <td class="text-center"><?php echo badge_status_outing($h['status']); ?></td>
            <td class="text-center"><strong><?php echo (int) $h['jml_ikut']; ?></strong><span class="fs-8 text-muted">/<?php echo (int) $h['jml_peserta']; ?></span></td>
            <td class="text-center"><?php echo (int) $h['jml_lunas']; ?></td>
            <td class="text-end nowrap fw-semibold"><?php echo rupiah($h['total_belanja'], false); ?></td>
            <td class="text-end nowrap fs-8"><?php echo rupiah($h['total_iuran'], false); ?></td>
            <td class="text-center nowrap">
              <a href="<?php echo base_url('admin/outings.php?action=view&outing_id=' . $oid); ?>" class="btn btn-sm btn-outline-secondary btn-icon" title="Lihat ringkasan" data-bs-toggle="tooltip"><i class="bi bi-eye"></i></a>
              <a href="<?php echo base_url('admin/export_pdf.php?outing_id=' . $oid . '&download=1'); ?>" class="btn btn-sm btn-outline-danger btn-icon" title="Download PDF" data-bs-toggle="tooltip"><i class="bi bi-file-earmark-pdf"></i></a>
              <button type="button" class="btn btn-sm btn-outline-warning btn-icon"
                      title="Edit History (memerlukan konfirmasi)" data-bs-toggle="tooltip"
                      onclick="omsConfirm({title:'Edit Data History',message:'Anda akan mengoreksi data HISTORY <strong><?php echo e(addslashes($h['nama'])); ?></strong>.<br>Data ini sudah diarsipkan dan bersifat read-only bagi member. Lanjutkan?',confirmText:'Ya, Saya Perlu Koreksi',confirmClass:'btn-warning',danger:false}).then(function(ok){ if(ok){ window.location.href='<?php echo base_url('admin/outings.php?action=edit&outing_id=' . $oid); ?>'; } });">
                <i class="bi bi-pencil-square"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <div class="card-footer bg-white small text-muted">
    <i class="bi bi-shield-check me-1"></i>
    History bersifat <strong>read-only</strong> untuk member. Tombol <strong>Edit History</strong> hanya tersedia bagi Administrator dan selalu meminta konfirmasi.
    Membuat outing baru tidak pernah menimpa atau menghapus data history.
  </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
