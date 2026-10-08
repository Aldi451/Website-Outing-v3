<?php
/**
 * ==========================================================================
 *  MEMBER — member/profile.php  (DATA DIRI + GANTI PASSWORD SENDIRI)
 * --------------------------------------------------------------------------
 *  Data profil bersifat READ-ONLY (dikelola Administrator).
 *  Satu-satunya perubahan yang boleh dilakukan member: mengganti password
 *  sendiri dengan memasukkan password lama.
 * ==========================================================================
 */

require_once __DIR__ . '/../includes/init.php';
require_member();

$uid = current_uid();

/* ------------------------------------------------------------- PROSES */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (post_str('task') === 'change_password') {
        $lama = isset($_POST['password_lama']) ? (string) $_POST['password_lama'] : '';
        $baru = isset($_POST['password_baru']) ? (string) $_POST['password_baru'] : '';
        $konfirmasi = isset($_POST['password_baru2']) ? (string) $_POST['password_baru2'] : '';
        $errors = [];

        $row = fetch_one('SELECT password FROM users WHERE id = ? LIMIT 1', [$uid]);
        if ($row === null) {
            flash('danger', 'Akun tidak ditemukan.');
            redirect('member/profile.php');
        }
        if ($lama === '' || !password_verify($lama, $row['password'])) {
            $errors[] = 'Password lama tidak sesuai.';
        }
        $valid = validate_password($baru);
        if ($valid !== true) {
            $errors[] = $valid;
        }
        if ($baru !== $konfirmasi) {
            $errors[] = 'Konfirmasi password baru tidak sama.';
        }
        if ($lama !== '' && $lama === $baru) {
            $errors[] = 'Password baru harus berbeda dari password lama.';
        }

        if (count($errors) > 0) {
            flash('danger', 'Password tidak diubah:<br>&bull; ' . implode('<br>&bull; ', array_map('e', $errors)));
            redirect('member/profile.php');
        }

        run_query('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?', [hash_password($baru), $uid]);
        log_activity('Member mengganti password sendiri', 'users', $uid);
        flash('success', 'Password Anda berhasil diganti. Gunakan password baru pada login berikutnya.');
        redirect('member/profile.php');
    }

    flash('danger', 'Perintah tidak dikenali. Data profil hanya dapat diubah oleh Administrator.');
    redirect('member/profile.php');
}

/* --------------------------------------------------------------- DATA */
$profil = fetch_one(
    'SELECT id, user_id, nama, jabatan, departemen, no_hp, email, role, status, last_login, created_at
     FROM users WHERE id = ? LIMIT 1',
    [$uid]
);
if ($profil === null) {
    do_logout();
    redirect('index.php');
}

$riwayat = fetch_all(
    "SELECT o.kode, o.nama, o.tujuan, o.status, o.tanggal_mulai,
            op.attendance_status, op.payment_status, op.nominal_bayar
     FROM outing_participants op
     JOIN outings o ON o.id = op.outing_id
     WHERE op.user_id = ?
     ORDER BY o.tanggal_mulai DESC
     LIMIT 20",
    [$uid]
);

$pageTitle = 'Profil Saya';
$pageSubtitle = 'Data diri Anda sebagaimana tercatat pada sistem (dikelola Administrator).';
$activeMenu = 'profile';

