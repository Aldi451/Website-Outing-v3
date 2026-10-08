<?php
/**
 * ==========================================================================
 *  ADMIN — admin/users.php  (CRUD PENGGUNA / USER)
 * --------------------------------------------------------------------------
 *  - Tambah, ubah, hapus user (admin & member)
 *  - Aktif / nonaktifkan akun (AJAX)
 *  - Reset password (acak atau manual) — TANPA email, hasil ditampilkan sekali
 *  - TIDAK ADA registrasi member mandiri & TIDAK ADA lupa password via email
 *    (sesuai spesifikasi): semua akun dibuat oleh Administrator.
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();

$ENUM_ROLE = ['admin', 'member'];
$ENUM_STATUS = ['aktif', 'nonaktif'];

/* ==========================================================================
 * PROSES POST
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $task = post_str('task');

    /* ------------------------------------------------------------ SIMPAN */
    if ($task === 'save') {
        $id = post_int('id', 0);
        $userIdLogin = strtoupper(post_str('user_id'));
        $nama = post_str('nama');
        $jabatan = post_str('jabatan');
        $departemen = post_str('departemen');
        $noHp = post_str('no_hp');
        $email = post_str('email');
        $role = in_enum(post_str('role', 'member'), $ENUM_ROLE, 'member');
        $status = in_enum(post_str('status', 'aktif'), $ENUM_STATUS, 'aktif');
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $password2 = isset($_POST['password2']) ? (string) $_POST['password2'] : '';
        $errors = [];

        if (!valid_user_id($userIdLogin)) {
            $errors[] = 'User ID tidak valid: 3-30 karakter, hanya huruf/angka/titik/strip/underscore.';
        }
        if ($nama === '' || text_length($nama) < 3) {
            $errors[] = 'Nama lengkap minimal 3 karakter.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Format email tidak valid (email bersifat opsional, tanpa verifikasi).';
        }
        if ($noHp !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $noHp)) {
            $errors[] = 'Nomor HP tidak valid.';
        }

        if ($id > 0) {
            $existing = fetch_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
            if ($existing === null) {
                flash('danger', 'Pengguna tidak ditemukan.');
                redirect('admin/users.php');
            }
            if ($password !== '' || $password2 !== '') {
                $valid = validate_password($password);
                if ($valid !== true) {
                    $errors[] = $valid;
                } elseif ($password !== $password2) {
                    $errors[] = 'Konfirmasi password tidak sama.';
                }
            }
            // cegah menurunkan role admin terakhir
            if ($existing['role'] === 'admin' && $role !== 'admin') {
                $adminAktif = (int) fetch_value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'aktif'", [], 0);
                if ($adminAktif <= 1) {
                    $errors[] = 'Ditolak: harus ada minimal satu Administrator aktif.';
                }
            }
        } else {
            if ($password === '') {
                $errors[] = 'Password wajib diisi untuk pengguna baru.';
            } else {
                $valid = validate_password($password);
                if ($valid !== true) {
                    $errors[] = $valid;
                } elseif ($password !== $password2) {
                    $errors[] = 'Konfirmasi password tidak sama.';
                }
            }
        }

        if (count($errors) === 0) {
            $dup = fetch_one('SELECT id FROM users WHERE user_id = ? AND id <> ? LIMIT 1', [$userIdLogin, $id]);
            if ($dup !== null) {
                $errors[] = 'User ID <strong>' . e($userIdLogin) . '</strong> sudah dipakai pengguna lain.';
            }
        }

        if (count($errors) > 0) {
            flash('danger', 'Data tidak tersimpan:<br>&bull; ' . implode('<br>&bull; ', $errors));
            redirect('admin/users.php' . ($id > 0 ? '?action=edit&id=' . $id : '?action=create'));
        }

        if ($id > 0) {
            $sql = 'UPDATE users SET user_id = ?, nama = ?, jabatan = ?, departemen = ?, no_hp = ?, email = ?,
                           role = ?, status = ?, updated_at = NOW()';
            $params = [$userIdLogin, $nama, $jabatan, $departemen, $noHp, $email, $role, $status];
            if ($password !== '') {
                $sql .= ', password = ?';
                $params[] = hash_password($password);
            }
            $sql .= ' WHERE id = ?';
            $params[] = $id;
            run_query($sql, $params);

            log_activity('Ubah data user ' . $userIdLogin . ($password !== '' ? ' (termasuk password)' : ''), 'users', $id);
            flash('success', 'Data pengguna <strong>' . e($nama) . '</strong> berhasil diperbarui'
                . ($password !== '' ? ' beserta password barunya.' : '.'));
        } else {
            $newId = insert_get_id(
                'INSERT INTO users (user_id, nama, jabatan, departemen, no_hp, email, password, role, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [$userIdLogin, $nama, $jabatan, $departemen, $noHp, $email, hash_password($password), $role, $status]
            );
            log_activity('Membuat user baru ' . $userIdLogin . ' (' . $role . ')', 'users', $newId);
            flash('success', 'Pengguna <strong>' . e($nama) . '</strong> (' . e($userIdLogin) . ') berhasil dibuat.');
        }
        redirect('admin/users.php');
    }

    /* ------------------------------------------------------------ HAPUS */
    if ($task === 'delete') {
        $id = post_int('id', 0);
        $row = fetch_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
        if ($row === null) {
            flash('danger', 'Pengguna tidak ditemukan.');
            redirect('admin/users.php');
        }
        if ($id === current_uid()) {
            flash('danger', 'Anda tidak dapat menghapus akun sendiri.');
            redirect('admin/users.php');
        }
        if ($row['role'] === 'admin') {
            $adminAktif = (int) fetch_value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'aktif'", [], 0);
            if ($adminAktif <= 1) {
                flash('danger', 'Ditolak: harus ada minimal satu Administrator aktif.');
                redirect('admin/users.php');
            }
        }

        $jmlKepesertaan = (int) fetch_value('SELECT COUNT(*) FROM outing_participants WHERE user_id = ?', [$id], 0);
        run_query('DELETE FROM users WHERE id = ?', [$id]);
        log_activity('Hapus user ' . $row['user_id'] . ' (ikut ' . $jmlKepesertaan . ' outing)', 'users', $id);
        flash('success', 'Pengguna <strong>' . e($row['nama']) . '</strong> dihapus. '
            . $jmlKepesertaan . ' data kepesertaannya ikut terhapus (riwayat outing tetap aman).');
        redirect('admin/users.php');
    }

    flash('danger', 'Perintah tidak dikenali.');
    redirect('admin/users.php');
}

