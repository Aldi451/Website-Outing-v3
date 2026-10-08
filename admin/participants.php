<?php
/**
 * ==========================================================================
 *  ADMIN — admin/participants.php  (KELOLA PESERTA PER OUTING)
 * --------------------------------------------------------------------------
 *  - Tambah user ke outing (satu user boleh ikut BANYAK outing)
 *  - Set Kehadiran (Ikut/Batal/Tidak Ikut) & Pembayaran (Sudah/Belum) — TERPISAH
 *  - Ubah cepat lewat AJAX (api/participants.php) atau form
 *  - Hapus peserta (dengan dialog konfirmasi)
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();

$ENUM_KEHADIRAN = ['Ikut', 'Batal', 'Tidak Ikut'];
$ENUM_BAYAR = ['Sudah', 'Belum'];

$outingId = get_int('outing_id', post_int('outing_id', 0));
$outing = get_outing($outingId);
if ($outing === null) {
    flash('danger', 'Outing tidak ditemukan. Pilih outing terlebih dahulu.');
    redirect('admin/outings.php');
}
$isHistory = in_array($outing['status'], ['Selesai', 'Cancelled'], true);

/* ==========================================================================
 * PROSES POST
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $task = post_str('task');

    /* ---------------------------------------------- TAMBAH PESERTA (BULK) */
    if ($task === 'add') {
        $ids = isset($_POST['user_ids']) && is_array($_POST['user_ids']) ? $_POST['user_ids'] : [];
        $attendance = in_enum(post_str('attendance_status', 'Ikut'), $ENUM_KEHADIRAN, 'Ikut');
        $payment = in_enum(post_str('payment_status', 'Belum'), $ENUM_BAYAR, 'Belum');

        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, function ($v) {
            return $v > 0;
        });

        if (count($ids) === 0) {
            flash('warning', 'Pilih minimal satu pengguna untuk ditambahkan.');
            redirect('admin/participants.php?outing_id=' . $outingId);
        }

        $ditambah = 0;
        $dilewati = 0;
        foreach ($ids as $uid) {
            $ada = fetch_one('SELECT id FROM outing_participants WHERE outing_id = ? AND user_id = ? LIMIT 1', [$outingId, $uid]);
            if ($ada !== null) {
                $dilewati++;
                continue;
            }
            $userAda = fetch_one("SELECT id FROM users WHERE id = ? AND status = 'aktif' LIMIT 1", [$uid]);
            if ($userAda === null) {
                $dilewati++;
                continue;
            }
            insert_get_id(
                'INSERT INTO outing_participants (outing_id, user_id, attendance_status, payment_status, created_at)
                 VALUES (?, ?, ?, ?, NOW())',
                [$outingId, $uid, $attendance, $payment]
            );
            $ditambah++;
        }

        log_activity("Tambah $ditambah peserta ke outing " . $outing['kode'], 'outing_participants', $outingId);

        $msg = '<strong>' . $ditambah . '</strong> peserta berhasil ditambahkan.';
        if ($dilewati > 0) {
            $msg .= ' (' . $dilewati . ' dilewati karena sudah terdaftar / nonaktif)';
        }
        flash($ditambah > 0 ? 'success' : 'warning', $msg);
        redirect('admin/participants.php?outing_id=' . $outingId);
    }

    /* ---------------------------------------------------- HAPUS PESERTA */
    if ($task === 'remove') {
        $pid = post_int('participant_id', 0);
        $row = fetch_one('SELECT * FROM outing_participants WHERE id = ? AND outing_id = ? LIMIT 1', [$pid, $outingId]);
        if ($row === null) {
            flash('danger', 'Data peserta tidak ditemukan.');
            redirect('admin/participants.php?outing_id=' . $outingId);
        }
        $nama = fetch_value('SELECT nama FROM users WHERE id = ?', [(int) $row['user_id']], '(user terhapus)');
        run_query('DELETE FROM outing_participants WHERE id = ?', [$pid]);
        log_activity('Hapus peserta ' . $nama . ' dari outing ' . $outing['kode'], 'outing_participants', $pid);
        flash('success', 'Peserta <strong>' . e($nama) . '</strong> dikeluarkan dari outing ini.');
        redirect('admin/participants.php?outing_id=' . $outingId);
    }

    /* ------------------------------------ SIMPAH DETAIL (nominal/tanggal) */
    if ($task === 'update_payment') {
        $pid = post_int('participant_id', 0);
        $row = fetch_one('SELECT * FROM outing_participants WHERE id = ? AND outing_id = ? LIMIT 1', [$pid, $outingId]);
        if ($row === null) {
            flash('danger', 'Data peserta tidak ditemukan.');
            redirect('admin/participants.php?outing_id=' . $outingId);
        }
        $nominal = max(0, parse_number(post_str('nominal_bayar', '0')));
        $tglBayar = parse_date(post_str('tanggal_bayar'));
        $payment = in_enum(post_str('payment_status', 'Belum'), $ENUM_BAYAR, 'Belum');
        $attendance = in_enum(post_str('attendance_status', 'Ikut'), $ENUM_KEHADIRAN, 'Ikut');
        $catatan = post_str('catatan');

        if ($payment === 'Sudah' && $tglBayar === null) {
            $tglBayar = date('Y-m-d');
        }
        if ($payment === 'Sudah' && $nominal <= 0) {
            $nominal = (float) $outing['iuran_per_orang'];
        }
        if ($payment === 'Belum') {
            $tglBayar = null;
        }

        run_query(
            'UPDATE outing_participants
             SET attendance_status = ?, payment_status = ?, nominal_bayar = ?, tanggal_bayar = ?, catatan = ?, updated_at = NOW()
             WHERE id = ? AND outing_id = ?',
            [$attendance, $payment, $nominal, $tglBayar, $catatan, $pid, $outingId]
        );
        log_activity('Update pembayaran peserta id=' . $pid . ' outing ' . $outing['kode'], 'outing_participants', $pid);
        flash('success', 'Data peserta berhasil diperbarui.');
        redirect('admin/participants.php?outing_id=' . $outingId);
    }

    flash('danger', 'Perintah tidak dikenali.');
    redirect('admin/participants.php?outing_id=' . $outingId);
}

