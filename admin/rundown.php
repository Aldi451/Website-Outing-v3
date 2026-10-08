<?php
/**
 * ==========================================================================
 *  ADMIN — admin/rundown.php  (CRUD RUNDOWN / JADWAL ACARA)
 * --------------------------------------------------------------------------
 *  - CRUD jadwal: hari, tanggal, waktu, acara, lokasi, PIC, keterangan
 *  - Atur urutan (tombol naik/turun via AJAX + "Rapikan Urutan")
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();

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

    /* ------------------------------------------------------------ SIMPAN */
    if ($task === 'save') {
        $id = post_int('id', 0);
        $hari = post_str('hari', '');
        $tanggal = parse_date(post_str('tanggal'));
        $mulai = post_str('waktu_mulai');
        $selesai = post_str('waktu_selesai');
        $acara = post_str('acara');
        $lokasi = post_str('lokasi');
        $pic = post_str('pic');
        $keterangan = post_str('keterangan');
        $urutan = post_int('urutan', 0);
        $errors = [];

        if ($acara === '' || text_length($acara) < 3) {
            $errors[] = 'Nama acara wajib diisi (minimal 3 karakter).';
        }
        if ($mulai !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $mulai)) {
            $errors[] = 'Format waktu mulai tidak valid (gunakan HH:MM).';
        }
        if ($selesai !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $selesai)) {
            $errors[] = 'Format waktu selesai tidak valid (gunakan HH:MM).';
        }
        if ($mulai !== '' && $selesai !== '' && $selesai < $mulai) {
            $errors[] = 'Waktu selesai tidak boleh sebelum waktu mulai.';
        }
        if (post_str('tanggal') !== '' && $tanggal === null) {
            $errors[] = 'Tanggal acara tidak valid.';
        }

        if (count($errors) > 0) {
            flash('danger', 'Rundown tidak tersimpan:<br>&bull; ' . implode('<br>&bull; ', $errors));
            redirect('admin/rundown.php?outing_id=' . $outingId);
        }

        $mulai = ($mulai !== '') ? substr($mulai, 0, 5) . ':00' : null;
        $selesai = ($selesai !== '') ? substr($selesai, 0, 5) . ':00' : null;

        if ($urutan < 1) {
            $maxUrutan = (int) fetch_value('SELECT COALESCE(MAX(urutan),0) FROM rundown WHERE outing_id = ?', [$outingId], 0);
            $urutan = $maxUrutan + 1;
        }

        if ($id > 0) {
            $existing = fetch_one('SELECT id FROM rundown WHERE id = ? AND outing_id = ? LIMIT 1', [$id, $outingId]);
            if ($existing === null) {
                flash('danger', 'Data rundown tidak ditemukan.');
                redirect('admin/rundown.php?outing_id=' . $outingId);
            }
            run_query(
                'UPDATE rundown SET hari = ?, tanggal = ?, waktu_mulai = ?, waktu_selesai = ?, acara = ?,
                        lokasi = ?, pic = ?, keterangan = ?, urutan = ?, updated_at = NOW()
                 WHERE id = ? AND outing_id = ?',
                [$hari, $tanggal, $mulai, $selesai, $acara, $lokasi, $pic, $keterangan, $urutan, $id, $outingId]
            );
            log_activity('Ubah rundown "' . $acara . '" outing ' . $outing['kode'], 'rundown', $id);
            flash('success', 'Rundown <strong>' . e($acara) . '</strong> berhasil diperbarui.');
        } else {
            $newId = insert_get_id(
                'INSERT INTO rundown (outing_id, urutan, hari, tanggal, waktu_mulai, waktu_selesai, acara,
                                      lokasi, pic, keterangan, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [$outingId, $urutan, $hari, $tanggal, $mulai, $selesai, $acara, $lokasi, $pic, $keterangan]
            );
            log_activity('Tambah rundown "' . $acara . '" outing ' . $outing['kode'], 'rundown', $newId);
            flash('success', 'Rundown <strong>' . e($acara) . '</strong> berhasil ditambahkan.');
        }
        redirect('admin/rundown.php?outing_id=' . $outingId);
    }

    /* ------------------------------------------------------------ HAPUS */
    if ($task === 'delete') {
        $id = post_int('id', 0);
        $row = fetch_one('SELECT * FROM rundown WHERE id = ? AND outing_id = ? LIMIT 1', [$id, $outingId]);
        if ($row === null) {
            flash('danger', 'Data rundown tidak ditemukan.');
            redirect('admin/rundown.php?outing_id=' . $outingId);
        }
        run_query('DELETE FROM rundown WHERE id = ?', [$id]);
        log_activity('Hapus rundown "' . $row['acara'] . '" outing ' . $outing['kode'], 'rundown', $id);
        flash('success', 'Rundown <strong>' . e($row['acara']) . '</strong> dihapus.');
        redirect('admin/rundown.php?outing_id=' . $outingId);
    }

    /* ---------------------------------------------------- RAPIKAN URUTAN */
    if ($task === 'renumber') {
        $rows = fetch_all('SELECT id FROM rundown WHERE outing_id = ? ORDER BY urutan ASC, id ASC', [$outingId]);
        $n = 0;
        foreach ($rows as $r) {
            $n++;
            run_query('UPDATE rundown SET urutan = ? WHERE id = ?', [$n, (int) $r['id']]);
        }
        log_activity('Rapikan urutan rundown (' . $n . ' baris) outing ' . $outing['kode'], 'rundown', $outingId);
        flash('success', 'Urutan rundown dirapikan menjadi 1 - ' . $n . '.');
        redirect('admin/rundown.php?outing_id=' . $outingId);
    }

    flash('danger', 'Perintah tidak dikenali.');
    redirect('admin/rundown.php?outing_id=' . $outingId);
}