/* ==========================================================================
 * MODE FORM (CREATE / EDIT)
 * ========================================================================== */
$action = get_str('action', 'list');
if ($action === 'create' || $action === 'edit') {
    $form = [
        'id' => 0, 'user_id' => '', 'nama' => '', 'jabatan' => '', 'departemen' => '',
        'no_hp' => '', 'email' => '', 'role' => 'member', 'status' => 'aktif',
    ];
    $isEdit = ($action === 'edit');
    if ($isEdit) {
        $row = fetch_one('SELECT * FROM users WHERE id = ? LIMIT 1', [get_int('id', 0)]);
        if ($row === null) {
            flash('danger', 'Pengguna tidak ditemukan.');
            redirect('admin/users.php');
        }
        $form = $row;
    } else {
        // usulan User ID berikutnya
        $prefix = 'MBR';
        $last = fetch_value("SELECT user_id FROM users WHERE user_id LIKE 'MBR%' ORDER BY id DESC LIMIT 1", [], '');
        $saran = 'MBR001';
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $saran = 'MBR' . str_pad((string) (((int) $m[1]) + 1), 3, '0', STR_PAD_LEFT);
        }
        $form['user_id'] = $saran;
    }

    $pageTitle = $isEdit ? 'Ubah Pengguna' : 'Tambah Pengguna Baru';
    $pageSubtitle = $isEdit
        ? 'Memperbarui akun <strong>' . e($form['user_id']) . '</strong>'
        : 'Akun baru langsung dapat dipakai login (tanpa verifikasi email).';
    $activeMenu = 'users';

    require_once INCLUDES_PATH . '/header.php';
    ?>
    <form method="post" action="<?php echo base_url('admin/users.php'); ?>" class="card">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="task" value="save">
      <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">

      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-person-gear text-primary"></i>
        <span class="card-title-sm me-auto"><?php echo $isEdit ? 'Form Ubah Pengguna' : 'Form Pengguna Baru'; ?></span>
        <a href="<?php echo base_url('admin/users.php'); ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
      </div>

      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label" for="user_id">User ID (untuk login) <span class="required-mark">*</span></label>
            <input type="text" class="form-control text-uppercase" id="user_id" name="user_id"
                   value="<?php echo e($form['user_id']); ?>" maxlength="30" required
                   pattern="[A-Za-z0-9._\-]{3,30}" placeholder="contoh: MBR001">
            <div class="form-text">Unik, 3-30 karakter: huruf, angka, titik, strip, underscore.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="nama">Nama Lengkap <span class="required-mark">*</span></label>
            <input type="text" class="form-control" id="nama" name="nama" value="<?php echo e($form['nama']); ?>" maxlength="100" required>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="jabatan">Jabatan</label>
            <input type="text" class="form-control" id="jabatan" name="jabatan" value="<?php echo e($form['jabatan']); ?>" maxlength="100">
          </div>

          <div class="col-md-4">
            <label class="form-label" for="departemen">Departemen / Divisi</label>
            <input type="text" class="form-control" id="departemen" name="departemen" value="<?php echo e($form['departemen']); ?>" maxlength="100">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="no_hp">No. HP / WhatsApp</label>
            <input type="text" class="form-control" id="no_hp" name="no_hp" value="<?php echo e($form['no_hp']); ?>" maxlength="20" placeholder="08xxxxxxxxxx">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="email">Email <span class="fs-8 text-muted">(opsional)</span></label>
            <input type="email" class="form-control" id="email" name="email" value="<?php echo e($form['email']); ?>" maxlength="120">
            <div class="form-text">Hanya data kontak. Sistem tidak mengirim email verifikasi.</div>
          </div>

          <div class="col-md-3">
            <label class="form-label" for="role">Role <span class="required-mark">*</span></label>
            <select class="form-select" id="role" name="role">
              <?php foreach ($ENUM_ROLE as $r): ?>
                <option value="<?php echo $r; ?>" <?php echo ($form['role'] === $r) ? 'selected' : ''; ?>><?php echo ucfirst($r); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Admin = full CRUD, Member = lihat saja</div>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="status">Status Akun <span class="required-mark">*</span></label>
            <select class="form-select" id="status" name="status">
              <?php foreach ($ENUM_STATUS as $s): ?>
                <option value="<?php echo $s; ?>" <?php echo ($form['status'] === $s) ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Nonaktif tidak dapat login</div>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="password"><?php echo $isEdit ? 'Password Baru' : 'Password'; ?> <?php echo $isEdit ? '' : '<span class="required-mark">*</span>'; ?></label>
            <input type="password" class="form-control" id="password" name="password" maxlength="72" autocomplete="new-password"
                   <?php echo $isEdit ? '' : 'required'; ?>>
            <div class="form-text"><?php echo $isEdit ? 'Kosongkan bila tidak diganti' : 'Minimal 6 karakter'; ?></div>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="password2">Konfirmasi Password <?php echo $isEdit ? '' : '<span class="required-mark">*</span>'; ?></label>
            <input type="password" class="form-control" id="password2" name="password2" maxlength="72" autocomplete="new-password"
                   <?php echo $isEdit ? '' : 'required'; ?>>
          </div>
        </div>
      </div>

      <div class="card-footer bg-white d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo $isEdit ? 'Simpan Perubahan' : 'Simpan Pengguna'; ?></button>
        <a href="<?php echo base_url('admin/users.php'); ?>" class="btn btn-light">Batal</a>
        <span class="ms-auto align-self-center fs-8 text-muted">
          <i class="bi bi-shield-lock me-1"></i>Password disimpan sebagai hash bcrypt (tidak dapat dibaca kembali).
        </span>
      </div>
    </form>
    <?php
    require_once INCLUDES_PATH . '/footer.php';
    exit;
}

