<?php
/**
 * ==========================================================================
 *  ADMIN — admin/categories.php  (KATEGORI PENGELUARAN DINAMIS)
 * --------------------------------------------------------------------------
 *  Kategori dapat ditambah/diubah/dinonaktifkan manual oleh admin.
 *  Kategori yang SUDAH dipakai pada pembelian tidak dapat dihapus
 *  (FOREIGN KEY ... ON DELETE RESTRICT) — hanya bisa dinonaktifkan.
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();

/* ==========================================================================
 * PROSES POST
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $task = post_str('task');

    /* ---------------------------------------------------------- SIMPAN */
    if ($task === 'save') {
        $id = post_int('id', 0);
        $nama = post_str('nama');
        $keterangan = post_str('keterangan');
        $aktif = (post_str('is_aktif', '1') === '1') ? 1 : 0;
        $errors = [];

        if ($nama === '' || text_length($nama) < 3) {
            $errors[] = 'Nama kategori minimal 3 karakter.';
        }
        if (text_length($nama) > 100) {
            $errors[] = 'Nama kategori maksimal 100 karakter.';
        }
        if (count($errors) === 0) {
            $dup = fetch_one('SELECT id FROM categories WHERE nama = ? AND id <> ? LIMIT 1', [$nama, $id]);
            if ($dup !== null) {
                $errors[] = 'Kategori <strong>' . e($nama) . '</strong> sudah ada.';
            }
        }

        if (count($errors) > 0) {
            flash('danger', implode('<br>', $errors));
            redirect('admin/categories.php');
        }

        if ($id > 0) {
            $ada = fetch_one('SELECT id FROM categories WHERE id = ? LIMIT 1', [$id]);
            if ($ada === null) {
                flash('danger', 'Kategori tidak ditemukan.');
                redirect('admin/categories.php');
            }
            run_query('UPDATE categories SET nama = ?, keterangan = ?, is_aktif = ?, updated_at = NOW() WHERE id = ?',
                [$nama, $keterangan, $aktif, $id]);
            log_activity('Ubah kategori "' . $nama . '"', 'categories', $id);
            flash('success', 'Kategori <strong>' . e($nama) . '</strong> diperbarui.');
        } else {
            $newId = insert_get_id(
                'INSERT INTO categories (nama, keterangan, is_aktif, created_at) VALUES (?, ?, ?, NOW())',
                [$nama, $keterangan, $aktif]
            );
            log_activity('Tambah kategori "' . $nama . '"', 'categories', $newId);
            flash('success', 'Kategori <strong>' . e($nama) . '</strong> berhasil ditambahkan.');
        }
        redirect('admin/categories.php');
    }

    /* ----------------------------------------------------------- HAPUS */
    if ($task === 'delete') {
        $id = post_int('id', 0);
        $row = fetch_one('SELECT * FROM categories WHERE id = ? LIMIT 1', [$id]);
        if ($row === null) {
            flash('danger', 'Kategori tidak ditemukan.');
            redirect('admin/categories.php');
        }

        $dipakai = (int) fetch_value('SELECT COUNT(*) FROM purchases WHERE category_id = ?', [$id], 0);
        if ($dipakai > 0) {
            flash('warning', 'Kategori <strong>' . e($row['nama']) . '</strong> sedang dipakai oleh '
                . $dipakai . ' transaksi pembelian sehingga <strong>tidak dapat dihapus</strong> '
                . '(proteksi integritas data). Gunakan tombol <em>Nonaktifkan</em> agar tidak muncul di pilihan.');
            redirect('admin/categories.php');
        }

        run_query('DELETE FROM categories WHERE id = ?', [$id]);
        log_activity('Hapus kategori "' . $row['nama'] . '"', 'categories', $id);
        flash('success', 'Kategori <strong>' . e($row['nama']) . '</strong> dihapus.');
        redirect('admin/categories.php');
    }

    /* ------------------------------------------------------ AKTIF/NONAKTIF */
    if ($task === 'toggle') {
        $id = post_int('id', 0);
        $row = fetch_one('SELECT * FROM categories WHERE id = ? LIMIT 1', [$id]);
        if ($row === null) {
            flash('danger', 'Kategori tidak ditemukan.');
            redirect('admin/categories.php');
        }
        $baru = ((int) $row['is_aktif'] === 1) ? 0 : 1;
        run_query('UPDATE categories SET is_aktif = ?, updated_at = NOW() WHERE id = ?', [$baru, $id]);
        log_activity(($baru === 1 ? 'Aktifkan' : 'Nonaktifkan') . ' kategori "' . $row['nama'] . '"', 'categories', $id);
        flash('success', 'Kategori <strong>' . e($row['nama']) . '</strong> kini ' . ($baru === 1 ? 'AKTIF' : 'NONAKTIF') . '.');
        redirect('admin/categories.php');
    }

    flash('danger', 'Perintah tidak dikenali.');
    redirect('admin/categories.php');
}

