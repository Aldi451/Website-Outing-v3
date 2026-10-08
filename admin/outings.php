<?php
/**
 * ==========================================================================
 *  ADMIN — admin/outings.php  (CRUD OUTING + RINGKASAN)
 * --------------------------------------------------------------------------
 *  action = (kosong) : daftar outing (filter status/tahun/kata kunci)
 *         = create   : form outing baru
 *         = edit     : form ubah outing (termasuk "Edit History")
 *         = view     : ringkasan satu outing (tab pertama kelola outing)
 *  POST   : save | delete | set_status
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();

$ENUM_STATUS = ['Planning', 'Active', 'Selesai', 'Cancelled'];
$action = get_str('action', 'list');

/* ==========================================================================
 * PROSES POST (disimpan -> redirect, pola PRG agar tidak double submit)
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $task = post_str('task');

    /* ---------------------------------------------------------- SIMPAN */
    if ($task === 'save') {
        $id = post_int('id', 0);
        $kode = strtoupper(post_str('kode', generate_outing_code()));
        $nama = post_str('nama');
        $tujuan = post_str('tujuan');
        $lokasi = post_str('lokasi');
        $mulai = parse_date(post_str('tanggal_mulai'));
        $selesai = parse_date(post_str('tanggal_selesai'));
        $anggaran = max(0, parse_number(post_str('anggaran', '0')));
        $iuran = max(0, parse_number(post_str('iuran_per_orang', '0')));
        $status = in_enum(post_str('status', 'Planning'), $ENUM_STATUS, 'Planning');
        $keterangan = post_str('keterangan');
        $errors = [];

        if (text_length($nama) < 3) {
            $errors[] = 'Nama outing minimal 3 karakter.';
        }
        if ($tujuan === '') {
            $errors[] = 'Tujuan/destinasi wajib diisi.';
        }
        if ($mulai === null) {
            $errors[] = 'Tanggal mulai tidak valid (gunakan format YYYY-MM-DD).';
        }
        if ($selesai === null) {
            $errors[] = 'Tanggal selesai tidak valid (gunakan format YYYY-MM-DD).';
        }
        if ($mulai && $selesai && strtotime($selesai) < strtotime($mulai)) {
            $errors[] = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
        }
        if (!preg_match('/^[A-Z0-9\-\/]{3,20}$/', $kode)) {
            $errors[] = 'Kode outing hanya boleh huruf besar, angka, strip (3-20 karakter).';
        }
        if ($anggaran < 0 || $iuran < 0) {
            $errors[] = 'Anggaran dan iuran tidak boleh negatif.';
        }

        // keunikan kode
        if (count($errors) === 0) {
            $cek = fetch_one('SELECT id FROM outings WHERE kode = ? AND id <> ? LIMIT 1', [$kode, $id]);
            if ($cek !== null) {
                $errors[] = 'Kode outing <strong>' . e($kode) . '</strong> sudah dipakai. Gunakan kode lain.';
            }
        }

        if (count($errors) > 0) {
            flash('danger', 'Data tidak tersimpan:<br>&bull; ' . implode('<br>&bull; ', $errors));
            redirect('admin/outings.php?action=' . ($id > 0 ? 'edit&outing_id=' . $id : 'create'));
        }

        if ($id > 0) {
            $existing = get_outing($id);
            if ($existing === null) {
                flash('danger', 'Outing tidak ditemukan.');
                redirect('admin/outings.php');
            }
            run_query(
                'UPDATE outings SET kode = ?, nama = ?, tujuan = ?, lokasi = ?, tanggal_mulai = ?, tanggal_selesai = ?,
                        anggaran = ?, iuran_per_orang = ?, status = ?, keterangan = ?, updated_at = NOW()
                 WHERE id = ?',
                [$kode, $nama, $tujuan, $lokasi, $mulai, $selesai, $anggaran, $iuran, $status, $keterangan, $id]
            );
            log_activity('Ubah outing ' . $kode, 'outings', $id);
            flash('success', 'Outing <strong>' . e($nama) . '</strong> berhasil diperbarui.');
            redirect('admin/outings.php?action=view&outing_id=' . $id);
        }

        $newId = insert_get_id(
            'INSERT INTO outings (kode, nama, tujuan, lokasi, tanggal_mulai, tanggal_selesai, anggaran,
                                  iuran_per_orang, status, keterangan, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$kode, $nama, $tujuan, $lokasi, $mulai, $selesai, $anggaran, $iuran, $status, $keterangan, current_uid()]
        );
        outing_upload_dir($newId); // siapkan folder bukti /uploads/outing/{id}/
        log_activity('Membuat outing baru ' . $kode, 'outings', $newId);
        flash('success', 'Outing <strong>' . e($nama) . '</strong> berhasil dibuat. Selanjutnya tambahkan peserta.');
        redirect('admin/participants.php?outing_id=' . $newId);
    }

    /* ---------------------------------------------------------- HAPUS */
    if ($task === 'delete') {
        $id = post_int('outing_id', 0);
        $outing = get_outing($id);
        if ($outing === null) {
            flash('danger', 'Outing tidak ditemukan.');
            redirect('admin/outings.php');
        }

        // hapus file bukti yang tersimpan di /uploads/outing/{id}/
        $files = fetch_all('SELECT bukti_file FROM purchases WHERE outing_id = ? AND bukti_file <> ""', [$id]);
        foreach ($files as $f) {
            delete_bukti($id, $f['bukti_file']);
        }
        $dir = OUTING_UPLOAD_PATH . '/' . (int) $id;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }

        run_query('DELETE FROM outings WHERE id = ?', [$id]);
        log_activity('Hapus outing ' . $outing['kode'] . ' beserta seluruh datanya', 'outings', $id);
        flash('success', 'Outing <strong>' . e($outing['nama']) . '</strong> dan seluruh data terkait telah dihapus.');
        redirect('admin/outings.php');
    }

    /* -------------------------------------------------- UBAH STATUS CEPAT */
    if ($task === 'set_status') {
        $id = post_int('outing_id', 0);
        $status = in_enum(post_str('status'), $ENUM_STATUS, null);
        $outing = get_outing($id);
        if ($outing === null || $status === null) {
            flash('danger', 'Status tidak valid.');
            redirect('admin/outings.php');
        }
        run_query('UPDATE outings SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $id]);
        log_activity('Ubah status outing ' . $outing['kode'] . ' menjadi ' . $status, 'outings', $id);

        $pesan = ($status === 'Selesai' || $status === 'Cancelled')
            ? 'Status diubah menjadi <strong>' . e($status) . '</strong>. Outing otomatis pindah ke Dashboard History.'
            : 'Status diubah menjadi <strong>' . e($status) . '</strong>.';
        flash('success', $pesan);
        redirect('admin/outings.php?action=view&outing_id=' . $id);
    }

    flash('danger', 'Perintah tidak dikenali.');
    redirect('admin/outings.php');
}