/* ==========================================================================
 * DATA
 * ========================================================================== */
$peserta = fetch_all(
    'SELECT op.*, u.nama, u.user_id, u.departemen, u.jabatan, u.no_hp
     FROM outing_participants op
     JOIN users u ON u.id = op.user_id
     WHERE op.outing_id = ?
     ORDER BY u.nama ASC',
    [$outingId]
);

$idsPeserta = [];
foreach ($peserta as $p) {
    $idsPeserta[] = (int) $p['user_id'];
}

$kandidat = [];
if (count($idsPeserta) > 0) {
    $placeholders = implode(',', array_fill(0, count($idsPeserta), '?'));
    $kandidat = fetch_all(
        "SELECT id, user_id, nama, departemen, jabatan FROM users
         WHERE status = 'aktif' AND id NOT IN ($placeholders)
         ORDER BY nama ASC",
        $idsPeserta
    );
} else {
    $kandidat = fetch_all(
        "SELECT id, user_id, nama, departemen, jabatan FROM users
         WHERE status = 'aktif' ORDER BY nama ASC"
    );
}

$fin = outing_finance($outingId);
$totalIuranTarget = $fin['ikut'] * (float) $outing['iuran_per_orang'];
$persenLunas = ($fin['ikut'] > 0) ? round($fin['lunas'] / $fin['ikut'] * 100) : 0;

/* ---------------------------------------------------------------- layout */
$pageTitle = 'Kelola Peserta';
$pageSubtitle = 'Atur kehadiran &amp; pembayaran peserta <strong>' . e($outing['nama']) . '</strong>.';
$activeMenu = $isHistory ? 'history' : 'outings';
$outingCtx = $outing;
$outingTab = 'peserta';
$pageActions =
    '<a href="' . base_url('admin/import.php?type=peserta&outing_id=' . $outingId) . '" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Import Excel</a>'
    . '<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalTambahPeserta"'
    . (count($kandidat) === 0 ? ' disabled' : '') . '><i class="bi bi-person-plus me-1"></i>Tambah Peserta</button>';