/* ==========================================================================
 * DATA
 * ========================================================================== */
$kategori = fetch_all(
    'SELECT c.*,
            (SELECT COUNT(*) FROM purchases p WHERE p.category_id = c.id) AS jumlah_transaksi,
            (SELECT COALESCE(SUM(p.total_harga),0) FROM purchases p WHERE p.category_id = c.id) AS total_nilai
     FROM categories c
     ORDER BY c.is_aktif DESC, c.nama ASC'
);

$totalKategoriAktif = 0;
$totalTransaksi = 0;
foreach ($kategori as $k) {
    if ((int) $k['is_aktif'] === 1) {
        $totalKategoriAktif++;
    }
    $totalTransaksi += (int) $k['jumlah_transaksi'];
}

$pageTitle = 'Kategori Biaya';
$pageSubtitle = 'Kategori pengeluaran bersifat <strong>dinamis</strong>: dapat ditambah, diubah, atau dinonaktifkan kapan saja.';
$activeMenu = 'categories';
$pageActions = '<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalKategori" onclick="omsKategoriForm.reset()">'
    . '<i class="bi bi-plus-circle me-1"></i>Tambah Kategori</button>';

require_once INCLUDES_PATH . '/header.php';
?>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-4">
    <div class="card"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-primary"><i class="bi bi-tags"></i></span>
      <div><div class="stat-label">Total Kategori</div><div class="stat-value"><?php echo count($kategori); ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-4">
    <div class="card"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-success"><i class="bi bi-check2-circle"></i></span>
      <div><div class="stat-label">Kategori Aktif</div><div class="stat-value"><?php echo $totalKategoriAktif; ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-4">
    <div class="card"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-warning"><i class="bi bi-receipt"></i></span>
      <div><div class="stat-label">Transaksi Terpakai</div><div class="stat-value"><?php echo $totalTransaksi; ?></div></div>
    </div></div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center gap-2">
    <span class="card-title-sm me-auto"><i class="bi bi-tags me-1 text-primary"></i>Daftar Kategori</span>
    <input type="search" class="form-control form-control-sm" style="max-width:220px"
           placeholder="Cari kategori..." data-table-filter="#tblKategori">
  </div>
  <div class="card-body p-0">
    <div class="table-wrap">
      <table class="table align-middle" id="tblKategori">
        <thead>
          <tr>
            <th style="width:50px" class="text-center">No</th>
            <th>Nama Kategori</th>
            <th>Keterangan</th>
            <th class="text-center" style="width:120px">Transaksi</th>
            <th class="text-end" style="width:150px">Total Nilai</th>
            <th class="text-center" style="width:110px">Status</th>
            <th class="text-center" style="width:170px">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($kategori) === 0): ?>
            <tr><td colspan="7" class="table-empty"><i class="bi bi-tags"></i>Belum ada kategori. Tambahkan kategori pertama.</td></tr>
          <?php else: $no = 0; foreach ($kategori as $k): $no++; $kid = (int) $k['id']; ?>
            <tr data-searchable data-search="<?php echo e(strtolower($k['nama'] . ' ' . $k['keterangan'])); ?>">
              <td class="text-muted text-center"><?php echo $no; ?></td>
              <td class="text-strong"><?php echo e($k['nama']); ?></td>
              <td class="fs-8 text-muted"><?php echo e($k['keterangan'] ?: '-'); ?></td>
              <td class="text-center"><?php echo (int) $k['jumlah_transaksi']; ?></td>
              <td class="text-end nowrap"><?php echo rupiah($k['total_nilai'], false); ?></td>
              <td class="text-center">
                <?php echo ((int) $k['is_aktif'] === 1)
                    ? '<span class="badge bg-success">Aktif</span>'
                    : '<span class="badge bg-secondary">Nonaktif</span>'; ?>
              </td>
              <td class="text-center nowrap">
                <button type="button" class="btn btn-sm btn-outline-primary btn-icon" title="Edit"
                        data-bs-toggle="modal" data-bs-target="#modalKategori"
                        data-edit-id="<?php echo $kid; ?>"
                        data-edit-nama="<?php echo e($k['nama']); ?>"
                        data-edit-keterangan="<?php echo e($k['keterangan']); ?>"
                        data-edit-aktif="<?php echo (int) $k['is_aktif']; ?>">
                  <i class="bi bi-pencil"></i>
                </button>

                <form method="post" action="<?php echo base_url('admin/categories.php'); ?>" class="d-inline"
                      data-confirm="<?php echo ((int) $k['is_aktif'] === 1) ? 'Nonaktifkan' : 'Aktifkan kembali'; ?> kategori <strong><?php echo e($k['nama']); ?></strong>?"
                      data-confirm-title="Ubah Status Kategori" data-confirm-text="Ya, Lanjutkan" data-confirm-class="btn-primary">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="task" value="toggle">
                  <input type="hidden" name="id" value="<?php echo $kid; ?>">
                  <button type="submit" class="btn btn-sm btn-outline-<?php echo ((int) $k['is_aktif'] === 1) ? 'warning' : 'success'; ?> btn-icon"
                          title="<?php echo ((int) $k['is_aktif'] === 1) ? 'Nonaktifkan' : 'Aktifkan'; ?>" data-bs-toggle="tooltip">
                    <i class="bi <?php echo ((int) $k['is_aktif'] === 1) ? 'bi-toggle-on' : 'bi-toggle-off'; ?>"></i>
                  </button>
                </form>

                <?php if ((int) $k['jumlah_transaksi'] === 0): ?>
                <form method="post" action="<?php echo base_url('admin/categories.php'); ?>" class="d-inline"
                      data-confirm="Hapus kategori <strong><?php echo e($k['nama']); ?></strong>?<br>Tindakan ini tidak dapat dibatalkan."
                      data-confirm-title="Hapus Kategori" data-confirm-text="Ya, Hapus">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="task" value="delete">
                  <input type="hidden" name="id" value="<?php echo $kid; ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="Hapus" data-bs-toggle="tooltip"><i class="bi bi-trash3"></i></button>
                </form>
                <?php else: ?>
                <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" disabled
                        title="Tidak dapat dihapus: sudah dipakai <?php echo (int) $k['jumlah_transaksi']; ?> transaksi" data-bs-toggle="tooltip">
                  <i class="bi bi-lock"></i>
                </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          <tr data-empty-row style="display:none"><td colspan="7" class="table-empty"><i class="bi bi-search"></i>Tidak ada kategori yang cocok.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card-footer bg-white small text-muted">
    <i class="bi bi-shield-check me-1"></i>
    Kategori yang sudah dipakai transaksi dilindungi <code>FOREIGN KEY ... ON DELETE RESTRICT</code> sehingga data pembelian tidak pernah kehilangan kategori.
  </div>
