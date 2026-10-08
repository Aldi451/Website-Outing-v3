<?php
/**
 * ==========================================================================
 *  MEMBER — member/outing.php?id={outing_id}   (100% VIEW ONLY)
 * --------------------------------------------------------------------------
 *  Menampilkan detail outing yang diikuti: info outing, status diri,
 *  rundown (tampilan TIMELINE/CARD ramah HP), daftar peserta, pembelian,
 *  rekap per kategori, serta bukti transaksi.
 *
 *  Proteksi: require_participant() memastikan user benar-benar terdaftar
 *  sebagai peserta outing tsb (bukan sekadar menyembunyikan menu).
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';

$outingId = get_int('id', 0);
$akses = require_participant($outingId);
$outing = $akses['outing'];
$me = $akses['participant'];

$uid = current_uid();
$isHistory = in_array($outing['status'], ['Selesai', 'Cancelled'], true);

$rundown = fetch_all(
    'SELECT * FROM rundown WHERE outing_id = ? ORDER BY urutan ASC, id ASC',
    [$outingId]
);

$peserta = fetch_all(
    "SELECT u.nama, u.departemen, u.jabatan, op.attendance_status, op.payment_status
     FROM outing_participants op
     JOIN users u ON u.id = op.user_id
     WHERE op.outing_id = ?
     ORDER BY FIELD(op.attendance_status,'Ikut','Batal','Tidak Ikut'), u.nama ASC",
    [$outingId]
);

$pembelian = fetch_all(
    'SELECT p.id, p.nama_barang, p.qty, p.satuan, p.harga_satuan, p.total_harga, p.tanggal_beli,
            p.bukti_file, p.keterangan, c.nama AS kategori
     FROM purchases p
     JOIN categories c ON c.id = p.category_id
     WHERE p.outing_id = ?
     ORDER BY p.tanggal_beli ASC, p.id ASC',
    [$outingId]
);

$perKategori = outing_by_category($outingId);
$fin = outing_finance($outingId);

// kelompokkan rundown per hari
$grupHari = [];
foreach ($rundown as $r) {
    $key = ($r['hari'] !== '') ? $r['hari'] : ($r['tanggal'] ? tgl_indo($r['tanggal'], true) : 'Jadwal Umum');
    if (!isset($grupHari[$key])) {
        $grupHari[$key] = ['tanggal' => $r['tanggal'], 'items' => []];
    }
    $grupHari[$key]['items'][] = $r;
}

$totalBukti = 0;
foreach ($pembelian as $p) {
    if (!empty($p['bukti_file'])) {
        $totalBukti++;
    }
}

$tab = in_enum(get_str('tab'), ['ringkasan', 'rundown', 'peserta', 'pembelian'], 'ringkasan');

$pageTitle = $outing['nama'];
$pageSubtitle = '<span class="badge badge-soft">' . e($outing['kode']) . '</span> '
    . e($outing['tujuan']) . ' &middot; ' . rentang_tanggal($outing['tanggal_mulai'], $outing['tanggal_selesai']);
$activeMenu = 'dashboard';

require_once INCLUDES_PATH . '/header.php';
?>

<!-- ======================= STATUS DIRI (PALING PENTING) ======================= -->
<div class="card mb-3 <?php echo $isHistory ? 'outing-card is-done' : 'outing-card'; ?>">
  <div class="card-body">
    <div class="row g-3 align-items-center">
      <div class="col-12 col-lg-4">
        <div class="fs-8 text-muted text-uppercase fw-bold">Status Saya pada Outing Ini</div>
        <h5 class="mb-1"><?php echo e(current_nama()); ?></h5>
        <div class="fs-8 text-muted"><?php echo e(current_user_id_login()); ?></div>
        <div class="mt-2 d-flex flex-wrap gap-2">
          <?php echo badge_attendance($me['attendance_status']); ?>
          <?php echo badge_payment($me['payment_status']); ?>
          <?php echo badge_status_outing($outing['status']); ?>
        </div>
      </div>
      <div class="col-6 col-lg-2">
        <div class="status-self">
          <div class="label">Iuran Standar</div>
          <div class="value"><?php echo rupiah($outing['iuran_per_orang'], false); ?></div>
        </div>
      </div>
      <div class="col-6 col-lg-2">
        <div class="status-self">
          <div class="label"><?php echo $me['payment_status'] === 'Sudah' ? 'Sudah Dibayar' : 'Tagihan Saya'; ?></div>
          <div class="value <?php echo $me['payment_status'] === 'Sudah' ? 'text-success' : 'text-danger'; ?>">
            <?php echo rupiah($me['payment_status'] === 'Sudah' ? $me['nominal_bayar'] : $outing['iuran_per_orang'], false); ?>
          </div>
        </div>
      </div>
      <div class="col-6 col-lg-2">
        <div class="status-self">
          <div class="label">Tanggal Bayar</div>
          <div class="value fs-6"><?php echo $me['tanggal_bayar'] ? date('d/m/Y', strtotime($me['tanggal_bayar'])) : '-'; ?></div>
        </div>
      </div>
      <div class="col-6 col-lg-2">
        <div class="status-self">
          <div class="label">Catatan</div>
          <div class="value fs-6"><?php echo $me['catatan'] !== '' ? e(str_limit($me['catatan'], 24)) : '-'; ?></div>
        </div>
      </div>
    </div>

    <?php if ($me['payment_status'] === 'Belum' && $me['attendance_status'] === 'Ikut' && !$isHistory): ?>
      <div class="alert alert-warning mt-3 mb-0 py-2 small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Iuran Anda tercatat <strong>belum dibayar</strong>. Silakan hubungi panitia/bendahara untuk pembayaran
        dan minta pembaruan status pada sistem.
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ======================= NAVIGASI TAB (read-only) ======================= -->
<ul class="nav nav-tabs outing-tabs mb-3 bg-white rounded-top px-2" style="border:1px solid var(--oms-card-border)">
  <?php
  $tabList = [
      'ringkasan' => ['bi-card-list', 'Ringkasan'],
      'rundown'   => ['bi-clock-history', 'Rundown (' . count($rundown) . ')'],
      'peserta'   => ['bi-people', 'Peserta (' . count($peserta) . ')'],
      'pembelian' => ['bi-receipt', 'Pembelian (' . count($pembelian) . ')'],
  ];
  foreach ($tabList as $key => $t):
  ?>
    <li class="nav-item">
      <a class="nav-link <?php echo ($tab === $key) ? 'active' : ''; ?>"
         href="<?php echo base_url('member/outing.php?id=' . $outingId . '&tab=' . $key); ?>">
        <i class="bi <?php echo $t[0]; ?> me-1"></i><?php echo $t[1]; ?>
      </a>
    </li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'ringkasan'): ?>
<!-- ============================ RINGKASAN ============================ -->
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><span class="card-title-sm"><i class="bi bi-info-circle me-1 text-primary"></i>Informasi Outing</span></div>
      <div class="card-body">
        <table class="table table-sm fs-7 mb-0">
          <tbody>
            <tr><th class="text-muted fw-normal" style="width:40%">Nama</th><td class="fw-semibold"><?php echo e($outing['nama']); ?></td></tr>
            <tr><th class="text-muted fw-normal">Kode</th><td><?php echo e($outing['kode']); ?></td></tr>
            <tr><th class="text-muted fw-normal">Tujuan</th><td><?php echo e($outing['tujuan']); ?></td></tr>
            <tr><th class="text-muted fw-normal">Lokasi</th><td><?php echo e($outing['lokasi'] ?: '-'); ?></td></tr>
            <tr><th class="text-muted fw-normal">Tanggal</th><td><?php echo rentang_tanggal($outing['tanggal_mulai'], $outing['tanggal_selesai']); ?></td></tr>
            <tr><th class="text-muted fw-normal">Status</th><td><?php echo badge_status_outing($outing['status']); ?></td></tr>
            <tr><th class="text-muted fw-normal">Anggaran</th><td><?php echo rupiah($outing['anggaran']); ?></td></tr>
            <tr><th class="text-muted fw-normal">Iuran/orang</th><td><?php echo rupiah($outing['iuran_per_orang']); ?></td></tr>
          </tbody>
        </table>
        <?php if (!empty($outing['keterangan'])): ?>
          <hr>
          <div class="fs-8 text-muted"><strong>Catatan Panitia:</strong><br><?php echo nl2br(e($outing['keterangan'])); ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body stat-card py-3">
          <span class="stat-icon bg-soft-success"><i class="bi bi-people-fill"></i></span>
          <div><div class="stat-label">Peserta Ikut</div><div class="stat-value"><?php echo $fin['ikut']; ?></div></div>
        </div></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body stat-card py-3">
          <span class="stat-icon bg-soft-info"><i class="bi bi-cash-stack"></i></span>
          <div><div class="stat-label">Iuran Masuk</div><div class="stat-value fs-6"><?php echo rupiah($fin['total_iuran'], false); ?></div></div>
        </div></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body stat-card py-3">
          <span class="stat-icon bg-soft-danger"><i class="bi bi-receipt"></i></span>
          <div><div class="stat-label">Pengeluaran</div><div class="stat-value fs-6"><?php echo rupiah($fin['total_pengeluaran'], false); ?></div></div>
        </div></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body stat-card py-3">
          <span class="stat-icon bg-soft-purple"><i class="bi bi-clock-history"></i></span>
          <div><div class="stat-label">Agenda</div><div class="stat-value"><?php echo count($rundown); ?></div></div>
        </div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title-sm"><i class="bi bi-pie-chart me-1 text-primary"></i>Pengeluaran per Kategori</span></div>
      <div class="card-body">
        <?php
        $adaIsi = false;
        foreach ($perKategori as $k) {
            if ((float) $k['total'] > 0) { $adaIsi = true; break; }
        }
        if (!$adaIsi): ?>
          <p class="text-muted small mb-0">Belum ada pengeluaran yang dipublikasikan.</p>
        <?php else: ?>
          <?php foreach ($perKategori as $k):
              if ((float) $k['total'] <= 0) { continue; }
              $share = ($fin['total_pengeluaran'] > 0) ? round((float) $k['total'] / $fin['total_pengeluaran'] * 100) : 0; ?>
            <div class="bar-chart-row">
              <span class="bar-chart-label"><?php echo e($k['nama']); ?></span>
              <span class="bar-chart-track"><span class="bar-chart-fill" style="width:<?php echo $share; ?>%"></span></span>
              <span class="bar-chart-value"><?php echo rupiah($k['total'], false); ?>
                <small class="text-muted d-block fw-normal"><?php echo $share; ?>%</small></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php elseif ($tab === 'rundown'): ?>
<!-- ============================ RUNDOWN (TIMELINE) ============================ -->
<div class="card">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-clock-history text-warning"></i>
    <span class="card-title-sm me-auto">Rundown Acara</span>
    <span class="fs-8 text-muted">Tampilan timeline — ramah di layar HP</span>
  </div>
  <div class="card-body">
    <?php if (count($rundown) === 0): ?>
      <div class="table-empty py-4"><i class="bi bi-calendar-x"></i>Rundown belum dipublikasikan panitia.</div>
    <?php else: ?>
      <?php foreach ($grupHari as $namaHari => $grup): ?>
        <div class="mb-2 d-flex align-items-center gap-2">
          <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle"><?php echo e($namaHari); ?></span>
          <?php if (!empty($grup['tanggal'])): ?>
            <span class="fs-8 text-muted"><i class="bi bi-calendar3 me-1"></i><?php echo tgl_indo($grup['tanggal'], true); ?></span>
          <?php endif; ?>
          <span class="fs-8 text-muted ms-auto"><?php echo count($grup['items']); ?> agenda</span>
        </div>
        <div class="timeline mb-4">
          <?php $no = 0; foreach ($grup['items'] as $r): $no++; ?>
            <div class="timeline-item">
              <span class="timeline-dot"><?php echo (int) $r['urutan'] ?: $no; ?></span>
              <div class="timeline-card">
                <div class="d-flex flex-wrap align-items-center gap-2">
                  <span class="timeline-time">
                    <i class="bi bi-clock me-1"></i>
                    <?php echo $r['waktu_mulai'] ? jam($r['waktu_mulai']) : '--:--'; ?>
                    &ndash;
                    <?php echo $r['waktu_selesai'] ? jam($r['waktu_selesai']) : '--:--'; ?>
                  </span>
                  <?php if (!empty($r['tanggal'])): ?>
                    <span class="fs-8 text-muted"><i class="bi bi-calendar3 me-1"></i><?php echo date('d/m/Y', strtotime($r['tanggal'])); ?></span>
                  <?php endif; ?>
                </div>
                <div class="timeline-title"><?php echo e($r['acara']); ?></div>
                <div class="timeline-meta">
                  <?php if (!empty($r['lokasi'])): ?><span class="me-2"><i class="bi bi-geo-alt"></i><?php echo e($r['lokasi']); ?></span><?php endif; ?>
                  <?php if (!empty($r['pic'])): ?><span><i class="bi bi-person-badge"></i><?php echo e($r['pic']); ?></span><?php endif; ?>
                </div>
                <?php if (!empty($r['keterangan'])): ?>
                  <div class="mt-1 fs-8 text-muted border-top pt-1"><i class="bi bi-info-circle me-1"></i><?php echo nl2br(e($r['keterangan'])); ?></div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($tab === 'peserta'): ?>
<!-- ============================ PESERTA ============================ -->
<div class="card">
  <div class="card-header d-flex flex-wrap align-items-center gap-2">
    <i class="bi bi-people text-primary"></i>
    <span class="card-title-sm me-auto">Daftar Peserta</span>
    <input type="search" class="form-control form-control-sm" style="max-width:200px"
           placeholder="Cari nama..." data-table-filter="#tblPesertaMember">
    <span class="badge bg-success"><?php echo $fin['ikut']; ?> ikut</span>
    <span class="badge bg-warning text-dark"><?php echo $fin['batal']; ?> batal</span>
    <span class="badge bg-secondary"><?php echo $fin['tidak_ikut']; ?> tidak ikut</span>
  </div>
  <div class="card-body p-0">
    <?php if (count($peserta) === 0): ?>
      <div class="table-empty py-4"><i class="bi bi-people"></i>Daftar peserta belum tersedia.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table align-middle" id="tblPesertaMember">
        <thead>
          <tr><th style="width:46px" class="text-center">No</th><th>Nama</th><th>Departemen</th><th class="text-center">Kehadiran</th><th class="text-center">Pembayaran</th></tr>
        </thead>
        <tbody>
          <?php $no = 0; foreach ($peserta as $p): $no++; $isMe = ($p['nama'] === current_nama()); ?>
          <tr data-searchable data-search="<?php echo e(strtolower($p['nama'] . ' ' . $p['departemen'])); ?>" <?php echo $isMe ? 'class="table-active"' : ''; ?>>
            <td class="text-center text-muted"><?php echo $no; ?></td>
            <td>
              <span class="text-strong"><?php echo e($p['nama']); ?></span>
              <?php if ($isMe): ?><span class="badge bg-info text-dark ms-1">Anda</span><?php endif; ?>
              <?php if (!empty($p['jabatan'])): ?><div class="fs-8 text-muted"><?php echo e($p['jabatan']); ?></div><?php endif; ?>
            </td>
            <td class="fs-8 text-muted"><?php echo e($p['departemen'] ?: '-'); ?></td>
            <td class="text-center"><?php echo badge_attendance($p['attendance_status']); ?></td>
            <td class="text-center"><?php echo badge_payment($p['payment_status']); ?></td>
          </tr>
          <?php endforeach; ?>
          <tr data-empty-row style="display:none"><td colspan="5" class="table-empty"><i class="bi bi-search"></i>Nama tidak ditemukan.</td></tr>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <div class="card-footer bg-white small text-muted">
    <i class="bi bi-shield-lock me-1"></i> Demi privasi, nomor HP dan email peserta tidak ditampilkan pada portal member.
  </div>
</div>

<?php else: ?>
<!-- ============================ PEMBELIAN & BUKTI ============================ -->
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-receipt text-success"></i>
        <span class="card-title-sm me-auto">Rincian Pengeluaran</span>
        <input type="search" class="form-control form-control-sm" style="max-width:180px"
               placeholder="Cari item..." data-table-filter="#tblBeliMember">
      </div>
      <div class="card-body p-0">
        <?php if (count($pembelian) === 0): ?>
          <div class="table-empty py-4"><i class="bi bi-receipt-cutoff"></i>Belum ada data pengeluaran.</div>
        <?php else: ?>
        <div class="table-wrap">
          <table class="table align-middle" id="tblBeliMember">
            <thead>
              <tr>
                <th style="width:88px">Tanggal</th>
                <th>Item</th>
                <th style="width:120px">Kategori</th>
                <th class="text-center" style="width:80px">Qty</th>
                <th class="text-end" style="width:120px">Total</th>
                <th class="text-center" style="width:80px">Bukti</th>
              </tr>
            </thead>
            <tbody>
              <?php $grand = 0.0; foreach ($pembelian as $p): $grand += (float) $p['total_harga']; ?>
              <tr data-searchable data-search="<?php echo e(strtolower($p['nama_barang'] . ' ' . $p['kategori'])); ?>">
                <td class="fs-8 nowrap"><?php echo $p['tanggal_beli'] ? date('d/m/Y', strtotime($p['tanggal_beli'])) : '-'; ?></td>
                <td>
                  <div class="text-strong fs-7"><?php echo e($p['nama_barang']); ?></div>
                  <div class="fs-8 text-muted">
                    <?php echo rupiah($p['harga_satuan'], false); ?> / <?php echo e($p['satuan']); ?>
                    <?php if (!empty($p['keterangan'])): ?> &middot; <?php echo e(str_limit($p['keterangan'], 40)); ?><?php endif; ?>
                  </div>
                </td>
                <td><span class="badge badge-soft"><?php echo e($p['kategori']); ?></span></td>
                <td class="text-center fs-8"><?php echo rtrim(rtrim(number_format((float) $p['qty'], 2, ',', '.'), '0'), ','); ?></td>
                <td class="text-end nowrap fw-semibold"><?php echo rupiah($p['total_harga'], false); ?></td>
                <td class="text-center">
                  <?php if (!empty($p['bukti_file'])): ?>
                    <a href="<?php echo base_url('member/bukti.php?outing_id=' . $outingId . '&id=' . (int) $p['id']); ?>"
                       target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary btn-icon" title="Lihat bukti" data-bs-toggle="tooltip">
                      <i class="bi <?php echo preg_match('/\.pdf$/i', $p['bukti_file']) ? 'bi-file-earmark-pdf' : 'bi-image'; ?>"></i>
                    </a>
                  <?php else: ?>
                    <span class="text-muted fs-8">-</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <tr data-empty-row style="display:none"><td colspan="6" class="table-empty"><i class="bi bi-search"></i>Item tidak ditemukan.</td></tr>
            </tbody>
            <tfoot>
              <tr class="table-light"><th colspan="4" class="text-end">TOTAL</th><th class="text-end"><?php echo rupiah($grand, false); ?></th><th></th></tr>
            </tfoot>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><span class="card-title-sm"><i class="bi bi-wallet2 me-1 text-info"></i>Status Pembayaran Saya</span></div>
      <div class="card-body">
        <div class="status-self mb-2">
          <div class="label">Kehadiran</div>
          <div class="value"><?php echo badge_attendance($me['attendance_status']); ?></div>
        </div>
        <div class="status-self mb-2">
          <div class="label">Pembayaran</div>
          <div class="value"><?php echo badge_payment($me['payment_status']); ?></div>
        </div>
        <table class="table table-sm fs-8 mb-0">
          <tbody>
            <tr><td class="text-muted">Iuran standar</td><td class="text-end fw-semibold"><?php echo rupiah($outing['iuran_per_orang'], false); ?></td></tr>
            <tr><td class="text-muted">Nominal dibayar</td><td class="text-end fw-semibold"><?php echo rupiah($me['nominal_bayar'], false); ?></td></tr>
            <tr><td class="text-muted">Tanggal bayar</td><td class="text-end"><?php echo $me['tanggal_bayar'] ? date('d/m/Y', strtotime($me['tanggal_bayar'])) : '-'; ?></td></tr>
            <tr><td class="text-muted">Catatan</td><td class="text-end"><?php echo $me['catatan'] !== '' ? e($me['catatan']) : '-'; ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title-sm"><i class="bi bi-paperclip me-1 text-danger"></i>Bukti Transaksi</span></div>
      <div class="card-body small text-muted">
        <p class="mb-2">Terdapat <strong><?php echo $totalBukti; ?></strong> bukti (JPG/PNG/PDF) yang diunggah panitia dari
          total <?php echo count($pembelian); ?> transaksi.</p>
        <p class="mb-0">Bukti dibuka pada tab baru dan hanya dapat diakses oleh peserta outing ini.</p>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="mt-3">
  <a href="<?php echo base_url('member/dashboard.php'); ?>" class="btn btn-sm btn-light">
    <i class="bi bi-arrow-left me-1"></i>Kembali ke Dashboard
  </a>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