/* ==========================================================================
 * MODE DAFTAR
 * ========================================================================== */
$filterRole = in_enum(get_str('role'), array_merge($ENUM_ROLE, ['Semua']), 'Semua');
$filterStatus = in_enum(get_str('status'), array_merge($ENUM_STATUS, ['Semua']), 'Semua');
$cari = get_str('q');

$where = ['1 = 1'];
$params = [];
if ($filterRole !== 'Semua') {
    $where[] = 'u.role = ?';
    $params[] = $filterRole;
}
if ($filterStatus !== 'Semua') {
    $where[] = 'u.status = ?';
    $params[] = $filterStatus;
}
if ($cari !== '') {
    $where[] = '(u.nama LIKE ? OR u.user_id LIKE ? OR u.departemen LIKE ? OR u.jabatan LIKE ?)';
    $like = '%' . $cari . '%';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$users = fetch_all(
    "SELECT u.*,
            (SELECT COUNT(*) FROM outing_participants op WHERE op.user_id = u.id) AS jml_outing,
            (SELECT COUNT(*) FROM outing_participants op WHERE op.user_id = u.id AND op.attendance_status = 'Ikut') AS jml_ikut
     FROM users u
     WHERE $whereSql
     ORDER BY FIELD(u.role,'admin','member'), u.nama ASC
     LIMIT 300",
    $params
);

$hitung = [
    'total' => (int) fetch_value('SELECT COUNT(*) FROM users', [], 0),
    'admin' => (int) fetch_value("SELECT COUNT(*) FROM users WHERE role = 'admin'", [], 0),
    'member' => (int) fetch_value("SELECT COUNT(*) FROM users WHERE role = 'member'", [], 0),
    'nonaktif' => (int) fetch_value("SELECT COUNT(*) FROM users WHERE status = 'nonaktif'", [], 0),
];

$pageTitle = 'Data Pengguna';
$pageSubtitle = 'Kelola akun Administrator &amp; Member. Login memakai <strong>User ID + Password</strong>.';
$activeMenu = 'users';
$pageActions =
    '<a href="' . base_url('admin/import.php?type=user') . '" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Import Excel</a>'
    . '<a href="' . base_url('admin/users.php?action=create') . '" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Tambah Pengguna</a>';

require_once INCLUDES_PATH . '/header.php';
?>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="card"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-primary"><i class="bi bi-people"></i></span>
      <div><div class="stat-label">Total Pengguna</div><div class="stat-value"><?php echo $hitung['total']; ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-danger"><i class="bi bi-shield-lock"></i></span>
      <div><div class="stat-label">Administrator</div><div class="stat-value"><?php echo $hitung['admin']; ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-success"><i class="bi bi-person-badge"></i></span>
      <div><div class="stat-label">Member</div><div class="stat-value"><?php echo $hitung['member']; ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card"><div class="card-body stat-card py-3">
      <span class="stat-icon bg-soft-warning"><i class="bi bi-person-slash"></i></span>
      <div><div class="stat-label">Nonaktif</div><div class="stat-value"><?php echo $hitung['nonaktif']; ?></div></div>
    </div></div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex flex-wrap align-items-center gap-2">
    <span class="card-title-sm me-auto"><i class="bi bi-people me-1 text-primary"></i>Daftar Pengguna</span>
    <form method="get" action="<?php echo base_url('admin/users.php'); ?>" class="d-flex flex-wrap gap-2 align-items-center">
      <input type="search" class="form-control form-control-sm" style="max-width:180px" name="q"
             value="<?php echo e($cari); ?>" placeholder="Cari nama / user ID">
      <select class="form-select form-select-sm" style="max-width:130px" name="role">
        <?php foreach (array_merge(['Semua'], $ENUM_ROLE) as $r): ?>
          <option value="<?php echo $r; ?>" <?php echo ($filterRole === $r) ? 'selected' : ''; ?>><?php echo ($r === 'Semua') ? 'Semua Role' : ucfirst($r); ?></option>
        <?php endforeach; ?>
      </select>
      <select class="form-select form-select-sm" style="max-width:140px" name="status">
        <?php foreach (array_merge(['Semua'], $ENUM_STATUS) as $s): ?>
          <option value="<?php echo $s; ?>" <?php echo ($filterStatus === $s) ? 'selected' : ''; ?>><?php echo ($s === 'Semua') ? 'Semua Status' : ucfirst($s); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i></button>
      <a href="<?php echo base_url('admin/users.php'); ?>" class="btn btn-sm btn-light">Reset</a>
    </form>
  </div>

  <div class="card-body p-0">
    <div class="table-wrap">
      <table class="table align-middle">
        <thead>
          <tr>
            <th style="width:46px" class="text-center">No</th>
            <th>Pengguna</th>
            <th style="width:150px">Jabatan / Dept.</th>
            <th style="width:130px">Kontak</th>
            <th class="text-center" style="width:100px">Role</th>
            <th class="text-center" style="width:110px">Status</th>
            <th class="text-center" style="width:100px">Outing</th>
            <th style="width:130px">Login Terakhir</th>
            <th class="text-center" style="width:170px">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($users) === 0): ?>
            <tr><td colspan="9" class="table-empty"><i class="bi bi-search"></i>Tidak ada pengguna yang cocok dengan filter.</td></tr>
          <?php else: $no = 0; foreach ($users as $u): $no++; $uid = (int) $u['id']; $isSelf = ($uid === current_uid()); ?>
          <tr>
            <td class="text-muted text-center"><?php echo $no; ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <span class="avatar-circle"><?php echo e(strtoupper(substr($u['nama'], 0, 1))); ?></span>
                <div>
                  <div class="text-strong"><?php echo e($u['nama']); ?> <?php if ($isSelf): ?><span class="badge bg-info text-dark">Anda</span><?php endif; ?></div>
                  <div class="fs-8 text-muted"><i class="bi bi-person-badge"></i> <?php echo e($u['user_id']); ?></div>
                </div>
              </div>
            </td>
            <td class="fs-8">
              <?php echo e($u['jabatan'] ?: '-'); ?>
              <?php if (!empty($u['departemen'])): ?><div class="text-muted"><?php echo e($u['departemen']); ?></div><?php endif; ?>
            </td>
            <td class="fs-8">
              <?php if (!empty($u['no_hp'])): ?><div><i class="bi bi-whatsapp text-success"></i> <?php echo e($u['no_hp']); ?></div><?php endif; ?>
              <?php if (!empty($u['email'])): ?><div class="text-muted"><i class="bi bi-envelope"></i> <?php echo e(str_limit($u['email'], 24)); ?></div><?php endif; ?>
              <?php if (empty($u['no_hp']) && empty($u['email'])): ?><span class="text-muted">-</span><?php endif; ?>
            </td>
            <td class="text-center"><?php echo badge_role($u['role']); ?></td>
            <td class="text-center">
              <span id="badgeStatus<?php echo $uid; ?>"><?php echo badge_user_status($u['status']); ?></span>
              <?php if (!$isSelf): ?>
              <div class="mt-1">
                <button type="button" class="btn btn-sm btn-link p-0 fs-8"
                        data-ajax-action="toggle_status"
                        data-url="<?php echo base_url('api/users.php'); ?>"
                        data-params="<?php echo e(json_encode(['id' => $uid])); ?>"
                        data-confirm="<?php echo ($u['status'] === 'aktif') ? 'Nonaktifkan' : 'Aktifkan kembali'; ?> akun <strong><?php echo e($u['nama']); ?></strong>?<?php echo ($u['status'] === 'aktif') ? '<br>Akun nonaktif tidak dapat login.' : ''; ?>"
                        data-confirm-title="Ubah Status Akun"
                        data-confirm-text="Ya, <?php echo ($u['status'] === 'aktif') ? 'Nonaktifkan' : 'Aktifkan'; ?>"
                        data-confirm-class="<?php echo ($u['status'] === 'aktif') ? 'btn-warning' : 'btn-success'; ?>">
                  <?php echo ($u['status'] === 'aktif') ? 'nonaktifkan' : 'aktifkan'; ?>
                </button>
              </div>
              <?php endif; ?>
            </td>
            <td class="text-center fs-8">
              <span class="badge bg-light text-dark border"><?php echo (int) $u['jml_ikut']; ?> ikut</span>
              <div class="text-muted">dari <?php echo (int) $u['jml_outing']; ?> outing</div>
            </td>
            <td class="fs-8 text-muted"><?php echo $u['last_login'] ? date('d/m/Y H:i', strtotime($u['last_login'])) : 'belum pernah'; ?></td>
            <td class="text-center nowrap">
              <a href="<?php echo base_url('admin/users.php?action=edit&id=' . $uid); ?>" class="btn btn-sm btn-outline-primary btn-icon" title="Edit" data-bs-toggle="tooltip"><i class="bi bi-pencil"></i></a>
              <button type="button" class="btn btn-sm btn-outline-warning btn-icon" title="Reset password"
                      data-reset-pw="<?php echo $uid; ?>" data-nama="<?php echo e($u['nama']); ?>" data-userid="<?php echo e($u['user_id']); ?>">
                <i class="bi bi-key"></i>
              </button>
              <?php if (!$isSelf): ?>
              <form method="post" action="<?php echo base_url('admin/users.php'); ?>" class="d-inline"
                    data-confirm="Hapus pengguna <strong><?php echo e($u['nama']); ?></strong> (<?php echo e($u['user_id']); ?>)?<br><span class='text-danger'>Seluruh data kepesertaannya (<?php echo (int) $u['jml_outing']; ?> outing) akan ikut terhapus.</span>"
                    data-confirm-title="Hapus Pengguna" data-confirm-text="Ya, Hapus">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="task" value="delete">
                <input type="hidden" name="id" value="<?php echo $uid; ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="Hapus" data-bs-toggle="tooltip"><i class="bi bi-trash3"></i></button>
              </form>
              <?php else: ?>
              <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" disabled title="Tidak dapat menghapus akun sendiri"><i class="bi bi-lock"></i></button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card-footer bg-white small text-muted d-flex flex-wrap gap-2 align-items-center">
    <span class="me-auto"><i class="bi bi-info-circle me-1"></i>Menampilkan <?php echo count($users); ?> pengguna.</span>
    <a href="<?php echo base_url('admin/template_excel.php?type=user'); ?>" class="btn btn-sm btn-outline-success">
      <i class="bi bi-download me-1"></i>Download Template Excel Pengguna
    </a>
  </div>