/* ==========================================================================
 * DATA
 * ========================================================================== */
$rundown = fetch_all('SELECT * FROM rundown WHERE outing_id = ? ORDER BY urutan ASC, id ASC', [$outingId]);

// kelompokkan per hari (untuk tampilan grup)
$groups = [];
foreach ($rundown as $r) {
    $key = ($r['hari'] !== '') ? $r['hari'] : ($r['tanggal'] ? tgl_indo($r['tanggal']) : 'Umum');
    if (!isset($groups[$key])) {
        $groups[$key] = [];
    }
    $groups[$key][] = $r;
}

$nextUrutan = count($rundown) > 0 ? ((int) $rundown[count($rundown) - 1]['urutan'] + 1) : 1;

/* ---------------------------------------------------------------- layout */
$pageTitle = 'Kelola Rundown';
$pageSubtitle = 'Susun jadwal acara <strong>' . e($outing['nama']) . '</strong> &middot; ' . count($rundown) . ' agenda';
$activeMenu = $isHistory ? 'history' : 'outings';
$outingCtx = $outing;
$outingTab = 'rundown';
$pageActions =
    '<form method="post" action="' . base_url('admin/rundown.php') . '" class="d-inline" '
    . 'data-confirm="Rapikan nomor urut seluruh rundown menjadi 1 sampai ' . count($rundown) . '?" data-confirm-title="Rapikan Urutan" data-confirm-text="Ya, Rapikan" data-confirm-class="btn-primary">'
    . csrf_field() . '<input type="hidden" name="task" value="renumber"><input type="hidden" name="outing_id" value="' . $outingId . '">'
    . '<button type="submit" class="btn btn-outline-secondary btn-sm"' . (count($rundown) === 0 ? ' disabled' : '') . '><i class="bi bi-sort-numeric-down me-1"></i>Rapikan Urutan</button></form>'
    . '<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalRundown" onclick="omsRundownForm.reset()">'
    . '<i class="bi bi-plus-circle me-1"></i>Tambah Rundown</button>';

