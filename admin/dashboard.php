<?php
/**
 * ==========================================================================
 *  ADMIN — admin/dashboard.php
 * --------------------------------------------------------------------------
 *  Ringkasan outing AKTIF (status Planning & Active): peserta, keuangan,
 *  pengeluaran per kategori, rundown terdekat, dan aktivitas terakhir.
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();

/* ------------------------------------------------------------------ data */
$statOutingAktif = (int) fetch_value("SELECT COUNT(*) FROM outings WHERE status IN ('Planning','Active')", [], 0);
$statHistory     = (int) fetch_value("SELECT COUNT(*) FROM outings WHERE status IN ('Selesai','Cancelled')", [], 0);
$statUserAktif   = (int) fetch_value("SELECT COUNT(*) FROM users WHERE status = 'aktif'", [], 0);
$statUserTotal   = (int) fetch_value("SELECT COUNT(*) FROM users", [], 0);
$statKategori    = (int) fetch_value("SELECT COUNT(*) FROM categories WHERE is_aktif = 1", [], 0);

$statPesertaIkut = (int) fetch_value(
    "SELECT COUNT(*) FROM outing_participants op
     JOIN outings o ON o.id = op.outing_id
     WHERE o.status IN ('Planning','Active') AND op.attendance_status = 'Ikut'",
    [], 0
);
$statBelumBayar = (int) fetch_value(
    "SELECT COUNT(*) FROM outing_participants op
     JOIN outings o ON o.id = op.outing_id
     WHERE o.status IN ('Planning','Active') AND op.attendance_status = 'Ikut' AND op.payment_status = 'Belum'",
    [], 0
);
$totalPengeluaran = (float) fetch_value(
    "SELECT COALESCE(SUM(p.total_harga),0) FROM purchases p
     JOIN outings o ON o.id = p.outing_id
     WHERE o.status IN ('Planning','Active')",
    [], 0
);
$totalIuran = (float) fetch_value(
    "SELECT COALESCE(SUM(op.nominal_bayar),0) FROM outing_participants op
     JOIN outings o ON o.id = op.outing_id
     WHERE o.status IN ('Planning','Active') AND op.payment_status = 'Sudah'",
    [], 0
);
$totalAnggaran = (float) fetch_value(
    "SELECT COALESCE(SUM(o.anggaran),0) FROM outings o WHERE o.status IN ('Planning','Active')",
    [], 0
);

$outingAktif = fetch_all(
    "SELECT o.*,
            (SELECT COUNT(*) FROM outing_participants op WHERE op.outing_id = o.id AND op.attendance_status = 'Ikut') AS jml_ikut,
            (SELECT COUNT(*) FROM outing_participants op WHERE op.outing_id = o.id) AS jml_peserta,
            (SELECT COALESCE(SUM(p.total_harga),0) FROM purchases p WHERE p.outing_id = o.id) AS total_belanja,
            (SELECT COUNT(*) FROM rundown r WHERE r.outing_id = o.id) AS jml_rundown
     FROM outings o
     WHERE o.status IN ('Planning','Active')
     ORDER BY FIELD(o.status,'Active','Planning'), o.tanggal_mulai ASC
     LIMIT 6"
);

$perKategori = fetch_all(
    "SELECT c.nama, COALESCE(SUM(p.total_harga),0) AS total, COUNT(p.id) AS jumlah
     FROM categories c
     LEFT JOIN purchases p ON p.category_id = c.id
        AND p.outing_id IN (SELECT id FROM outings WHERE status IN ('Planning','Active'))
     GROUP BY c.id, c.nama
     HAVING total > 0
     ORDER BY total DESC"
);

$rundownTerdekat = fetch_all(
    "SELECT r.*, o.nama AS outing_nama, o.kode AS outing_kode
     FROM rundown r
     JOIN outings o ON o.id = r.outing_id
     WHERE o.status IN ('Planning','Active')
     ORDER BY COALESCE(r.tanggal, o.tanggal_mulai) ASC, r.urutan ASC
     LIMIT 6"
);