</div>

<!-- ===================== MODAL HASIL RESET PASSWORD ===================== -->
<div class="modal fade" id="modalHasilPassword" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-key-fill me-2 text-warning"></i>Password Baru</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-2">Password baru untuk <strong id="pwNama"></strong> (<span id="pwUserId"></span>):</p>
        <div class="input-group">
          <input type="text" class="form-control font-monospace text-center" id="pwValue" readonly>
          <button class="btn btn-outline-primary" type="button" id="pwCopy"><i class="bi bi-clipboard"></i></button>
        </div>
        <div class="alert alert-warning small mt-3 mb-0 py-2">
          <i class="bi bi-exclamation-triangle me-1"></i>
          Password hanya ditampilkan <strong>sekali</strong>. Salin dan berikan kepada pengguna melalui jalur aman.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary btn-sm w-100" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<?php
$extraScripts = <<<'HTML'
<script>
(function () {
  var modalEl = document.getElementById('modalHasilPassword');
  var modal = modalEl ? new bootstrap.Modal(modalEl) : null;

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-reset-pw]');
    if (!btn) { return; }

    var id = btn.getAttribute('data-reset-pw');
    var nama = btn.getAttribute('data-nama');
    var userId = btn.getAttribute('data-userid');

    window.omsConfirm({
      title: 'Reset Password',
      message: 'Reset password akun <strong>' + nama + '</strong> (' + userId + ')?<br>'
             + 'Sistem akan membuat password acak baru. Password lama tidak dapat dipakai lagi.',
      confirmText: 'Ya, Reset Password',
      confirmClass: 'btn-warning',
      icon: 'bi-key-fill',
      danger: false
    }).then(function (ok) {
      if (!ok) { return; }
      btn.disabled = true;
      omsPost('api/users.php', {
        action: 'reset_password',
        id: id
      }).then(function (res) {
        btn.disabled = false;
        if (res && res.ok) {
          document.getElementById('pwNama').textContent = nama;
          document.getElementById('pwUserId').textContent = userId;
          document.getElementById('pwValue').value = res.password || '';
          if (modal) { modal.show(); }
          window.omsToast(res.message, 'success');
        } else {
          window.omsToast((res && res.message) ? res.message : 'Gagal reset password.', 'danger');
        }
      });
    });
  });

  var copy = document.getElementById('pwCopy');
  if (copy) {
    copy.addEventListener('click', function () {
      var input = document.getElementById('pwValue');
      input.select();
      input.setSelectionRange(0, 99999);
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(input.value);
        } else {
          document.execCommand('copy');
        }
        window.omsToast('Password disalin ke clipboard.', 'info');
      } catch (err) {
        window.omsToast('Salin manual: ' + input.value, 'warning');
      }
    });
  }
})();
</script>
HTML;

require_once INCLUDES_PATH . '/footer.php';