/* ==========================================================================
 * MODE FORM (CREATE / EDIT)
 * ========================================================================== */
if ($action === 'create' || $action === 'edit') {
    $form = [
        'id' => 0, 'kode' => generate_outing_code(), 'nama' => '', 'tujuan' => '', 'lokasi' => '',
        'tanggal_mulai' => date('Y-m-d'), 'tanggal_selesai' => date('Y-m-d', strtotime('+1 day')),
        'anggaran' => 0, 'iuran_per_orang' => 0, 'status' => 'Planning', 'keterangan' => '',
    ];
    $isEdit = ($action === 'edit');

    if ($isEdit) {
        $id = get_int('outing_id', 0);
        $row = get_outing($id);
        if ($row === null) {
            flash('danger', 'Outing tidak ditemukan.');
            redirect('admin/outings.php');
        }
        $form = $row;
    }

    $pageTitle = $isEdit ? 'Ubah Data Outing' : 'Buat Outing Baru';
    $pageSubtitle = $isEdit
        ? 'Mengoreksi data <strong>' . e($form['kode']) . '</strong>. Perubahan dicatat pada log aktivitas.'
        : 'Data outing baru tidak akan menimpa atau menghapus outing lama (multi-outing).';
    $activeMenu = $isEdit && in_array($form['status'], ['Selesai', 'Cancelled'], true) ? 'history' : 'outings';

    require_once INCLUDES_PATH . '/header.php';
    ?>
    <form method="post" action="<?php echo base_url('admin/outings.php'); ?>" class="card mb-4">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="task" value="save">
      <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">

      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-calendar2-heart text-primary"></i>
        <span class="card-title-sm me-auto"><?php echo $isEdit ? 'Form Ubah Outing' : 'Form Outing Baru'; ?></span>
        <span class="badge bg-light text-dark border">Kolom bertanda <span class="required-mark">*</span> wajib diisi</span>
      </div>

      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label" for="kode">Kode Outing <span class="required-mark">*</span></label>
            <input type="text" class="form-control text-uppercase" id="kode" name="kode"
                   value="<?php echo e($form['kode']); ?>" maxlength="20" required>
            <div class="form-text">Unik. Otomatis: <?php echo e(generate_outing_code()); ?></div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="nama">Nama Outing <span class="required-mark">*</span></label>
            <input type="text" class="form-control" id="nama" name="nama" value="<?php echo e($form['nama']); ?>"
                   maxlength="150" placeholder="contoh: Outing Kantor 2026 - Yogyakarta" required>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="status">Status <span class="required-mark">*</span></label>
            <select class="form-select" id="status" name="status">
              <?php foreach ($ENUM_STATUS as $s): ?>
                <option value="<?php echo $s; ?>" <?php echo ($form['status'] === $s) ? 'selected' : ''; ?>><?php echo $s; ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Selesai/Cancelled &rarr; masuk History</div>
          </div>

          <div class="col-md-6">
            <label class="form-label" for="tujuan">Tujuan / Destinasi <span class="required-mark">*</span></label>
            <input type="text" class="form-control" id="tujuan" name="tujuan" value="<?php echo e($form['tujuan']); ?>"
                   maxlength="150" placeholder="contoh: Yogyakarta" required>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="lokasi">Lokasi Spesifik / Titik Kumpul</label>
            <input type="text" class="form-control" id="lokasi" name="lokasi" value="<?php echo e($form['lokasi']); ?>"
                   maxlength="150" placeholder="contoh: Hotel Grand Tjokro, Jl. Affandi No.37">
          </div>

          <div class="col-md-3">
            <label class="form-label" for="tanggal_mulai">Tanggal Mulai <span class="required-mark">*</span></label>
            <input type="date" class="form-control" id="tanggal_mulai" name="tanggal_mulai"
                   value="<?php echo e($form['tanggal_mulai']); ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="tanggal_selesai">Tanggal Selesai <span class="required-mark">*</span></label>
            <input type="date" class="form-control" id="tanggal_selesai" name="tanggal_selesai"
                   value="<?php echo e($form['tanggal_selesai']); ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="anggaran">Anggaran (Rp)</label>
            <div class="input-group">
              <span class="input-group-text">Rp</span>
              <input type="text" class="form-control text-end" id="anggaran" name="anggaran"
                     value="<?php echo e(number_format((float) $form['anggaran'], 0, ',', '.')); ?>" inputmode="numeric">
            </div>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="iuran_per_orang">Iuran per Orang (Rp)</label>
            <div class="input-group">
              <span class="input-group-text">Rp</span>
              <input type="text" class="form-control text-end" id="iuran_per_orang" name="iuran_per_orang"
                     value="<?php echo e(number_format((float) $form['iuran_per_orang'], 0, ',', '.')); ?>" inputmode="numeric">
            </div>
          </div>

          <div class="col-12">
            <label class="form-label" for="keterangan">Keterangan / Catatan Panitia</label>
            <textarea class="form-control" id="keterangan" name="keterangan" rows="3"
                      maxlength="1000" placeholder="Catatan tambahan yang tampil di laporan PDF & portal member"><?php echo e($form['keterangan']); ?></textarea>
          </div>
        </div>
      </div>

      <div class="card-footer bg-white d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo $isEdit ? 'Simpan Perubahan' : 'Simpan & Lanjut Tambah Peserta'; ?></button>
        <a href="<?php echo base_url($isEdit ? 'admin/outings.php?action=view&outing_id=' . (int) $form['id'] : 'admin/outings.php'); ?>"
           class="btn btn-light">Batal</a>
        <?php if ($isEdit && in_array($form['status'], ['Selesai', 'Cancelled'], true)): ?>
          <span class="ms-auto align-self-center fs-8 text-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>Anda sedang mengedit data HISTORY.
          </span>
        <?php endif; ?>
      </div>
    </form>
    <?php
    require_once INCLUDES_PATH . '/footer.php';
    exit;
}