$aktivitas = fetch_all(
    "SELECT l.aktivitas, l.created_at, l.tabel_ref, u.nama AS user_nama
     FROM activity_logs l
     LEFT JOIN users u ON u.id = l.user_id
     ORDER BY l.id DESC
     LIMIT 8"
);

$maxKategori = 0.0;
foreach ($perKategori as $k) {
    $maxKategori = max($maxKategori, (float) $k['total']);
}

$persenAnggaran = ($totalAnggaran > 0) ? min(100, round($totalPengeluaran / $totalAnggaran * 100)) : 0;

/* ---------------------------------------------------------------- layout */
$pageTitle = 'Dashboard Administrator';
$pageSubtitle = 'Ringkasan outing aktif, peserta, dan keuangan secara real-time.';
$activeMenu = 'dashboard';
$pageActions =
    '<a href="' . base_url('admin/outings.php?action=create') . '" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Outing Baru</a>'
    . '<a href="' . base_url('admin/import.php') . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Import Excel</a>';

require_once INCLUDES_PATH . '/header.php';
?>

<!-- ============================ STATISTIK ============================ -->
<div class="row g-3 mb-1">
  <div class="col-6 col-xl-3">
    <div class="card h-100"><div class="card-body stat-card">
      <span class="stat-icon bg-soft-primary"><i class="bi bi-calendar2-heart"></i></span>
      <div>
        <div class="stat-label">Outing Aktif</div>
        <div class="stat-value"><?php echo $statOutingAktif; ?></div>
        <div class="stat-sub"><?php echo $statHistory; ?> outing di history</div>
      </div>
    </div></div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card h-100"><div class="card-body stat-card">
      <span class="stat-icon bg-soft-success"><i class="bi bi-people-fill"></i></span>
      <div>
        <div class="stat-label">Peserta Ikut</div>
        <div class="stat-value"><?php echo $statPesertaIkut; ?></div>
        <div class="stat-sub text-danger"><?php echo $statBelumBayar; ?> belum bayar</div>
      </div>
    </div></div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card h-100"><div class="card-body stat-card">
      <span class="stat-icon bg-soft-warning"><i class="bi bi-cash-stack"></i></span>
      <div>
        <div class="stat-label">Pengeluaran</div>
        <div class="stat-value"><?php echo rupiah($totalPengeluaran, false); ?></div>
        <div class="stat-sub">dari anggaran <?php echo rupiah($totalAnggaran, false); ?></div>
      </div>
    </div></div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card h-100"><div class="card-body stat-card">
      <span class="stat-icon bg-soft-info"><i class="bi bi-wallet2"></i></span>
      <div>
        <div class="stat-label">Iuran Terkumpul</div>
        <div class="stat-value"><?php echo rupiah($totalIuran, false); ?></div>
        <div class="stat-sub">selisih <?php echo rupiah($totalIuran - $totalPengeluaran); ?></div>
      </div>
    </div></div>
  </div>
</div>

