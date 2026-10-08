<?php
/**
 * ==========================================================================
 *  MEMBER — member/dashboard.php  (100% VIEW ONLY)
 * --------------------------------------------------------------------------
 *  Menampilkan outing yang diikuti: aktif (Planning/Active) & history
 *  (Selesai/Cancelled), plus status diri (kehadiran & pembayaran).
 *  TIDAK ADA aksi tulis pada halaman ini.
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_member();

$uid = current_uid();

$dataDiri = fetch_one(
    'SELECT id, user_id, nama, jabatan, departemen, no_hp, email, last_login FROM users WHERE id = ? LIMIT 1',
    [$uid]
);
if ($dataDiri === null) {
    do_logout();
    redirect('index.php');
}

$ikutAktif = fetch_all(
    "SELECT o.*, op.attendance_status, op.payment_status, op.nominal_bayar, op.tanggal_bayar, op.id AS pid,
            (SELECT COUNT(*) FROM outing_participants x WHERE x.outing_id = o.id AND x.attendance_status = 'Ikut') AS jml_ikut,
            (SELECT COUNT(*) FROM outing_participants x WHERE x.outing_id = o.id AND x.payment_status = 'Sudah') AS jml_lunas,
            (SELECT COALESCE(SUM(p.total_harga),0) FROM purchases p WHERE p.outing_id = o.id) AS total_belanja,
            (SELECT COUNT(*) FROM rundown r WHERE r.outing_id = o.id) AS jml_rundown
     FROM outing_participants op
     JOIN outings o ON o.id = op.outing_id
     WHERE op.user_id = ? AND o.status IN ('Planning','Active')
     ORDER BY FIELD(o.status,'Active','Planning'), o.tanggal_mulai ASC",
    [$uid]
);

$ikutHistory = fetch_all(
    "SELECT o.*, op.attendance_status, op.payment_status, op.nominal_bayar, op.tanggal_bayar, op.id AS pid,
            (SELECT COALESCE(SUM(p.total_harga),0) FROM purchases p WHERE p.outing_id = o.id) AS total_belanja
     FROM outing_participants op
     JOIN outings o ON o.id = op.outing_id
     WHERE op.user_id = ? AND o.status IN ('Selesai','Cancelled')
     ORDER BY o.tanggal_mulai DESC",
    [$uid]
);

$totalOuting = count($ikutAktif) + count($ikutHistory);
$totalIuranSaya = 0.0;
$tagihanBelum = 0.0;
foreach (array_merge($ikutAktif, $ikutHistory) as $o) {
    if ($o['payment_status'] === 'Sudah') {
        $totalIuranSaya += (float) $o['nominal_bayar'];
    } elseif ($o['attendance_status'] === 'Ikut') {
        $tagihanBelum += (float) $o['iuran_per_orang'];
    }
}

/** Hitung hari menuju keberangkatan. */
function sisa_hari($tanggal)
{
    $ts = strtotime($tanggal . ' 00:00:00');
    if ($ts === false) {
        return null;
    }
    $today = strtotime(date('Y-m-d') . ' 00:00:00');

    return (int) round(($ts - $today) / 86400);
}

$pageTitle = 'Dashboard Member';
$pageSubtitle = 'Halo <strong>' . e($dataDiri['nama']) . '</strong> — berikut outing yang Anda ikuti.';
$activeMenu = 'dashboard';

require_once INCLUDES_PATH . '/header.php';
?>

<!-- ============================ HERO ============================ -->
<div class="member-hero mb-3">
  <div class="d-flex flex-wrap align-items-center gap-3">
    <div class="me-auto">
      <h2><i class="bi bi-person-circle me-2"></i><?php echo e($dataDiri['nama']); ?></h2>
      <div class="hero-sub">
        <?php echo e($dataDiri['user_id']); ?>
        <?php if (!empty($dataDiri['jabatan'])): ?> &middot; <?php echo e($dataDiri['jabatan']); ?><?php endif; ?>
        <?php if (!empty($dataDiri['departemen'])): ?> &middot; <?php echo e($dataDiri['departemen']); ?><?php endif; ?>
      </div>
    </div>
    <div class="text-end">
      <div class="hero-sub">Total outing diikuti</div>
      <div class="fs-3 fw-bold lh-1"><?php echo $totalOuting; ?></div>
      <div class="hero-sub">Iuran dibayar <?php echo rupiah($totalIuranSaya, false); ?></div>
    </div>
  </div>