require_once INCLUDES_PATH . '/header.php';
?>

<?php if ($isHistory): ?>
<div class="alert alert-warning py-2 small">
  <i class="bi bi-archive me-1"></i> Outing berstatus <strong><?php echo e($outing['status']); ?></strong> (history).
  Perubahan rundown di sini adalah koreksi administratif dan tercatat pada log aktivitas.
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-clock-history text-warning"></i>
    <span class="card-title-sm me-auto">Daftar Rundown</span>
    <span class="fs-8 text-muted">Gunakan tombol <i class="bi bi-arrow-up"></i>/<i class="bi bi-arrow-down"></i> untuk mengatur urutan</span>
  </div>
  <div class="card-body p-0">
    <?php if (count($rundown) === 0): ?>
      <div class="table-empty">
        <i class="bi bi-calendar-plus"></i> Belum ada rundown untuk outing ini.
        <div class="mt-2">
          <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalRundown" onclick="omsRundownForm.reset()">
            <i class="bi bi-plus-circle me-1"></i>Tambah Rundown Pertama
          </button>
        </div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table align-middle" id="tblRundown">
          <thead>
            <tr>
              <th style="width:56px" class="text-center">Urut</th>
              <th style="width:110px">Hari/Tanggal</th>
              <th style="width:120px">Waktu</th>
              <th>Acara</th>
              <th style="width:170px">Lokasi</th>
              <th style="width:140px">PIC</th>
              <th style="width:130px" class="text-center">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php $no = 0; foreach ($rundown as $r): $no++; $rid = (int) $r['id']; ?>
            <tr data-searchable data-search="<?php echo e(strtolower($r['acara'] . ' ' . $r['lokasi'] . ' ' . $r['pic'] . ' ' . $r['hari'])); ?>">
              <td class="text-center">
                <div class="d-flex align-items-center justify-content-center gap-1">
                  <span class="badge bg-light text-dark border" style="min-width:30px"><?php echo (int) $r['urutan']; ?></span>
                  <span class="d-flex flex-column">
                    <button type="button" class="btn btn-sm btn-link btn-icon p-0 text-muted" data-move="up"
                            data-url="<?php echo base_url('api/rundown.php'); ?>" data-id="<?php echo $rid; ?>"
                            title="Naikkan" data-bs-toggle="tooltip"><i class="bi bi-caret-up-fill"></i></button>
                    <button type="button" class="btn btn-sm btn-link btn-icon p-0 text-muted" data-move="down"
                            data-url="<?php echo base_url('api/rundown.php'); ?>" data-id="<?php echo $rid; ?>"
                            title="Turunkan" data-bs-toggle="tooltip"><i class="bi bi-caret-down-fill"></i></button>
                  </span>
                </div>
              </td>
              <td class="fs-8">
                <?php if (!empty($r['hari'])): ?><span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle"><?php echo e($r['hari']); ?></span><?php endif; ?>
                <?php if (!empty($r['tanggal'])): ?><div class="text-muted"><?php echo date('d/m/Y', strtotime($r['tanggal'])); ?></div><?php endif; ?>
                <?php if (empty($r['hari']) && empty($r['tanggal'])): ?><span class="text-muted">-</span><?php endif; ?>
              </td>
              <td class="nowrap fs-8">
                <i class="bi bi-clock text-muted"></i>
                <?php echo $r['waktu_mulai'] ? jam($r['waktu_mulai']) : '--:--'; ?>
                &ndash;
                <?php echo $r['waktu_selesai'] ? jam($r['waktu_selesai']) : '--:--'; ?>
              </td>
              <td>
                <div class="text-strong"><?php echo e($r['acara']); ?></div>
                <?php if (!empty($r['keterangan'])): ?>
                  <div class="fs-8 text-muted"><i class="bi bi-info-circle"></i> <?php echo e(str_limit($r['keterangan'], 80)); ?></div>
                <?php endif; ?>
              </td>
              <td class="fs-8"><i class="bi bi-geo-alt text-muted"></i> <?php echo e($r['lokasi'] ?: '-'); ?></td>
              <td class="fs-8"><i class="bi bi-person-badge text-muted"></i> <?php echo e($r['pic'] ?: '-'); ?></td>
              <td class="text-center nowrap">
                <button type="button" class="btn btn-sm btn-outline-primary btn-icon" title="Edit"
                        data-bs-toggle="modal" data-bs-target="#modalRundown"
                        data-edit-id="<?php echo $rid; ?>"
                        data-edit-hari="<?php echo e($r['hari']); ?>"
                        data-edit-tanggal="<?php echo e($r['tanggal']); ?>"
                        data-edit-mulai="<?php echo e($r['waktu_mulai'] ? substr($r['waktu_mulai'], 0, 5) : ''); ?>"
                        data-edit-selesai="<?php echo e($r['waktu_selesai'] ? substr($r['waktu_selesai'], 0, 5) : ''); ?>"
                        data-edit-acara="<?php echo e($r['acara']); ?>"
                        data-edit-lokasi="<?php echo e($r['lokasi']); ?>"
                        data-edit-pic="<?php echo e($r['pic']); ?>"
                        data-edit-keterangan="<?php echo e($r['keterangan']); ?>"
                        data-edit-urutan="<?php echo (int) $r['urutan']; ?>">
                  <i class="bi bi-pencil"></i>
                </button>
                <form method="post" action="<?php echo base_url('admin/rundown.php'); ?>" class="d-inline"
                      data-confirm="Hapus rundown <strong><?php echo e($r['acara']); ?></strong>?"
                      data-confirm-title="Hapus Rundown" data-confirm-text="Ya, Hapus">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="task" value="delete">
                  <input type="hidden" name="outing_id" value="<?php echo $outingId; ?>">
                  <input type="hidden" name="id" value="<?php echo $rid; ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="Hapus" data-bs-toggle="tooltip"><i class="bi bi-trash3"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============================ MODAL RUNDOWN ============================ -->