require_once INCLUDES_PATH . '/header.php';
?>

<?php if ($isHistory): ?>
<div class="alert alert-warning d-flex align-items-start py-2">
  <i class="bi bi-archive me-2 fs-5"></i>
  <div>Outing ini berstatus <strong><?php echo e($outing['status']); ?></strong> (data history).
  Secara default history bersifat <em>read-only</em>, namun sebagai Administrator Anda tetap dapat melakukan koreksi.
  Setiap perubahan tercatat di <a href="<?php echo base_url('admin/activity.php'); ?>">Log Aktivitas</a>.</div>
</div>
<?php endif; ?>

<!-- ============================ RINGKASAN ============================ -->
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-2">
    <div class="card h-100"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-primary"><i class="bi bi-people"></i></span>
      <div><div class="stat-label">Terdaftar</div><div class="stat-value"><?php echo $fin['total_peserta']; ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-2">
    <div class="card h-100"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-success"><i class="bi bi-check2-circle"></i></span>
      <div><div class="stat-label">Ikut</div><div class="stat-value"><?php echo $fin['ikut']; ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-2">
    <div class="card h-100"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-warning"><i class="bi bi-x-circle"></i></span>
      <div><div class="stat-label">Batal</div><div class="stat-value"><?php echo $fin['batal']; ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-2">
    <div class="card h-100"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-danger"><i class="bi bi-slash-circle"></i></span>
      <div><div class="stat-label">Tidak Ikut</div><div class="stat-value"><?php echo $fin['tidak_ikut']; ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-2">
    <div class="card h-100"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-info"><i class="bi bi-cash-stack"></i></span>
      <div><div class="stat-label">Lunas</div><div class="stat-value"><?php echo $fin['lunas']; ?></div>
        <div class="stat-sub"><?php echo $persenLunas; ?>% dari peserta ikut</div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-2">
    <div class="card h-100"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-purple"><i class="bi bi-wallet2"></i></span>
      <div><div class="stat-label">Iuran Masuk</div><div class="stat-value fs-6"><?php echo rupiah($fin['total_iuran'], false); ?></div>
        <div class="stat-sub">target <?php echo rupiah($totalIuranTarget, false); ?></div></div>
    </div></div>
  </div>
</div>