<div class="row g-3 mt-1">
  <!-- ======================= DAFTAR OUTING AKTIF ======================= -->
  <div class="col-xl-8">
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-broadcast text-success"></i>
        <span class="card-title-sm me-auto">Outing Aktif &amp; Perencanaan</span>
        <a href="<?php echo base_url('admin/outings.php'); ?>" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
      </div>
      <div class="card-body p-0">
        <?php if (count($outingAktif) === 0): ?>
          <div class="table-empty">
            <i class="bi bi-calendar-x"></i>
            Belum ada outing berstatus <strong>Planning</strong> atau <strong>Active</strong>.<br>
            <a href="<?php echo base_url('admin/outings.php?action=create'); ?>" class="btn btn-primary btn-sm mt-2">
              <i class="bi bi-plus-circle me-1"></i>Buat Outing Pertama
            </a>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Outing</th>
                  <th class="text-center">Status</th>
                  <th class="text-center">Peserta</th>
                  <th class="text-end">Pengeluaran</th>
                  <th class="text-center">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($outingAktif as $o): ?>
                <tr>
                  <td>
                    <div class="text-strong"><?php echo e($o['nama']); ?></div>
                    <div class="fs-8 text-muted">
                      <span class="badge badge-soft me-1"><?php echo e($o['kode']); ?></span>
                      <i class="bi bi-geo-alt"></i> <?php echo e($o['tujuan']); ?> &middot;
                      <i class="bi bi-calendar3"></i> <?php echo rentang_tanggal($o['tanggal_mulai'], $o['tanggal_selesai']); ?>
                    </div>
                    <?php if ((float) $o['anggaran'] > 0): ?>
                    <div class="progress progress-thin mt-1" style="max-width:220px" title="Penyerapan anggaran <?php echo round($o['total_belanja'] / $o['anggaran'] * 100); ?>%">
                      <div class="progress-bar <?php echo ($o['total_belanja'] > $o['anggaran']) ? 'bg-danger' : 'bg-primary'; ?>"
                           style="width: <?php echo min(100, round($o['total_belanja'] / $o['anggaran'] * 100)); ?>%"></div>
                    </div>
                    <?php endif; ?>
                  </td>
                  <td class="text-center"><?php echo badge_status_outing($o['status']); ?></td>
                  <td class="text-center">
                    <div class="fw-bold"><?php echo (int) $o['jml_ikut']; ?></div>
                    <div class="fs-8 text-muted">dari <?php echo (int) $o['jml_peserta']; ?> terdaftar</div>
                  </td>
                  <td class="text-end nowrap">
                    <div class="fw-bold"><?php echo rupiah($o['total_belanja']); ?></div>
                    <div class="fs-8 text-muted">anggaran <?php echo rupiah($o['anggaran'], false); ?></div>
                  </td>
                  <td class="text-center nowrap">
                    <a href="<?php echo base_url('admin/participants.php?outing_id=' . (int) $o['id']); ?>"
                       class="btn btn-sm btn-outline-primary btn-icon" title="Kelola outing" data-bs-toggle="tooltip">
                      <i class="bi bi-sliders"></i>
                    </a>
                    <a href="<?php echo base_url('admin/export_pdf.php?outing_id=' . (int) $o['id']); ?>" target="_blank" rel="noopener"
                       class="btn btn-sm btn-outline-danger btn-icon" title="Export PDF" data-bs-toggle="tooltip">
                      <i class="bi bi-file-earmark-pdf"></i>
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ==================== PENGELUARAN PER KATEGORI ==================== -->
    <div class="card">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-pie-chart text-primary"></i>
        <span class="card-title-sm me-auto">Total Pengeluaran per Kategori <small class="text-muted fw-normal">(outing aktif)</small></span>
        <a href="<?php echo base_url('admin/categories.php'); ?>" class="btn btn-sm btn-outline-secondary">Kelola Kategori</a>
      </div>
      <div class="card-body">
        <?php if (count($perKategori) === 0): ?>
          <p class="text-muted mb-0 small"><i class="bi bi-inbox me-1"></i>Belum ada pengeluaran tercatat.</p>
        <?php else: ?>
          <?php foreach ($perKategori as $k):
              $pct = ($maxKategori > 0) ? round((float) $k['total'] / $maxKategori * 100) : 0;
              $share = ($totalPengeluaran > 0) ? round((float) $k['total'] / $totalPengeluaran * 100) : 0;
          ?>
            <div class="bar-chart-row">
              <span class="bar-chart-label" title="<?php echo e($k['nama']); ?>"><?php echo e(str_limit($k['nama'], 22)); ?></span>
              <span class="bar-chart-track"><span class="bar-chart-fill" style="width: <?php echo $pct; ?>%"></span></span>
              <span class="bar-chart-value">
                <?php echo rupiah($k['total'], false); ?>
                <small class="text-muted d-block fw-normal"><?php echo $share; ?>% &middot; <?php echo (int) $k['jumlah']; ?> item</small>
              </span>
            </div>
          <?php endforeach; ?>
          <hr class="my-2">
          <div class="d-flex justify-content-between small">
            <span class="text-muted">Total pengeluaran outing aktif</span>
            <strong><?php echo rupiah($totalPengeluaran); ?></strong>
          </div>
          <div class="d-flex justify-content-between small">
            <span class="text-muted">Penyerapan anggaran</span>
            <strong class="<?php echo $persenAnggaran >= 100 ? 'text-danger' : 'text-success'; ?>"><?php echo $persenAnggaran; ?>%</strong>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ============================ KOLOM KANAN ============================ -->
  <div class="col-xl-4">
    <!-- Rundown terdekat -->
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-clock-history text-warning"></i>
        <span class="card-title-sm me-auto">Rundown Terdekat</span>
      </div>
      <div class="card-body">
        <?php if (count($rundownTerdekat) === 0): ?>
          <p class="text-muted small mb-0"><i class="bi bi-inbox me-1"></i>Belum ada rundown.</p>
        <?php else: ?>
          <div class="timeline">
            <?php foreach ($rundownTerdekat as $r): ?>
              <div class="timeline-item">
                <span class="timeline-dot"><i class="bi bi-dot"></i></span>
                <div class="timeline-card py-2">
                  <div class="timeline-time">
                    <?php echo $r['tanggal'] ? tgl_indo($r['tanggal']) : '-'; ?>
                    &middot; <?php echo jam($r['waktu_mulai']); ?>
                  </div>
                  <div class="timeline-title"><?php echo e($r['acara']); ?></div>
                  <div class="timeline-meta">
                    <i class="bi bi-flag"></i><?php echo e($r['outing_nama']); ?>
                    <?php if (!empty($r['lokasi'])): ?> &middot; <i class="bi bi-geo-alt"></i><?php echo e($r['lokasi']); ?><?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Aktivitas terakhir -->
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-activity text-success"></i>
        <span class="card-title-sm me-auto">Aktivitas Terakhir</span>
        <a href="<?php echo base_url('admin/activity.php'); ?>" class="btn btn-sm btn-outline-secondary">Semua</a>
      </div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          <?php if (count($aktivitas) === 0): ?>
            <li class="list-group-item text-muted small">Belum ada aktivitas.</li>
          <?php else: ?>
            <?php foreach ($aktivitas as $a): ?>
            <li class="list-group-item py-2">
              <div class="small"><?php echo e($a['aktivitas']); ?></div>
              <div class="fs-8 text-muted">
                <i class="bi bi-person"></i> <?php echo e($a['user_nama'] ?: 'Sistem'); ?>
                &middot; <?php echo date('d/m/Y H:i', strtotime($a['created_at'])); ?>
              </div>
            </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>
    </div>

    <!-- Alur kerja -->
    <div class="card">
      <div class="card-header"><span class="card-title-sm"><i class="bi bi-signpost-split me-1 text-primary"></i>Alur Kerja Sistem</span></div>
      <div class="card-body">
        <ol class="small mb-0 ps-3">
          <li class="mb-1">Buat outing &amp; isi data dasar</li>
          <li class="mb-1">Tambah peserta (manual / import Excel)</li>
          <li class="mb-1">Susun rundown acara</li>
          <li class="mb-1">Input pembelian + upload bukti</li>
          <li class="mb-1">Set status <strong>Selesai</strong></li>
          <li class="mb-1">Update pembayaran akhir peserta</li>
          <li>Download laporan PDF</li>
        </ol>
        <div class="divider-soft"></div>
        <div class="d-flex justify-content-between small text-muted">
          <span>Pengguna aktif</span><strong><?php echo $statUserAktif; ?> / <?php echo $statUserTotal; ?></strong>
        </div>
        <div class="d-flex justify-content-between small text-muted">
          <span>Kategori biaya</span><strong><?php echo $statKategori; ?></strong>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