<div class="modal fade" id="modalRundown" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form method="post" action="<?php echo base_url('admin/rundown.php'); ?>" class="modal-content" id="formRundown"
          data-next-urutan="<?php echo (int) $nextUrutan; ?>">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="task" value="save">
      <input type="hidden" name="outing_id" value="<?php echo $outingId; ?>">
      <input type="hidden" name="id" id="f_id" value="0">

      <div class="modal-header">
        <h5 class="modal-title" id="judulModalRundown"><i class="bi bi-calendar-plus me-2 text-primary"></i>Tambah Rundown</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>

      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label" for="f_acara">Nama Acara <span class="required-mark">*</span></label>
            <input type="text" class="form-control" id="f_acara" name="acara" maxlength="150" required
                   placeholder="contoh: Registrasi &amp; Kumpul Peserta">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="f_hari">Hari</label>
            <input type="text" class="form-control" id="f_hari" name="hari" maxlength="30" placeholder="contoh: Hari 1"
                   list="listHari">
            <datalist id="listHari">
              <?php foreach (array_keys($groups) as $g): ?><option value="<?php echo e($g); ?>"></option><?php endforeach; ?>
              <option value="Hari 1"></option><option value="Hari 2"></option><option value="Hari 3"></option>
            </datalist>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="f_tanggal">Tanggal</label>
            <input type="date" class="form-control" id="f_tanggal" name="tanggal"
                   min="<?php echo e($outing['tanggal_mulai']); ?>" max="<?php echo e($outing['tanggal_selesai']); ?>">
            <div class="form-text">Rentang outing: <?php echo rentang_tanggal($outing['tanggal_mulai'], $outing['tanggal_selesai']); ?></div>
          </div>

          <div class="col-md-3">
            <label class="form-label" for="f_mulai">Waktu Mulai</label>
            <input type="time" class="form-control" id="f_mulai" name="waktu_mulai">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="f_selesai">Waktu Selesai</label>
            <input type="time" class="form-control" id="f_selesai" name="waktu_selesai">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="f_urutan">Nomor Urut</label>
            <input type="number" class="form-control" id="f_urutan" name="urutan" min="1" max="999" value="<?php echo $nextUrutan; ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="f_pic">PIC / Penanggung Jawab</label>
            <input type="text" class="form-control" id="f_pic" name="pic" maxlength="100" placeholder="nama panitia">
          </div>

          <div class="col-12">
            <label class="form-label" for="f_lokasi">Lokasi</label>
            <input type="text" class="form-control" id="f_lokasi" name="lokasi" maxlength="150"
                   placeholder="contoh: Lobi Kantor Pusat" value="<?php echo e($outing['lokasi']); ?>">
          </div>
          <div class="col-12">
            <label class="form-label" for="f_keterangan">Keterangan</label>
            <textarea class="form-control" id="f_keterangan" name="keterangan" rows="2" maxlength="1000"
                      placeholder="catatan tambahan untuk peserta (dress code, perlengkapan, dll.)"></textarea>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan Rundown</button>
      </div>
    </form>
  </div>