<!-- ============================ TABEL PESERTA ============================ -->
<div class="card">
  <div class="card-header d-flex flex-wrap align-items-center gap-2">
    <span class="card-title-sm me-auto"><i class="bi bi-list-check me-1 text-primary"></i>Daftar Peserta</span>
    <select class="form-select form-select-sm" style="max-width:170px" id="filterKehadiran">
      <option value="">Semua Kehadiran</option>
      <?php foreach ($ENUM_KEHADIRAN as $k): ?><option value="<?php echo $k; ?>"><?php echo $k; ?></option><?php endforeach; ?>
    </select>
    <select class="form-select form-select-sm" style="max-width:170px" id="filterBayar">
      <option value="">Semua Pembayaran</option>
      <?php foreach ($ENUM_BAYAR as $k): ?><option value="<?php echo $k; ?>"><?php echo $k; ?></option><?php endforeach; ?>
    </select>
    <input type="search" class="form-control form-control-sm" style="max-width:190px"
           placeholder="Cari nama / user ID..." data-table-filter="#tblPeserta" data-filter-count="#filterCount">
    <span class="fs-8 text-muted" id="filterCount"><?php echo count($peserta); ?> data</span>
  </div>

  <div class="card-body p-0">
    <?php if (count($peserta) === 0): ?>
      <div class="table-empty">
        <i class="bi bi-person-plus"></i> Belum ada peserta pada outing ini.
        <div class="mt-2">
          <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalTambahPeserta">
            <i class="bi bi-person-plus me-1"></i>Tambah Peserta
          </button>
          <a class="btn btn-outline-success btn-sm" href="<?php echo base_url('admin/import.php?type=peserta&outing_id=' . $outingId); ?>">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Import dari Excel
          </a>
        </div>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table align-middle" id="tblPeserta">
        <thead>
          <tr>
            <th style="width:40px">No</th>
            <th>Peserta</th>
            <th class="text-center" style="width:150px">Kehadiran</th>
            <th class="text-center" style="width:150px">Pembayaran</th>
            <th class="text-end" style="width:140px">Nominal</th>
            <th class="text-center" style="width:120px">Tgl Bayar</th>
            <th class="text-center" style="width:110px">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php $no = 0; foreach ($peserta as $p): $no++;
              $pid = (int) $p['id']; ?>
          <tr data-searchable
              data-search="<?php echo e(strtolower($p['nama'] . ' ' . $p['user_id'] . ' ' . $p['attendance_status'] . ' ' . $p['payment_status'])); ?>"
              data-kehadiran="<?php echo e($p['attendance_status']); ?>" data-bayar="<?php echo e($p['payment_status']); ?>">
            <td class="text-muted"><?php echo $no; ?></td>
            <td>
              <div class="text-strong"><?php echo e($p['nama']); ?></div>
              <div class="fs-8 text-muted">
                <?php echo e($p['user_id']); ?>
                <?php if (!empty($p['departemen'])): ?> &middot; <?php echo e($p['departemen']); ?><?php endif; ?>
              </div>
              <?php if (!empty($p['catatan'])): ?>
                <div class="fs-8 text-warning"><i class="bi bi-chat-left-text"></i> <?php echo e(str_limit($p['catatan'], 50)); ?></div>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <select class="form-select form-select-sm" data-quick-url="<?php echo base_url('api/participants.php'); ?>"
                      data-action="set_attendance" data-field="attendance_status" data-id="<?php echo $pid; ?>"
                      data-params="<?php echo e(json_encode(['outing_id' => $outingId])); ?>"
                      title="Ubah kehadiran (tersimpan otomatis)">
                <?php foreach ($ENUM_KEHADIRAN as $k): ?>
                  <option value="<?php echo $k; ?>" <?php echo ($p['attendance_status'] === $k) ? 'selected' : ''; ?>><?php echo $k; ?></option>
                <?php endforeach; ?>
              </select>
              <div class="mt-1" id="badgeAtt<?php echo $pid; ?>"><?php echo badge_attendance($p['attendance_status']); ?></div>
            </td>
            <td class="text-center">
              <select class="form-select form-select-sm" data-quick-url="<?php echo base_url('api/participants.php'); ?>"
                      data-action="set_payment" data-field="payment_status" data-id="<?php echo $pid; ?>"
                      data-params="<?php echo e(json_encode(['outing_id' => $outingId])); ?>"
                      title="Ubah pembayaran (tersimpan otomatis)">
                <?php foreach ($ENUM_BAYAR as $k): ?>
                  <option value="<?php echo $k; ?>" <?php echo ($p['payment_status'] === $k) ? 'selected' : ''; ?>><?php echo $k; ?></option>
                <?php endforeach; ?>
              </select>
              <div class="mt-1" id="badgePay<?php echo $pid; ?>"><?php echo badge_payment($p['payment_status']); ?></div>
            </td>
            <td class="text-end nowrap"><?php echo rupiah($p['nominal_bayar'], false); ?></td>
            <td class="text-center fs-8"><?php echo $p['tanggal_bayar'] ? date('d/m/Y', strtotime($p['tanggal_bayar'])) : '-'; ?></td>
            <td class="text-center nowrap">
              <button type="button" class="btn btn-sm btn-outline-primary btn-icon" title="Edit detail pembayaran"
                      data-bs-toggle="modal" data-bs-target="#modalEdit<?php echo $pid; ?>">
                <i class="bi bi-pencil-square"></i>
              </button>
              <form method="post" action="<?php echo base_url('admin/participants.php'); ?>" class="d-inline"
                    data-confirm="Keluarkan <strong><?php echo e($p['nama']); ?></strong> dari outing <?php echo e($outing['nama']); ?>?<br>Data kehadiran &amp; pembayaran peserta ini akan terhapus."
                    data-confirm-title="Hapus Peserta" data-confirm-text="Ya, Keluarkan">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="task" value="remove">
                <input type="hidden" name="outing_id" value="<?php echo $outingId; ?>">
                <input type="hidden" name="participant_id" value="<?php echo $pid; ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="Hapus peserta" data-bs-toggle="tooltip"><i class="bi bi-trash3"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <tr data-empty-row style="display:none"><td colspan="7" class="table-empty"><i class="bi bi-search"></i>Tidak ada peserta yang cocok.</td></tr>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ===================== MODAL: TAMBAH PESERTA ===================== -->