</div>

<!-- ============================ STATISTIK DIRI ============================ -->
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-primary"><i class="bi bi-calendar2-heart"></i></span>
      <div><div class="stat-label">Outing Aktif</div><div class="stat-value"><?php echo count($ikutAktif); ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-success"><i class="bi bi-archive"></i></span>
      <div><div class="stat-label">History</div><div class="stat-value"><?php echo count($ikutHistory); ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-info"><i class="bi bi-wallet2"></i></span>
      <div><div class="stat-label">Sudah Dibayar</div><div class="stat-value fs-5"><?php echo rupiah($totalIuranSaya, false); ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body stat-card py-3">
      <span class="stat-icon <?php echo $tagihanBelum > 0 ? 'bg-soft-danger' : 'bg-soft-success'; ?>"><i class="bi bi-hourglass-split"></i></span>
      <div><div class="stat-label">Tagihan Tersisa</div><div class="stat-value fs-5"><?php echo rupiah($tagihanBelum, false); ?></div></div>
    </div></div>
  </div>
</div>

<!-- ============================ OUTING AKTIF ============================ -->
<div class="card mb-3">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-broadcast text-success"></i>
    <span class="card-title-sm me-auto">Outing Aktif Saya</span>
    <span class="badge bg-light text-dark border"><?php echo count($ikutAktif); ?> outing</span>
  </div>
  <div class="card-body">
    <?php if (count($ikutAktif) === 0): ?>
      <div class="table-empty py-4">
        <i class="bi bi-emoji-neutral"></i>
        Saat ini Anda belum terdaftar pada outing yang berstatus <strong>Planning</strong> atau <strong>Active</strong>.<br>
        <span class="small">Hubungi Administrator bila Anda seharusnya terdaftar.</span>
      </div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($ikutAktif as $o):
            $oid = (int) $o['id'];
            $sisa = sisa_hari($o['tanggal_mulai']);
            $targetIuran = (int) $o['jml_ikut'] * (float) $o['iuran_per_orang'];
            $persen = ($targetIuran > 0) ? min(100, round((float) $o['jml_lunas'] / max(1, (int) $o['jml_ikut']) * 100)) : 0;
        ?>
        <div class="col-md-6 col-xl-4">
          <div class="card h-100 outing-card">
            <div class="card-body">
              <div class="d-flex align-items-start gap-2 mb-2">
                <div class="me-auto">
                  <h6 class="mb-1"><?php echo e($o['nama']); ?></h6>
                  <div class="fs-8 text-muted">
                    <span class="badge badge-soft me-1"><?php echo e($o['kode']); ?></span>
                    <?php echo badge_status_outing($o['status']); ?>
                  </div>
                </div>
              </div>

              <div class="fs-8 text-muted mb-2">
                <div><i class="bi bi-geo-alt me-1"></i><?php echo e($o['tujuan']); ?><?php echo $o['lokasi'] ? ' — ' . e(str_limit($o['lokasi'], 30)) : ''; ?></div>
                <div><i class="bi bi-calendar3 me-1"></i><?php echo rentang_tanggal($o['tanggal_mulai'], $o['tanggal_selesai']); ?></div>
                <?php if ($sisa !== null): ?>
                  <div>
                    <i class="bi bi-alarm me-1"></i>
                    <?php if ($sisa > 0): ?>
                      <span class="text-primary fw-semibold"><?php echo $sisa; ?> hari lagi</span>
                    <?php elseif ($sisa === 0): ?>
                      <span class="text-success fw-semibold">Berangkat hari ini!</span>
                    <?php else: ?>
                      <span class="text-muted">Sedang/pernah berlangsung</span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>

              <div class="status-self mb-2">
                <div class="row g-2">
                  <div class="col-6">
                    <div class="label">Kehadiran Saya</div>
                    <div class="value fs-7"><?php echo badge_attendance($o['attendance_status']); ?></div>
                  </div>
                  <div class="col-6">
                    <div class="label">Pembayaran Saya</div>
                    <div class="value fs-7"><?php echo badge_payment($o['payment_status']); ?></div>
                  </div>
                  <div class="col-6">
                    <div class="label">Iuran Standar</div>
                    <div class="value fs-7"><?php echo rupiah($o['iuran_per_orang'], false); ?></div>
                  </div>
                  <div class="col-6">
                    <div class="label"><?php echo $o['payment_status'] === 'Sudah' ? 'Sudah Dibayar' : 'Tagihan Saya'; ?></div>
                    <div class="value fs-7">
                      <?php echo $o['payment_status'] === 'Sudah'
                          ? rupiah($o['nominal_bayar'], false)
                          : rupiah((float) $o['iuran_per_orang'], false); ?>
                    </div>
                  </div>
                </div>
              </div>

              <div class="fs-8 text-muted mb-1 d-flex justify-content-between">
                <span>Pelunasan peserta</span>
                <span><?php echo (int) $o['jml_lunas']; ?>/<?php echo (int) $o['jml_ikut']; ?> (<?php echo $persen; ?>%)</span>
              </div>
              <div class="progress progress-thin mb-2">
                <div class="progress-bar bg-success" style="width:<?php echo $persen; ?>%"></div>
              </div>

              <div class="d-flex gap-2">
                <a href="<?php echo base_url('member/outing.php?id=' . $oid); ?>" class="btn btn-sm btn-primary flex-grow-1">
                  <i class="bi bi-eye me-1"></i>Lihat Detail
                </a>
              </div>
              <div class="fs-8 text-muted mt-2">
                <i class="bi bi-info-circle me-1"></i>
                <?php echo (int) $o['jml_rundown']; ?> agenda rundown &middot; pengeluaran tercatat <?php echo rupiah($o['total_belanja'], false); ?>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============================ HISTORY ============================ -->
