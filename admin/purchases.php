<?php
/**
 * ==========================================================================
 *  ADMIN — admin/purchases.php  (CRUD PEMBELIAN / PENGELUARAN)
 * --------------------------------------------------------------------------
 *  - Pilih kategori DINAMIS (dikelola di admin/categories.php)
 *  - Total dihitung OTOMATIS = Qty x Harga (di browser & dihitung ulang server)
 *  - Upload bukti JPG/PNG/PDF ke /uploads/outing/{outing_id}/
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
 * STREAMING FILE BUKTI (harus sebelum ada output HTML!)
 * Akses langsung ke /uploads diblok .htaccess, jadi file disajikan lewat PHP
 * setelah hak akses admin diverifikasi (anti path traversal).
 * ========================================================================== */
if (get_str('action') === 'bukti') {
    $purchaseId = get_int('id', 0);
    $row = fetch_one('SELECT * FROM purchases WHERE id = ? AND outing_id = ? LIMIT 1', [$purchaseId, $outingId]);

    if ($row === null || empty($row['bukti_file'])) {
        http_response_code(404);
        exit('File bukti tidak ditemukan.');
    }

    $path = bukti_path($outingId, $row['bukti_file']);
    if ($path === false) {
        http_response_code(404);
        exit('File bukti sudah tidak ada di server.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . bukti_mime($row['bukti_file']));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="bukti-' . (int) $row['id'] . '-' . basename($row['bukti_file']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=60');
    readfile($path);
    exit;
}

/* ==========================================================================
 * PROSES POST
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $task = post_str('task');

    /* ------------------------------------------------------------ SIMPAN */
    if ($task === 'save') {
        $id = post_int('id', 0);
        $categoryId = post_int('category_id', 0);
        $namaBarang = post_str('nama_barang');
        $qty = parse_number(post_str('qty', '1'));
        $satuan = post_str('satuan', 'pcs');
        $harga = parse_number(post_str('harga_satuan', '0'));
        $tanggalBeli = parse_date(post_str('tanggal_beli'));
        $keterangan = post_str('keterangan');
        $errors = [];

        if ($namaBarang === '' || text_length($namaBarang) < 3) {
            $errors[] = 'Nama barang/keperluan wajib diisi (minimal 3 karakter).';
        }
        $kategori = fetch_one('SELECT * FROM categories WHERE id = ? LIMIT 1', [$categoryId]);
        if ($kategori === null) {
            $errors[] = 'Kategori tidak ditemukan. Pilih kategori yang valid atau buat kategori baru.';
        }
        if ($qty <= 0) {
            $errors[] = 'Qty harus lebih besar dari 0.';
        }
        if ($qty > 1000000) {
            $errors[] = 'Qty terlalu besar (maksimal 1.000.000).';
        }
        if ($harga < 0) {
            $errors[] = 'Harga satuan tidak boleh negatif.';
        }
        if ($harga > 999999999) {
            $errors[] = 'Harga satuan terlalu besar.';
        }
        if (post_str('tanggal_beli') !== '' && $tanggalBeli === null) {
            $errors[] = 'Tanggal pembelian tidak valid.';
        }
        if ($tanggalBeli === null) {
            $tanggalBeli = date('Y-m-d');
        }

        /* -------- upload bukti (opsional) -------- */
        $buktiFile = '';
        $uploadInfo = null;
        if (isset($_FILES['bukti_file']) && !empty($_FILES['bukti_file']['name'])) {
            $uploadInfo = upload_bukti($_FILES['bukti_file'], $outingId);
            if (!$uploadInfo['ok']) {
                $errors[] = 'Upload bukti gagal: ' . $uploadInfo['message'];
            } else {
                $buktiFile = $uploadInfo['filename'];
            }
        }

        if (count($errors) > 0) {
            // file yang terlanjur terupload dibersihkan agar tidak jadi sampah
            if ($buktiFile !== '') {
                delete_bukti($outingId, $buktiFile);
            }
            flash('danger', 'Pembelian tidak tersimpan:<br>&bull; ' . implode('<br>&bull; ', $errors));
            redirect('admin/purchases.php?outing_id=' . $outingId);
        }

        // TOTAL SELALU dihitung ulang di server: Qty x Harga
        $total = round($qty * $harga, 2);

        if ($id > 0) {
            $existing = fetch_one('SELECT * FROM purchases WHERE id = ? AND outing_id = ? LIMIT 1', [$id, $outingId]);
            if ($existing === null) {
                flash('danger', 'Data pembelian tidak ditemukan.');
                redirect('admin/purchases.php?outing_id=' . $outingId);
            }

            // jika ada bukti baru, hapus bukti lama
            if ($buktiFile !== '' && !empty($existing['bukti_file'])) {
                delete_bukti($outingId, $existing['bukti_file']);
            }
            if ($buktiFile === '') {
                $buktiFile = $existing['bukti_file'];
            }

            run_query(
                'UPDATE purchases SET category_id = ?, nama_barang = ?, qty = ?, satuan = ?, harga_satuan = ?,
                        total_harga = ?, tanggal_beli = ?, bukti_file = ?, keterangan = ?, updated_at = NOW()
                 WHERE id = ? AND outing_id = ?',
                [$categoryId, $namaBarang, $qty, $satuan, $harga, $total, $tanggalBeli, $buktiFile, $keterangan, $id, $outingId]
            );
            log_activity('Ubah pembelian "' . $namaBarang . '" (' . rupiah($total) . ') outing ' . $outing['kode'], 'purchases', $id);
            flash('success', 'Pembelian <strong>' . e($namaBarang) . '</strong> diperbarui. Total: ' . rupiah($total) . '.');
        } else {
            $newId = insert_get_id(
                'INSERT INTO purchases (outing_id, category_id, nama_barang, qty, satuan, harga_satuan, total_harga,
                                        tanggal_beli, bukti_file, keterangan, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [$outingId, $categoryId, $namaBarang, $qty, $satuan, $harga, $total, $tanggalBeli,
                 $buktiFile, $keterangan, current_uid()]
            );
            log_activity('Tambah pembelian "' . $namaBarang . '" (' . rupiah($total) . ') outing ' . $outing['kode'], 'purchases', $newId);
            flash('success', 'Pembelian <strong>' . e($namaBarang) . '</strong> ditambahkan. Total: ' . rupiah($total)
                . ($buktiFile !== '' ? ' &middot; bukti terupload.' : ' &middot; <em>tanpa bukti</em>'));
        }
        redirect('admin/purchases.php?outing_id=' . $outingId);
    }

    /* ------------------------------------------------------------ HAPUS */
    if ($task === 'delete') {
        $id = post_int('id', 0);
        $row = fetch_one('SELECT * FROM purchases WHERE id = ? AND outing_id = ? LIMIT 1', [$id, $outingId]);
        if ($row === null) {
            flash('danger', 'Data pembelian tidak ditemukan.');
            redirect('admin/purchases.php?outing_id=' . $outingId);
        }
        if (!empty($row['bukti_file'])) {
            delete_bukti($outingId, $row['bukti_file']);
        }
        run_query('DELETE FROM purchases WHERE id = ?', [$id]);
        log_activity('Hapus pembelian "' . $row['nama_barang'] . '" outing ' . $outing['kode'], 'purchases', $id);
        flash('success', 'Pembelian <strong>' . e($row['nama_barang']) . '</strong> beserta file buktinya dihapus.');
        redirect('admin/purchases.php?outing_id=' . $outingId);
    }

    flash('danger', 'Perintah tidak dikenali.');
    redirect('admin/purchases.php?outing_id=' . $outingId);
}

/* ==========================================================================
 * DATA
 * ========================================================================== */
$filterKategori = get_int('category_id', 0);
$cari = get_str('q');

$where = ['p.outing_id = ?'];
$params = [$outingId];
if ($filterKategori > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $filterKategori;
}
if ($cari !== '') {
    $where[] = '(p.nama_barang LIKE ? OR p.keterangan LIKE ? OR c.nama LIKE ?)';
    $params[] = '%' . $cari . '%';
    $params[] = '%' . $cari . '%';
    $params[] = '%' . $cari . '%';
}
$whereSql = implode(' AND ', $where);

$pembelian = fetch_all(
    "SELECT p.*, c.nama AS kategori
     FROM purchases p
     JOIN categories c ON c.id = p.category_id
     WHERE $whereSql
     ORDER BY p.tanggal_beli DESC, p.id DESC
     LIMIT 500",
    $params
);

$kategoriList = fetch_all('SELECT * FROM categories WHERE is_aktif = 1 ORDER BY nama ASC');
$perKategori = outing_by_category($outingId);
$fin = outing_finance($outingId);

$sisaAnggaran = (float) $outing['anggaran'] - $fin['total_pengeluaran'];
$persenSerap = ((float) $outing['anggaran'] > 0)
    ? min(100, round($fin['total_pengeluaran'] / (float) $outing['anggaran'] * 100))
    : 0;

/* ---------------------------------------------------------------- layout */
$pageTitle = 'Kelola Pembelian';
$pageSubtitle = 'Catat pengeluaran <strong>' . e($outing['nama']) . '</strong> &middot; total otomatis Qty &times; Harga';
$activeMenu = $isHistory ? 'history' : 'outings';
$outingCtx = $outing;
$outingTab = 'pembelian';
$pageActions =
    '<a href="' . base_url('admin/import.php?type=pembelian&outing_id=' . $outingId) . '" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Import Excel</a>'
    . '<a href="' . base_url('admin/categories.php') . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-tags me-1"></i>Kategori</a>'
    . '<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalBeli" onclick="omsPurchaseForm.reset()">'
    . '<i class="bi bi-plus-circle me-1"></i>Tambah Pembelian</button>';

require_once INCLUDES_PATH . '/header.php';
?>

<?php if ($isHistory): ?>
<div class="alert alert-warning py-2 small">
  <i class="bi bi-archive me-1"></i> Outing berstatus <strong><?php echo e($outing['status']); ?></strong> (history) — perubahan dicatat sebagai koreksi administratif.
</div>
<?php endif; ?>

<!-- ============================ RINGKASAN ============================ -->
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-danger"><i class="bi bi-cash-stack"></i></span>
      <div><div class="stat-label">Total Pengeluaran</div>
        <div class="stat-value fs-5"><?php echo rupiah($fin['total_pengeluaran'], false); ?></div>
        <div class="stat-sub"><?php echo count($pembelian); ?> item transaksi</div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-primary"><i class="bi bi-wallet2"></i></span>
      <div><div class="stat-label">Anggaran</div>
        <div class="stat-value fs-5"><?php echo rupiah($outing['anggaran'], false); ?></div>
        <div class="stat-sub">terserap <?php echo $persenSerap; ?>%</div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body stat-card py-3">
      <span class="stat-icon <?php echo $sisaAnggaran >= 0 ? 'bg-soft-success' : 'bg-soft-warning'; ?>"><i class="bi bi-piggy-bank"></i></span>
      <div><div class="stat-label"><?php echo $sisaAnggaran >= 0 ? 'Sisa Anggaran' : 'Kelebihan Belanja'; ?></div>
        <div class="stat-value fs-5"><?php echo rupiah(abs($sisaAnggaran), false); ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-info"><i class="bi bi-people"></i></span>
      <div><div class="stat-label">Iuran Terkumpul</div>
        <div class="stat-value fs-5"><?php echo rupiah($fin['total_iuran'], false); ?></div>
        <div class="stat-sub"><?php echo $fin['lunas']; ?> peserta lunas</div></div>
    </div></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <span class="card-title-sm me-auto"><i class="bi bi-receipt me-1 text-success"></i>Daftar Pembelian</span>
        <select class="form-select form-select-sm" style="max-width:170px" id="filterKategori">
          <option value="0">Semua Kategori</option>
          <?php foreach ($kategoriList as $k): ?>
            <option value="<?php echo (int) $k['id']; ?>" <?php echo ($filterKategori === (int) $k['id']) ? 'selected' : ''; ?>><?php echo e($k['nama']); ?></option>
          <?php endforeach; ?>
        </select>
        <input type="search" class="form-control form-control-sm" style="max-width:180px"
               placeholder="Cari item..." data-table-filter="#tblBeli" data-filter-count="#filterCountBeli">
      </div>
      <div class="card-body p-0">
        <?php if (count($pembelian) === 0): ?>
          <div class="table-empty">
            <i class="bi bi-receipt-cutoff"></i> Belum ada pembelian pada filter ini.
            <div class="mt-2">
              <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalBeli" onclick="omsPurchaseForm.reset()">
                <i class="bi bi-plus-circle me-1"></i>Tambah Pembelian
              </button>
              <a class="btn btn-outline-success btn-sm" href="<?php echo base_url('admin/import.php?type=pembelian&outing_id=' . $outingId); ?>">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Import Excel
              </a>
            </div>
          </div>
        <?php else: ?>
        <div class="table-wrap">
          <table class="table align-middle" id="tblBeli">
            <thead>
              <tr>
                <th style="width:92px">Tanggal</th>
                <th>Item / Keterangan</th>
                <th style="width:130px">Kategori</th>
                <th class="text-center" style="width:96px">Qty</th>
                <th class="text-end" style="width:120px">Harga</th>
                <th class="text-end" style="width:130px">Total</th>
                <th class="text-center" style="width:80px">Bukti</th>
                <th class="text-center" style="width:110px">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php $grand = 0.0; foreach ($pembelian as $p): $pid = (int) $p['id']; $grand += (float) $p['total_harga']; ?>
              <tr data-searchable data-search="<?php echo e(strtolower($p['nama_barang'] . ' ' . $p['kategori'] . ' ' . $p['keterangan'])); ?>">
                <td class="fs-8 nowrap"><?php echo $p['tanggal_beli'] ? date('d/m/Y', strtotime($p['tanggal_beli'])) : '-'; ?></td>
                <td>
                  <div class="text-strong"><?php echo e($p['nama_barang']); ?></div>
                  <?php if (!empty($p['keterangan'])): ?>
                    <div class="fs-8 text-muted"><?php echo e(str_limit($p['keterangan'], 70)); ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="badge badge-soft"><?php echo e($p['kategori']); ?></span></td>
                <td class="text-center nowrap"><?php echo rtrim(rtrim(number_format((float) $p['qty'], 2, ',', '.'), '0'), ','); ?> <span class="text-muted fs-8"><?php echo e($p['satuan']); ?></span></td>
                <td class="text-end nowrap fs-8"><?php echo rupiah($p['harga_satuan'], false); ?></td>
                <td class="text-end nowrap fw-semibold"><?php echo rupiah($p['total_harga'], false); ?></td>
                <td class="text-center">
                  <?php if (!empty($p['bukti_file'])): ?>
                    <div class="d-inline-flex flex-column gap-1">
                      <a href="<?php echo base_url('admin/purchases.php?action=bukti&outing_id=' . $outingId . '&id=' . $pid); ?>"
                         target="_blank" rel="noopener" title="Lihat bukti: <?php echo e($p['bukti_file']); ?>" data-bs-toggle="tooltip">
                        <?php if (preg_match('/\.(jpg|jpeg|png)$/i', $p['bukti_file'])): ?>
                          <img src="<?php echo base_url('admin/purchases.php?action=bukti&outing_id=' . $outingId . '&id=' . $pid); ?>"
                               alt="Bukti" class="bukti-thumb">
                        <?php else: ?>
                          <span class="bukti-icon"><i class="bi bi-file-earmark-pdf"></i></span>
                        <?php endif; ?>
                      </a>
                    </div>
                  <?php else: ?>
                    <span class="badge bg-light text-muted border">belum ada</span>
                  <?php endif; ?>
                </td>
                <td class="text-center nowrap">
                  <button type="button" class="btn btn-sm btn-outline-primary btn-icon" title="Edit"
                          data-bs-toggle="modal" data-bs-target="#modalBeli"
                          data-edit-id="<?php echo $pid; ?>"
                          data-edit-category="<?php echo (int) $p['category_id']; ?>"
                          data-edit-barang="<?php echo e($p['nama_barang']); ?>"
                          data-edit-qty="<?php echo e(rtrim(rtrim(number_format((float) $p['qty'], 2, '.', ''), '0'), '.')); ?>"
                          data-edit-satuan="<?php echo e($p['satuan']); ?>"
                          data-edit-harga="<?php echo e(number_format((float) $p['harga_satuan'], 0, '.', '')); ?>"
                          data-edit-tanggal="<?php echo e($p['tanggal_beli']); ?>"
                          data-edit-keterangan="<?php echo e($p['keterangan']); ?>"
                          data-edit-bukti="<?php echo e($p['bukti_file']); ?>">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-danger btn-icon" title="Hapus"
                          data-ajax-action="delete"
                          data-url="<?php echo base_url('api/purchases.php'); ?>"
                          data-params="<?php echo e(json_encode(['id' => $pid])); ?>"
                          data-confirm="Hapus pembelian <strong><?php echo e($p['nama_barang']); ?></strong> (<?php echo e(rupiah($p['total_harga'])); ?>)?<br>File bukti yang terlampir juga akan dihapus dari server."
                          data-confirm-title="Hapus Pembelian" data-confirm-text="Ya, Hapus">
                    <i class="bi bi-trash3"></i>
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
              <tr data-empty-row style="display:none"><td colspan="8" class="table-empty"><i class="bi bi-search"></i>Tidak ada data yang cocok.</td></tr>
            </tbody>
            <tfoot>
              <tr class="table-light">
                <th colspan="5" class="text-end">TOTAL (sesuai filter)</th>
                <th class="text-end"><?php echo rupiah($grand, false); ?></th>
                <th colspan="2"></th>
              </tr>
            </tfoot>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ======================= REKAP PER KATEGORI ======================= -->
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><span class="card-title-sm"><i class="bi bi-pie-chart me-1 text-primary"></i>Rekap per Kategori</span></div>
      <div class="card-body">
        <?php
        $adaIsi = false;
        foreach ($perKategori as $k) {
            if ((float) $k['total'] > 0) { $adaIsi = true; break; }
        }
        if (!$adaIsi): ?>
          <p class="text-muted small mb-0">Belum ada pengeluaran.</p>
        <?php else: ?>
          <?php foreach ($perKategori as $k):
              if ((float) $k['total'] <= 0) { continue; }
              $share = ($fin['total_pengeluaran'] > 0) ? round((float) $k['total'] / $fin['total_pengeluaran'] * 100) : 0; ?>
            <div class="bar-chart-row">
              <span class="bar-chart-label"><?php echo e($k['nama']); ?></span>
              <span class="bar-chart-track"><span class="bar-chart-fill" style="width:<?php echo $share; ?>%"></span></span>
              <span class="bar-chart-value"><?php echo rupiah($k['total'], false); ?>
                <small class="text-muted d-block fw-normal"><?php echo (int) $k['jumlah_item']; ?> item &middot; <?php echo $share; ?>%</small></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title-sm"><i class="bi bi-lightbulb me-1 text-warning"></i>Catatan</span></div>
      <div class="card-body small text-muted">
        <ul class="ps-3 mb-0">
          <li class="mb-1">Total <strong>selalu</strong> dihitung server sebagai Qty &times; Harga Satuan. Nilai total dari Excel import diabaikan.</li>
          <li class="mb-1">Bukti hanya menerima <strong>JPG, PNG, PDF</strong> maksimal <?php echo format_bytes(UPLOAD_MAX_SIZE); ?>.</li>
          <li class="mb-1">File bukti disimpan terpisah per outing di <code>/uploads/outing/<?php echo $outingId; ?>/</code>.</li>
          <li>Kategori dapat ditambah/diubah kapan saja lewat menu <a href="<?php echo base_url('admin/categories.php'); ?>">Kategori Biaya</a>.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<!-- ============================ MODAL PEMBELIAN ============================ -->
<div class="modal fade" id="modalBeli" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form method="post" action="<?php echo base_url('admin/purchases.php'); ?>" enctype="multipart/form-data"
          class="modal-content" id="formBeli">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="task" value="save">
      <input type="hidden" name="outing_id" value="<?php echo $outingId; ?>">
      <input type="hidden" name="id" id="b_id" value="0">

      <div class="modal-header">
        <h5 class="modal-title" id="judulModalBeli"><i class="bi bi-receipt me-2 text-success"></i>Tambah Pembelian</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>

      <div class="modal-body">
        <?php if (count($kategoriList) === 0): ?>
          <div class="alert alert-warning py-2 small">
            Belum ada kategori aktif. <a href="<?php echo base_url('admin/categories.php'); ?>">Buat kategori terlebih dahulu</a>.
          </div>
        <?php endif; ?>

        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label" for="b_barang">Nama Barang / Keperluan <span class="required-mark">*</span></label>
            <input type="text" class="form-control" id="b_barang" name="nama_barang" maxlength="150" required
                   placeholder="contoh: Sewa Bus Pariwisata 50 seat">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="b_kategori">Kategori <span class="required-mark">*</span></label>
            <select class="form-select" id="b_kategori" name="category_id" required>
              <option value="">- Pilih Kategori -</option>
              <?php foreach ($kategoriList as $k): ?>
                <option value="<?php echo (int) $k['id']; ?>"><?php echo e($k['nama']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label" for="b_qty">Qty <span class="required-mark">*</span></label>
            <input type="number" class="form-control text-end" id="b_qty" name="qty" step="0.01" min="0.01" value="1" required data-calc="qty">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="b_satuan">Satuan</label>
            <input type="text" class="form-control" id="b_satuan" name="satuan" maxlength="20" value="pcs" list="listSatuan">
            <datalist id="listSatuan">
              <option value="pcs"></option><option value="unit"></option><option value="paket"></option>
              <option value="pax"></option><option value="org"></option><option value="box"></option>
              <option value="kamar"></option><option value="hari"></option><option value="liter"></option>
            </datalist>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="b_harga">Harga Satuan (Rp) <span class="required-mark">*</span></label>
            <input type="text" class="form-control text-end" id="b_harga" name="harga_satuan" inputmode="numeric" value="0" required data-calc="harga">
          </div>
          <div class="col-md-3">
            <label class="form-label">Total (otomatis)</label>
            <input type="text" class="form-control text-end fw-bold bg-light" id="b_total" readonly data-calc="total" value="0">
            <div class="form-text">Qty &times; Harga &mdash; dihitung ulang server</div>
          </div>

          <div class="col-md-4">
            <label class="form-label" for="b_tanggal">Tanggal Pembelian</label>
            <input type="date" class="form-control" id="b_tanggal" name="tanggal_beli" value="<?php echo date('Y-m-d'); ?>">
          </div>
          <div class="col-md-8">
            <label class="form-label" for="b_keterangan">Keterangan</label>
            <input type="text" class="form-control" id="b_keterangan" name="keterangan" maxlength="255"
                   placeholder="contoh: termasuk driver &amp; BBM">
          </div>

          <div class="col-12">
            <label class="form-label" for="b_bukti">Bukti Transaksi <span class="fs-8 text-muted">(JPG / PNG / PDF, maks <?php echo format_bytes(UPLOAD_MAX_SIZE); ?>)</span></label>
            <input type="file" class="form-control" id="b_bukti" name="bukti_file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                   data-preview-target="#previewBukti">
            <div id="previewBukti" class="mt-2"><span class="text-muted small">Belum ada file dipilih.</span></div>
            <div id="buktiLama" class="mt-2" style="display:none">
              <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                <i class="bi bi-paperclip me-1"></i>Bukti saat ini: <span id="buktiLamaNama"></span>
              </span>
              <div class="form-text">Upload file baru untuk mengganti. Kosongkan bila tidak ingin mengubah bukti.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan Pembelian</button>
      </div>
    </form>
  </div>
</div>

<?php
$extraScripts = <<<'HTML'
<script>
window.omsPurchaseForm = {
  reset: function () {
    var f = document.getElementById('formBeli');
    if (!f) { return; }
    f.reset();
    document.getElementById('b_id').value = '0';
    document.getElementById('b_qty').value = '1';
    document.getElementById('b_harga').value = '0';
    document.getElementById('b_satuan').value = 'pcs';
    document.getElementById('b_tanggal').value = new Date().toISOString().slice(0, 10);
    document.getElementById('judulModalBeli').innerHTML =
      '<i class="bi bi-receipt me-2 text-success"></i>Tambah Pembelian';
    document.getElementById('buktiLama').style.display = 'none';
    document.getElementById('previewBukti').innerHTML = '<span class="text-muted small">Belum ada file dipilih.</span>';
    if (window.OMS_UTIL) { window.OMS_UTIL.recalc(f); }
  },
  fill: function (btn) {
    var f = document.getElementById('formBeli');
    if (!f) { return; }
    f.reset();
    document.getElementById('b_id').value = btn.getAttribute('data-edit-id') || '0';
    document.getElementById('b_kategori').value = btn.getAttribute('data-edit-category') || '';
    document.getElementById('b_barang').value = btn.getAttribute('data-edit-barang') || '';
    document.getElementById('b_qty').value = btn.getAttribute('data-edit-qty') || '1';
    document.getElementById('b_satuan').value = btn.getAttribute('data-edit-satuan') || 'pcs';
    document.getElementById('b_harga').value = btn.getAttribute('data-edit-harga') || '0';
    document.getElementById('b_tanggal').value = btn.getAttribute('data-edit-tanggal') || '';
    document.getElementById('b_keterangan').value = btn.getAttribute('data-edit-keterangan') || '';
    document.getElementById('judulModalBeli').innerHTML =
      '<i class="bi bi-pencil-square me-2 text-success"></i>Ubah Pembelian';

    var bukti = btn.getAttribute('data-edit-bukti') || '';
    var boxLama = document.getElementById('buktiLama');
    if (bukti !== '') {
      document.getElementById('buktiLamaNama').textContent = bukti;
      boxLama.style.display = '';
    } else {
      boxLama.style.display = 'none';
    }
    document.getElementById('previewBukti').innerHTML = '<span class="text-muted small">Belum ada file dipilih.</span>';
    if (window.OMS_UTIL) { window.OMS_UTIL.recalc(f); }
  }
};

(function () {
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-edit-id]');
    if (btn && btn.getAttribute('data-bs-target') === '#modalBeli') {
      window.omsPurchaseForm.fill(btn);
    }
  });

  // filter kategori -> reload dengan query string
  var fk = document.getElementById('filterKategori');
  if (fk) {
    fk.addEventListener('change', function () {
      var url = new URL(window.location.href);
      if (fk.value === '0') { url.searchParams.delete('category_id'); }
      else { url.searchParams.set('category_id', fk.value); }
      window.location.href = url.toString();
    });
  }
})();
</script>
HTML;

require_once INCLUDES_PATH . '/footer.php';