<div class="modal fade" id="modalTambahPeserta" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form method="post" action="<?php echo base_url('admin/participants.php'); ?>" class="modal-content">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="task" value="add">
      <input type="hidden" name="outing_id" value="<?php echo $outingId; ?>">

      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-plus me-2 text-primary"></i>Tambah Peserta ke Outing</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <p class="fs-8 text-muted mb-2">
          Centang pengguna yang akan diikutkan. Satu pengguna dapat mengikuti banyak outing berbeda.
          Hanya pengguna berstatus <strong>aktif</strong> yang ditampilkan.
        </p>

        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
          <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" id="checkAll">
            <label class="form-check-label small" for="checkAll">Pilih semua</label>
          </div>
          <span class="fs-8 text-muted">Terpilih: <strong data-selected-count>0</strong></span>
          <input type="search" class="form-control form-control-sm ms-auto" style="max-width:220px" id="cariKandidat" placeholder="Cari nama...">
        </div>

        <div class="table-wrap" style="max-height:320px;overflow-y:auto;border:1px solid var(--oms-card-border);border-radius:10px">
          <table class="table table-sm mb-0" id="tblKandidat">
            <thead><tr><th style="width:36px"></th><th>Nama</th><th>User ID</th><th>Departemen</th><th>Jabatan</th></tr></thead>
            <tbody>
              <?php if (count($kandidat) === 0): ?>
                <tr><td colspan="5" class="table-empty py-3">Semua pengguna aktif sudah terdaftar pada outing ini.</td></tr>
              <?php else: foreach ($kandidat as $k): ?>
                <tr data-kandidat data-search="<?php echo e(strtolower($k['nama'] . ' ' . $k['user_id'] . ' ' . $k['departemen'])); ?>">
                  <td><input class="form-check-input" type="checkbox" name="user_ids[]" value="<?php echo (int) $k['id']; ?>" data-check-item></td>
                  <td class="text-strong"><?php echo e($k['nama']); ?></td>
                  <td class="fs-8"><?php echo e($k['user_id']); ?></td>
                  <td class="fs-8 text-muted"><?php echo e($k['departemen'] ?: '-'); ?></td>
                  <td class="fs-8 text-muted"><?php echo e($k['jabatan'] ?: '-'); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <div class="row g-2 mt-1">
          <div class="col-md-6">
            <label class="form-label" for="addAttendance">Status Kehadiran Awal</label>
            <select class="form-select form-select-sm" id="addAttendance" name="attendance_status">
              <?php foreach ($ENUM_KEHADIRAN as $k): ?>
                <option value="<?php echo $k; ?>" <?php echo ($k === 'Ikut') ? 'selected' : ''; ?>><?php echo $k; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="addPayment">Status Pembayaran Awal</label>
            <select class="form-select form-select-sm" id="addPayment" name="payment_status">
              <?php foreach ($ENUM_BAYAR as $k): ?>
                <option value="<?php echo $k; ?>" <?php echo ($k === 'Belum') ? 'selected' : ''; ?>><?php echo $k; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Tambahkan Peserta</button>
      </div>
    </form>
  </div>
</div>