<div class="card">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-archive text-secondary"></i>
    <span class="card-title-sm me-auto">History Outing Saya</span>
    <span class="badge bg-light text-dark border"><?php echo count($ikutHistory); ?> outing</span>
  </div>
  <div class="card-body p-0">
    <?php if (count($ikutHistory) === 0): ?>
      <div class="table-empty py-4"><i class="bi bi-archive"></i>Belum ada riwayat outing yang Anda ikuti.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>Outing</th>
            <th class="nowrap">Tanggal</th>
            <th class="text-center">Status</th>
            <th class="text-center">Kehadiran</th>
            <th class="text-center">Pembayaran</th>
            <th class="text-end">Pengeluaran</th>
            <th class="text-center">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ikutHistory as $h): $oid = (int) $h['id']; ?>
          <tr>
            <td>
              <div class="text-strong"><?php echo e($h['nama']); ?></div>
              <div class="fs-8 text-muted"><span class="badge badge-soft"><?php echo e($h['kode']); ?></span> <i class="bi bi-geo-alt"></i> <?php echo e($h['tujuan']); ?></div>
            </td>
            <td class="fs-8 nowrap"><?php echo rentang_tanggal($h['tanggal_mulai'], $h['tanggal_selesai']); ?></td>
            <td class="text-center"><?php echo badge_status_outing($h['status']); ?></td>
            <td class="text-center"><?php echo badge_attendance($h['attendance_status']); ?></td>
            <td class="text-center">
              <?php echo badge_payment($h['payment_status']); ?>
              <?php if ($h['payment_status'] === 'Sudah'): ?>
                <div class="fs-8 text-muted"><?php echo rupiah($h['nominal_bayar'], false); ?></div>
              <?php endif; ?>
            </td>
            <td class="text-end nowrap fs-8"><?php echo rupiah($h['total_belanja'], false); ?></td>
            <td class="text-center">
              <a href="<?php echo base_url('member/outing.php?id=' . $oid); ?>" class="btn btn-sm btn-outline-secondary btn-icon" title="Lihat detail" data-bs-toggle="tooltip">
                <i class="bi bi-eye"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <div class="card-footer bg-white small text-muted">
    <i class="bi bi-lock me-1"></i>
    Halaman member bersifat <strong>hanya-lihat</strong>. Perubahan data kehadiran, pembayaran, rundown, dan pembelian
    hanya dapat dilakukan oleh Administrator.
  </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