</div>

<?php
$extraScripts = <<<'HTML'
<script>
/* Isi form rundown saat tombol Edit diklik (satu modal dipakai bersama). */
window.omsRundownForm = {
  reset: function () {
    var f = document.getElementById('formRundown');
    if (!f) { return; }
    f.reset();
    document.getElementById('f_id').value = '0';
    document.getElementById('f_urutan').value = f.getAttribute('data-next-urutan') || '1';
    document.getElementById('judulModalRundown').innerHTML =
      '<i class="bi bi-calendar-plus me-2 text-primary"></i>Tambah Rundown';
    var lokasi = document.getElementById('f_lokasi');
    if (lokasi && lokasi.getAttribute('data-default') !== null) {
      lokasi.value = lokasi.getAttribute('data-default');
    }
  },
  fill: function (btn) {
    var f = document.getElementById('formRundown');
    if (!f) { return; }
    f.reset();
    document.getElementById('f_id').value = btn.getAttribute('data-edit-id') || '0';
    document.getElementById('f_hari').value = btn.getAttribute('data-edit-hari') || '';
    document.getElementById('f_tanggal').value = btn.getAttribute('data-edit-tanggal') || '';
    document.getElementById('f_mulai').value = btn.getAttribute('data-edit-mulai') || '';
    document.getElementById('f_selesai').value = btn.getAttribute('data-edit-selesai') || '';
    document.getElementById('f_acara').value = btn.getAttribute('data-edit-acara') || '';
    document.getElementById('f_lokasi').value = btn.getAttribute('data-edit-lokasi') || '';
    document.getElementById('f_pic').value = btn.getAttribute('data-edit-pic') || '';
    document.getElementById('f_keterangan').value = btn.getAttribute('data-edit-keterangan') || '';
    document.getElementById('f_urutan').value = btn.getAttribute('data-edit-urutan') || '1';
    document.getElementById('judulModalRundown').innerHTML =
      '<i class="bi bi-pencil-square me-2 text-primary"></i>Ubah Rundown';
  }
};

(function () {
  var lokasi = document.getElementById('f_lokasi');
  if (lokasi) { lokasi.setAttribute('data-default', lokasi.value); }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-edit-id]');
    if (btn && btn.getAttribute('data-bs-target') === '#modalRundown') {
      window.omsRundownForm.fill(btn);
    }
  });

  // saring tabel rundown
  var search = document.querySelector('[data-table-filter="#tblRundown"]');
  if (!search) { return; }
})();
</script>
HTML;

require_once INCLUDES_PATH . '/footer.php';