<!-- ===================== MODAL: EDIT DETAIL PER PESERTA ===================== -->
<?php foreach ($peserta as $p): $pid = (int) $p['id']; ?>
<div class="modal fade" id="modalEdit<?php echo $pid; ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="<?php echo base_url('admin/participants.php'); ?>" class="modal-content">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="task" value="update_payment">
      <input type="hidden" name="outing_id" value="<?php echo $outingId; ?>">
      <input type="hidden" name="participant_id" value="<?php echo $pid; ?>">

      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2 text-primary"></i><?php echo e($p['nama']); ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Kehadiran</label>
            <select class="form-select form-select-sm" name="attendance_status">
              <?php foreach ($ENUM_KEHADIRAN as $k): ?>
                <option value="<?php echo $k; ?>" <?php echo ($p['attendance_status'] === $k) ? 'selected' : ''; ?>><?php echo $k; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Pembayaran</label>
            <select class="form-select form-select-sm" name="payment_status">
              <?php foreach ($ENUM_BAYAR as $k): ?>
                <option value="<?php echo $k; ?>" <?php echo ($p['payment_status'] === $k) ? 'selected' : ''; ?>><?php echo $k; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Nominal Bayar (Rp)</label>
            <input type="text" class="form-control form-control-sm text-end" name="nominal_bayar" inputmode="numeric"
                   value="<?php echo e(number_format((float) $p['nominal_bayar'], 0, ',', '.')); ?>">
            <div class="form-text">Iuran standar: <?php echo rupiah($outing['iuran_per_orang'], false); ?></div>
          </div>
          <div class="col-6">
            <label class="form-label">Tanggal Bayar</label>
            <input type="date" class="form-control form-control-sm" name="tanggal_bayar"
                   value="<?php echo e($p['tanggal_bayar']); ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Catatan</label>
            <input type="text" class="form-control form-control-sm" name="catatan" maxlength="255"
                   value="<?php echo e($p['catatan']); ?>" placeholder="contoh: transfer BCA / potong gaji">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<?php
$extraScripts = <<<'HTML'
<script>
(function () {
  // Saring tabel peserta berdasarkan kehadiran & pembayaran (tanpa reload)
  var fKeh = document.getElementById('filterKehadiran');
  var fBay = document.getElementById('filterBayar');
  function applyRowFilter() {
    if (!fKeh || !fBay) { return; }
    var k = fKeh.value, b = fBay.value, shown = 0;
    document.querySelectorAll('#tblPeserta tbody tr[data-searchable]').forEach(function (tr) {
      var okK = k === '' || tr.getAttribute('data-kehadiran') === k;
      var okB = b === '' || tr.getAttribute('data-bayar') === b;
      var cari = document.querySelector('[data-table-filter="#tblPeserta"]');
      var okC = true;
      if (cari && cari.value.trim() !== '') {
        okC = (tr.getAttribute('data-search') || '').indexOf(cari.value.toLowerCase().trim()) !== -1;
      }
      var show = okK && okB && okC;
      tr.style.display = show ? '' : 'none';
      if (show) { shown++; }
    });
    var c = document.getElementById('filterCount');
    if (c) { c.textContent = shown + ' data'; }
    var empty = document.querySelector('#tblPeserta tbody tr[data-empty-row]');
    if (empty) { empty.style.display = shown === 0 ? '' : 'none'; }
  }
  if (fKeh) { fKeh.addEventListener('change', applyRowFilter); }
  if (fBay) { fBay.addEventListener('change', applyRowFilter); }

  // Pencarian kandidat peserta di modal
  var cariKandidat = document.getElementById('cariKandidat');
  if (cariKandidat) {
    cariKandidat.addEventListener('input', function () {
      var q = cariKandidat.value.toLowerCase().trim();
      document.querySelectorAll('#tblKandidat tr[data-kandidat]').forEach(function (tr) {
        var hay = (tr.getAttribute('data-search') || '');
        tr.style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
      });
    });
  }
})();
</script>
HTML;

require_once INCLUDES_PATH . '/footer.php';