require_once INCLUDES_PATH . '/header.php';
?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-person-vcard text-primary"></i>
        <span class="card-title-sm me-auto">Data Diri</span>
        <span class="badge bg-light text-dark border"><i class="bi bi-lock me-1"></i>Hanya bisa diubah Admin</span>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <span class="avatar-circle" style="width:56px;height:56px;font-size:1.5rem"><?php echo e(strtoupper(substr($profil['nama'], 0, 1))); ?></span>
          <div>
            <h5 class="mb-0"><?php echo e($profil['nama']); ?></h5>
            <div class="text-muted fs-8">
              <?php echo e($profil['user_id']); ?> &middot; <?php echo badge_role($profil['role']); ?> <?php echo badge_user_status($profil['status']); ?>
            </div>
          </div>
        </div>

        <div class="table-wrap">
          <table class="table table-sm fs-7 mb-0">
            <tbody>
              <tr><th class="text-muted fw-normal" style="width:34%">User ID (login)</th><td><?php echo e($profil['user_id']); ?></td></tr>
              <tr><th class="text-muted fw-normal">Nama Lengkap</th><td><?php echo e($profil['nama']); ?></td></tr>
              <tr><th class="text-muted fw-normal">Jabatan</th><td><?php echo e($profil['jabatan'] ?: '-'); ?></td></tr>
              <tr><th class="text-muted fw-normal">Departemen</th><td><?php echo e($profil['departemen'] ?: '-'); ?></td></tr>
              <tr><th class="text-muted fw-normal">No. HP</th><td><?php echo e($profil['no_hp'] ?: '-'); ?></td></tr>
              <tr><th class="text-muted fw-normal">Email</th><td><?php echo e($profil['email'] ?: '-'); ?></td></tr>
              <tr><th class="text-muted fw-normal">Terdaftar Sejak</th><td><?php echo date('d/m/Y', strtotime($profil['created_at'])); ?></td></tr>
              <tr><th class="text-muted fw-normal">Login Terakhir</th><td><?php echo $profil['last_login'] ? date('d/m/Y H:i', strtotime($profil['last_login'])) : '-'; ?></td></tr>
            </tbody>
          </table>
        </div>

        <div class="alert alert-info small mt-3 mb-0 py-2">
          <i class="bi bi-info-circle me-1"></i>
          Ada data yang perlu diperbarui? Hubungi <strong>Administrator</strong> — sistem ini tidak menyediakan
          registrasi mandiri maupun pemulihan password via email.
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-clock-history text-success"></i>
        <span class="card-title-sm me-auto">Riwayat Keikutsertaan</span>
        <span class="badge bg-light text-dark border"><?php echo count($riwayat); ?> outing</span>
      </div>
      <div class="card-body p-0">
        <?php if (count($riwayat) === 0): ?>
          <div class="table-empty py-4"><i class="bi bi-inbox"></i>Belum ada riwayat keikutsertaan.</div>
        <?php else: ?>
        <div class="table-wrap">
          <table class="table table-sm align-middle">
            <thead><tr><th>Outing</th><th class="nowrap">Tanggal</th><th class="text-center">Status Outing</th><th class="text-center">Kehadiran</th><th class="text-center">Pembayaran</th></tr></thead>
            <tbody>
              <?php foreach ($riwayat as $r): ?>
              <tr>
                <td>
                  <div class="fs-7 text-strong"><?php echo e($r['nama']); ?></div>
                  <div class="fs-8 text-muted"><?php echo e($r['kode']); ?> &middot; <?php echo e($r['tujuan']); ?></div>
                </td>
                <td class="fs-8 nowrap"><?php echo date('d/m/Y', strtotime($r['tanggal_mulai'])); ?></td>
                <td class="text-center"><?php echo badge_status_outing($r['status']); ?></td>
                <td class="text-center"><?php echo badge_attendance($r['attendance_status']); ?></td>
                <td class="text-center">
                  <?php echo badge_payment($r['payment_status']); ?>
                  <?php if ($r['payment_status'] === 'Sudah'): ?>
                    <div class="fs-8 text-muted"><?php echo rupiah($r['nominal_bayar'], false); ?></div>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-shield-lock text-danger"></i>
        <span class="card-title-sm me-auto">Ganti Password</span>
      </div>
      <div class="card-body">
        <form method="post" action="<?php echo base_url('member/profile.php'); ?>" autocomplete="off"
              data-confirm="Ganti password Anda sekarang?<br>Pastikan Anda mengingat password baru ini karena pemulihan via email tidak tersedia."
              data-confirm-title="Ganti Password" data-confirm-text="Ya, Ganti Password" data-confirm-class="btn-primary">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="task" value="change_password">

          <div class="mb-3">
            <label class="form-label" for="password_lama">Password Lama <span class="required-mark">*</span></label>
            <input type="password" class="form-control" id="password_lama" name="password_lama" maxlength="72" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="password_baru">Password Baru <span class="required-mark">*</span></label>
            <input type="password" class="form-control" id="password_baru" name="password_baru" minlength="6" maxlength="72" required>
            <div class="form-text">Minimal 6 karakter. Disimpan sebagai hash bcrypt.</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="password_baru2">Ulangi Password Baru <span class="required-mark">*</span></label>
            <input type="password" class="form-control" id="password_baru2" name="password_baru2" minlength="6" maxlength="72" required>
          </div>

          <button type="submit" class="btn btn-primary w-100"><i class="bi bi-key me-1"></i>Ganti Password</button>
        </form>

        <div class="divider-soft"></div>
        <ul class="small text-muted ps-3 mb-0">
          <li class="mb-1">Sistem <strong>tidak</strong> menyediakan "Lupa Password" via email/OTP.</li>
          <li class="mb-1">Jika lupa password, minta <strong>Administrator</strong> melakukan reset (menu Pengguna &rarr; ikon kunci).</li>
          <li>Jangan bagikan password Anda kepada siapa pun.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