/* ==========================================================================
 * MODE RINGKASAN (VIEW) — tab "Ringkasan" pada kelola outing
 * ========================================================================== */
if ($action === 'view') {
    $outingId = get_int('outing_id', 0);
    $outing = get_outing($outingId);
    if ($outing === null) {
        flash('danger', 'Outing tidak ditemukan.');
        redirect('admin/outings.php');
    }

    $fin = outing_finance($outingId);
    $perKategori = outing_by_category($outingId);
    $peserta = fetch_all(
        'SELECT u.nama, u.user_id, u.departemen, op.attendance_status, op.payment_status, op.nominal_bayar
         FROM outing_participants op
         JOIN users u ON u.id = op.user_id
         WHERE op.outing_id = ?
         ORDER BY op.attendance_status ASC, u.nama ASC',
        [$outingId]
    );
    $rundown = fetch_all(
        'SELECT * FROM rundown WHERE outing_id = ? ORDER BY urutan ASC, id ASC',
        [$outingId]
    );
    $pembelian = fetch_all(
        'SELECT p.*, c.nama AS kategori
         FROM purchases p JOIN categories c ON c.id = p.category_id
         WHERE p.outing_id = ?
         ORDER BY p.tanggal_beli DESC, p.id DESC
         LIMIT 15',
        [$outingId]
    );
    $totalPembelian = (int) fetch_value('SELECT COUNT(*) FROM purchases WHERE outing_id = ?', [$outingId], 0);

    $isHistory = in_array($outing['status'], ['Selesai', 'Cancelled'], true);
    $pageTitle = $outing['nama'];
    $pageSubtitle = 'Ringkasan lengkap outing <strong>' . e($outing['kode']) . '</strong> &middot; '
        . rentang_tanggal($outing['tanggal_mulai'], $outing['tanggal_selesai']);
    $activeMenu = $isHistory ? 'history' : 'outings';
    $outingCtx = $outing;
    $outingTab = 'ringkasan';
    $pageActions =
        '<a href="' . base_url('admin/export_pdf.php?outing_id=' . $outingId . '&download=1') . '" class="btn btn-danger btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i>Download PDF</a>'
        . '<a href="' . base_url('admin/outings.php?action=edit&outing_id=' . $outingId) . '" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>';

    require_once INCLUDES_PATH . '/header.php';
    ?>
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header"><span class="card-title-sm"><i class="bi bi-info-circle me-1 text-primary"></i>Informasi Outing</span></div>
          <div class="card-body">
            <table class="table table-sm mb-0 fs-7">
              <tbody>
                <tr><th class="text-muted fw-normal" style="width:38%">Kode</th><td class="fw-semibold"><?php echo e($outing['kode']); ?></td></tr>
                <tr><th class="text-muted fw-normal">Status</th><td><?php echo badge_status_outing($outing['status']); ?></td></tr>
                <tr><th class="text-muted fw-normal">Tujuan</th><td><?php echo e($outing['tujuan']); ?></td></tr>
                <tr><th class="text-muted fw-normal">Lokasi</th><td><?php echo e($outing['lokasi'] ?: '-'); ?></td></tr>
                <tr><th class="text-muted fw-normal">Tanggal</th><td><?php echo rentang_tanggal($outing['tanggal_mulai'], $outing['tanggal_selesai']); ?></td></tr>
                <tr><th class="text-muted fw-normal">Anggaran</th><td><?php echo rupiah($outing['anggaran']); ?></td></tr>
                <tr><th class="text-muted fw-normal">Iuran/orang</th><td><?php echo rupiah($outing['iuran_per_orang']); ?></td></tr>
                <tr><th class="text-muted fw-normal">Dibuat</th><td><?php echo date('d/m/Y H:i', strtotime($outing['created_at'])); ?></td></tr>
              </tbody>
            </table>
            <?php if (!empty($outing['keterangan'])): ?>
              <hr>
              <div class="fs-8 text-muted"><strong>Catatan:</strong><br><?php echo nl2br(e($outing['keterangan'])); ?></div>
            <?php endif; ?>
          </div>
          <div class="card-footer bg-white">
            <form method="post" action="<?php echo base_url('admin/outings.php'); ?>" class="d-flex gap-2" data-confirm="Ubah status outing ini? Data peserta dan keuangan tidak akan hilang.">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="task" value="set_status">
              <input type="hidden" name="outing_id" value="<?php echo $outingId; ?>">
              <select name="status" class="form-select form-select-sm">
                <?php foreach ($ENUM_STATUS as $s): ?>
                  <option value="<?php echo $s; ?>" <?php echo ($outing['status'] === $s) ? 'selected' : ''; ?>><?php echo $s; ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-sm btn-primary nowrap"><i class="bi bi-arrow-repeat me-1"></i>Ubah</button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="row g-3 mb-3">
          <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body stat-card py-3">
              <span class="stat-icon bg-soft-success"><i class="bi bi-people-fill"></i></span>
              <div><div class="stat-label">Ikut</div><div class="stat-value"><?php echo $fin['ikut']; ?></div></div>
            </div></div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body stat-card py-3">
              <span class="stat-icon bg-soft-warning"><i class="bi bi-person-x"></i></span>
              <div><div class="stat-label">Batal / Tidak</div><div class="stat-value"><?php echo $fin['batal'] + $fin['tidak_ikut']; ?></div></div>
            </div></div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body stat-card py-3">
              <span class="stat-icon bg-soft-primary"><i class="bi bi-receipt"></i></span>
              <div><div class="stat-label">Item Beli</div><div class="stat-value"><?php echo $totalPembelian; ?></div></div>
            </div></div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body stat-card py-3">
              <span class="stat-icon bg-soft-danger"><i class="bi bi-cash-coin"></i></span>
              <div><div class="stat-label">Pengeluaran</div><div class="stat-value fs-6"><?php echo rupiah($fin['total_pengeluaran'], false); ?></div></div>
            </div></div>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-pie-chart text-primary"></i>
            <span class="card-title-sm me-auto">Rekap Pengeluaran per Kategori</span>
            <a href="<?php echo base_url('admin/purchases.php?outing_id=' . $outingId); ?>" class="btn btn-sm btn-outline-primary">Kelola</a>
          </div>
          <div class="card-body">
            <?php
            $adaData = false;
            foreach ($perKategori as $k) {
                if ((float) $k['total'] > 0) { $adaData = true; break; }
            }
            if (!$adaData): ?>
              <p class="text-muted small mb-0"><i class="bi bi-inbox me-1"></i>Belum ada pembelian pada outing ini.</p>
            <?php else: ?>
              <?php foreach ($perKategori as $k):
                  if ((float) $k['total'] <= 0) { continue; }
                  $share = ($fin['total_pengeluaran'] > 0) ? round((float) $k['total'] / $fin['total_pengeluaran'] * 100) : 0;
              ?>
                <div class="bar-chart-row">
                  <span class="bar-chart-label"><?php echo e($k['nama']); ?></span>
                  <span class="bar-chart-track"><span class="bar-chart-fill" style="width: <?php echo $share; ?>%"></span></span>
                  <span class="bar-chart-value"><?php echo rupiah($k['total'], false); ?><small class="text-muted d-block fw-normal"><?php echo $share; ?>% &middot; <?php echo (int) $k['jumlah_item']; ?> item</small></span>
                </div>
              <?php endforeach; ?>
              <hr class="my-2">
              <div class="d-flex justify-content-between small">
                <span class="text-muted">Iuran terkumpul</span><strong><?php echo rupiah($fin['total_iuran']); ?></strong>
              </div>
              <div class="d-flex justify-content-between small">
                <span class="text-muted">Selisih (iuran &minus; pengeluaran)</span>
                <strong class="<?php echo ($fin['total_iuran'] - $fin['total_pengeluaran']) >= 0 ? 'text-success' : 'text-danger'; ?>">
                  <?php echo rupiah($fin['total_iuran'] - $fin['total_pengeluaran']); ?>
                </strong>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><span class="card-title-sm"><i class="bi bi-clock-history me-1 text-warning"></i>Ringkasan Rundown (<?php echo count($rundown); ?> agenda)</span></div>
          <div class="card-body p-0">
            <?php if (count($rundown) === 0): ?>
              <div class="table-empty"><i class="bi bi-calendar-x"></i>Belum ada rundown.
                <a href="<?php echo base_url('admin/rundown.php?outing_id=' . $outingId); ?>" class="btn btn-sm btn-primary mt-2">Susun Rundown</a>
              </div>
            <?php else: ?>
              <div class="table-wrap" style="max-height:260px;overflow-y:auto">
                <table class="table table-sm">
                  <thead><tr><th style="width:44px">No</th><th>Waktu</th><th>Acara</th><th>Lokasi</th></tr></thead>
                  <tbody>
                    <?php foreach ($rundown as $i => $r): ?>
                    <tr>
                      <td class="text-muted"><?php echo (int) $r['urutan'] ?: ($i + 1); ?></td>
                      <td class="nowrap fs-8"><?php echo $r['tanggal'] ? date('d/m', strtotime($r['tanggal'])) : '-'; ?> <?php echo jam($r['waktu_mulai']); ?></td>
                      <td><?php echo e($r['acara']); ?></td>
                      <td class="fs-8 text-muted"><?php echo e(str_limit($r['lokasi'], 26)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card">
          <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-people text-primary"></i>
            <span class="card-title-sm me-auto">Daftar Peserta (<?php echo count($peserta); ?>)</span>
            <a href="<?php echo base_url('admin/participants.php?outing_id=' . $outingId); ?>" class="btn btn-sm btn-outline-primary">Kelola Peserta</a>
          </div>
          <div class="card-body p-0">
            <?php if (count($peserta) === 0): ?>
              <div class="table-empty"><i class="bi bi-person-x"></i>Belum ada peserta ditambahkan.</div>
            <?php else: ?>
              <div class="table-wrap" style="max-height:300px;overflow-y:auto">
                <table class="table table-sm">
                  <thead><tr><th>Nama</th><th>Departemen</th><th class="text-center">Kehadiran</th><th class="text-center">Pembayaran</th><th class="text-end">Nominal</th></tr></thead>
                  <tbody>
                    <?php foreach ($peserta as $p): ?>
                    <tr>
                      <td><span class="fw-semibold"><?php echo e($p['nama']); ?></span> <span class="fs-8 text-muted">(<?php echo e($p['user_id']); ?>)</span></td>
                      <td class="fs-8 text-muted"><?php echo e($p['departemen'] ?: '-'); ?></td>
                      <td class="text-center"><?php echo badge_attendance($p['attendance_status']); ?></td>
                      <td class="text-center"><?php echo badge_payment($p['payment_status']); ?></td>
                      <td class="text-end nowrap"><?php echo rupiah($p['nominal_bayar']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if (count($pembelian) > 0): ?>
      <div class="col-12">
        <div class="card">
          <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-receipt text-success"></i>
            <span class="card-title-sm me-auto">Pembelian Terbaru (menampilkan <?php echo count($pembelian); ?> dari <?php echo $totalPembelian; ?>)</span>
            <a href="<?php echo base_url('admin/purchases.php?outing_id=' . $outingId); ?>" class="btn btn-sm btn-outline-primary">Semua Pembelian</a>
          </div>
          <div class="card-body p-0">
            <div class="table-wrap">
              <table class="table table-sm">
                <thead><tr><th>Tanggal</th><th>Item</th><th>Kategori</th><th class="text-center">Qty</th><th class="text-end">Harga</th><th class="text-end">Total</th><th class="text-center">Bukti</th></tr></thead>
                <tbody>
                  <?php foreach ($pembelian as $p): ?>
                  <tr>
                    <td class="fs-8 nowrap"><?php echo $p['tanggal_beli'] ? date('d/m/Y', strtotime($p['tanggal_beli'])) : '-'; ?></td>
                    <td><?php echo e($p['nama_barang']); ?></td>
                    <td><span class="badge badge-soft"><?php echo e($p['kategori']); ?></span></td>
                    <td class="text-center"><?php echo rtrim(rtrim(number_format((float) $p['qty'], 2, ',', '.'), '0'), ','); ?> <?php echo e($p['satuan']); ?></td>
                    <td class="text-end nowrap"><?php echo rupiah($p['harga_satuan'], false); ?></td>
                    <td class="text-end nowrap fw-semibold"><?php echo rupiah($p['total_harga'], false); ?></td>
                    <td class="text-center">
                      <?php if (!empty($p['bukti_file'])): ?>
                        <i class="bi bi-paperclip text-success" title="<?php echo e($p['bukti_file']); ?>"></i>
                      <?php else: ?>
                        <span class="text-muted">-</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php
    require_once INCLUDES_PATH . '/footer.php';
    exit;
}

/* ==========================================================================
 * MODE DAFTAR (LIST)
 * ========================================================================== */
$filterStatus = in_enum(get_str('status'), array_merge($ENUM_STATUS, ['Aktif', 'Semua']), 'Semua');
$filterTahun = get_str('tahun');
$cari = get_str('q');

$where = ['1 = 1'];
$params = [];

if ($filterStatus === 'Aktif') {
    $where[] = "o.status IN ('Planning','Active')";
} elseif ($filterStatus !== 'Semua') {
    $where[] = 'o.status = ?';
    $params[] = $filterStatus;
}
if ($filterTahun !== '' && preg_match('/^\d{4}$/', $filterTahun)) {
    $where[] = '(YEAR(o.tanggal_mulai) = ? OR YEAR(o.tanggal_selesai) = ?)';
    $params[] = (int) $filterTahun;
    $params[] = (int) $filterTahun;
}
if ($cari !== '') {
    $where[] = '(o.nama LIKE ? OR o.kode LIKE ? OR o.tujuan LIKE ? OR o.lokasi LIKE ?)';
    $like = '%' . $cari . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);
$outings = fetch_all(
    "SELECT o.*,
            (SELECT COUNT(*) FROM outing_participants op WHERE op.outing_id = o.id) AS jml_peserta,
            (SELECT COUNT(*) FROM outing_participants op WHERE op.outing_id = o.id AND op.attendance_status = 'Ikut') AS jml_ikut,
            (SELECT COALESCE(SUM(p.total_harga),0) FROM purchases p WHERE p.outing_id = o.id) AS total_belanja
     FROM outings o
     WHERE $whereSql
     ORDER BY o.tanggal_mulai DESC, o.id DESC
     LIMIT 200",
    $params
);

$daftarTahun = fetch_all('SELECT DISTINCT YEAR(tanggal_mulai) AS th FROM outings ORDER BY th DESC');

$qs = [];
if ($filterStatus !== 'Semua') { $qs['status'] = $filterStatus; }
if ($filterTahun !== '') { $qs['tahun'] = $filterTahun; }
if ($cari !== '') { $qs['q'] = $cari; }
$queryString = count($qs) > 0 ? '&' . http_build_query($qs) : '';

$pageTitle = 'Data Outing';
$pageSubtitle = 'Multi-outing: buat outing baru tanpa menimpa data lama. Outing berstatus <strong>Selesai</strong>/<strong>Cancelled</strong> otomatis masuk History.';
$activeMenu = 'outings';
$pageActions = '<a href="' . base_url('admin/outings.php?action=create') . '" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Buat Outing</a>';

require_once INCLUDES_PATH . '/header.php';
?>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" action="<?php echo base_url('admin/outings.php'); ?>" class="row g-2 align-items-end">
      <div class="col-6 col-md-3">
        <label class="form-label mb-1" for="q">Cari</label>
        <input type="text" class="form-control form-control-sm" id="q" name="q" value="<?php echo e($cari); ?>"
               placeholder="nama / kode / tujuan">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label mb-1" for="status">Status</label>
        <select class="form-select form-select-sm" id="status" name="status">
          <?php
          $opsi = array_merge(['Semua', 'Aktif'], $ENUM_STATUS);
          foreach ($opsi as $s):
          ?>
            <option value="<?php echo $s; ?>" <?php echo ($filterStatus === $s) ? 'selected' : ''; ?>><?php echo $s; ?></option>
          <?php endforeach; ?>
        </select>
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
      <div class="col-6 col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Terapkan</button>
        <a href="<?php echo base_url('admin/outings.php'); ?>" class="btn btn-sm btn-light">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center gap-2">
    <span class="card-title-sm me-auto">Daftar Outing <span class="badge bg-light text-dark border ms-1"><?php echo count($outings); ?></span></span>
    <input type="search" class="form-control form-control-sm" style="max-width:220px"
           placeholder="Saring tabel..." data-table-filter="#tblOuting" data-filter-count="#filterCount">
    <span class="fs-8 text-muted" id="filterCount"><?php echo count($outings); ?> data</span>
  </div>
  <div class="card-body p-0">
    <?php if (count($outings) === 0): ?>
      <div class="table-empty">
        <i class="bi bi-search"></i> Tidak ada outing yang cocok dengan filter.
        <div class="mt-2"><a href="<?php echo base_url('admin/outings.php?action=create'); ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Buat Outing</a></div>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table align-middle" id="tblOuting">
        <thead>
          <tr>
            <th>Kode</th>
            <th>Nama &amp; Tujuan</th>
            <th class="nowrap">Tanggal</th>
            <th class="text-center">Status</th>
            <th class="text-center">Peserta</th>
            <th class="text-end">Anggaran</th>
            <th class="text-end">Pengeluaran</th>
            <th class="text-center nowrap">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($outings as $o): $oid = (int) $o['id']; ?>
          <tr data-searchable data-search="<?php echo e(strtolower($o['kode'] . ' ' . $o['nama'] . ' ' . $o['tujuan'] . ' ' . $o['status'])); ?>">
            <td class="nowrap"><span class="badge badge-soft"><?php echo e($o['kode']); ?></span></td>
            <td>
              <a href="<?php echo base_url('admin/outings.php?action=view&outing_id=' . $oid); ?>" class="text-strong"><?php echo e($o['nama']); ?></a>
              <div class="fs-8 text-muted"><i class="bi bi-geo-alt"></i> <?php echo e($o['tujuan']); ?><?php echo $o['lokasi'] ? ' &middot; ' . e(str_limit($o['lokasi'], 28)) : ''; ?></div>
            </td>
            <td class="fs-8 nowrap"><?php echo rentang_tanggal($o['tanggal_mulai'], $o['tanggal_selesai']); ?></td>
            <td class="text-center"><?php echo badge_status_outing($o['status']); ?></td>
            <td class="text-center">
              <span class="fw-bold"><?php echo (int) $o['jml_ikut']; ?></span>
              <span class="fs-8 text-muted">/ <?php echo (int) $o['jml_peserta']; ?></span>
            </td>
            <td class="text-end nowrap fs-8"><?php echo rupiah($o['anggaran'], false); ?></td>
            <td class="text-end nowrap fw-semibold"><?php echo rupiah($o['total_belanja'], false); ?></td>
            <td class="text-center nowrap">
              <a href="<?php echo base_url('admin/participants.php?outing_id=' . $oid); ?>" class="btn btn-sm btn-outline-primary btn-icon" title="Kelola" data-bs-toggle="tooltip"><i class="bi bi-sliders"></i></a>
              <a href="<?php echo base_url('admin/outings.php?action=edit&outing_id=' . $oid); ?>" class="btn btn-sm btn-outline-secondary btn-icon" title="Edit" data-bs-toggle="tooltip"><i class="bi bi-pencil"></i></a>
              <a href="<?php echo base_url('admin/export_pdf.php?outing_id=' . $oid . '&download=1' . $queryString); ?>" class="btn btn-sm btn-outline-danger btn-icon" title="Export PDF" data-bs-toggle="tooltip"><i class="bi bi-file-earmark-pdf"></i></a>
              <form method="post" action="<?php echo base_url('admin/outings.php'); ?>" class="d-inline"
                    data-confirm="Hapus outing <strong><?php echo e($o['nama']); ?></strong>?<br><span class='text-danger'>Seluruh peserta, rundown, pembelian, dan file bukti pada outing ini akan ikut terhapus permanen.</span>"
                    data-confirm-title="Hapus Outing" data-confirm-text="Ya, Hapus Permanen">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="task" value="delete">
                <input type="hidden" name="outing_id" value="<?php echo $oid; ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="Hapus" data-bs-toggle="tooltip"><i class="bi bi-trash3"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <tr data-empty-row style="display:none"><td colspan="8" class="table-empty"><i class="bi bi-search"></i>Tidak ada baris yang cocok dengan kata kunci saringan.</td></tr>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