</div>

<!-- ============================ MODAL KATEGORI ============================ -->
<div class="modal fade" id="modalKategori" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" action="<?php echo base_url('admin/categories.php'); ?>" class="modal-content" id="formKategori">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="task" value="save">
      <input type="hidden" name="id" id="k_id" value="0">

      <div class="modal-header">
        <h5 class="modal-title" id="judulModalKategori"><i class="bi bi-tags me-2 text-primary"></i>Tambah Kategori</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="k_nama">Nama Kategori <span class="required-mark">*</span></label>
          <input type="text" class="form-control" id="k_nama" name="nama" maxlength="100" required
                 placeholder="contoh: Transportasi">
          <div class="form-text">Harus unik (tidak boleh sama dengan kategori lain).</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="k_keterangan">Keterangan</label>
          <textarea class="form-control" id="k_keterangan" name="keterangan" rows="2" maxlength="255"
                    placeholder="contoh: sewa bus, BBM, tol, parkir"></textarea>
        </div>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="k_aktif" name="is_aktif" value="1" checked>
          <label class="form-check-label" for="k_aktif">Kategori aktif (muncul pada pilihan pembelian)</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan</button>
      </div>
    </form>
  </div>
</div>

<?php
$extraScripts = <<<'HTML'
<script>
window.omsKategoriForm = {
  reset: function () {
    var f = document.getElementById('formKategori');
    if (!f) { return; }
    f.reset();
    document.getElementById('k_id').value = '0';
    document.getElementById('k_aktif').checked = true;
    document.getElementById('judulModalKategori').innerHTML =
      '<i class="bi bi-tags me-2 text-primary"></i>Tambah Kategori';
  },
  fill: function (btn) {
    document.getElementById('k_id').value = btn.getAttribute('data-edit-id') || '0';
    document.getElementById('k_nama').value = btn.getAttribute('data-edit-nama') || '';
    document.getElementById('k_keterangan').value = btn.getAttribute('data-edit-keterangan') || '';
    document.getElementById('k_aktif').checked = (btn.getAttribute('data-edit-aktif') === '1');
    document.getElementById('judulModalKategori').innerHTML =
      '<i class="bi bi-pencil-square me-2 text-primary"></i>Ubah Kategori';
  }
};

document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-edit-id]');
  if (btn && btn.getAttribute('data-bs-target') === '#modalKategori') {
    window.omsKategoriForm.fill(btn);
  }
});
</script>
HTML;

require_once INCLUDES_PATH . '/footer.php';
