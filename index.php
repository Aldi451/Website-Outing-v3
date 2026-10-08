<?php
/**
 * ==========================================================================
 *  OUTING MANAGEMENT SYSTEM — index.php (GERBANG MASUK / LOGIN)
 * --------------------------------------------------------------------------
 *  Login memakai User ID + Password (TANPA registrasi member, TANPA email
 *  verifikasi, TANPA OTP, TANPA lupa password via email — sesuai spesifikasi).
 *  Akun member dibuat oleh Administrator.
 * ==========================================================================
 */

require_once __DIR__ . '/includes/init.php';

/* Sudah login? langsung arahkan sesuai role. */
if (is_logged_in()) {
    redirect(is_admin() ? 'admin/dashboard.php' : 'member/dashboard.php');
}

$errorMessage = '';
$errorField = '';
$oldUserId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $oldUserId = isset($_POST['user_id']) ? trim((string) $_POST['user_id']) : '';
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

    $result = attempt_login($oldUserId, $password);

    if ($result['ok']) {
        $user = $result['user'];
        do_login($user);

        try {
            run_query('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['uid']]);
        } catch (Exception $ex) {
            // tidak kritikal
        }
        log_activity('Login berhasil', 'users', $user['uid']);

        flash('success', 'Selamat datang, <strong>' . e($user['nama']) . '</strong>. Anda login sebagai '
            . ($user['role'] === 'admin' ? 'Administrator' : 'Member') . '.');

        redirect($user['role'] === 'admin' ? 'admin/dashboard.php' : 'member/dashboard.php');
    }

    log_activity('Login gagal untuk User ID "' . substr($oldUserId, 0, 40) . '"', 'users', 0);
    $errorMessage = $result['message'];
    $errorField = 'user_id';
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<?php echo csrf_meta(); ?>
<title>Login &middot; <?php echo APP_NAME; ?></title>
<link rel="stylesheet" href="<?php echo base_url('assets/vendor/bootstrap.min.css'); ?>">
<link rel="stylesheet" href="<?php echo base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css'); ?>">
<link rel="stylesheet" href="<?php echo base_url('assets/css/style.css'); ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#127957;&#65039;</text></svg>">
</head>
<body class="login-body">

<div class="login-card">
  <div class="login-logo"><i class="bi bi-compass"></i></div>
  <h1 class="login-title"><?php echo APP_NAME; ?></h1>
  <p class="login-sub">Kelola peserta, rundown, dan anggaran outing perusahaan Anda.</p>

  <?php echo render_flash(); ?>

  <?php if ($errorMessage !== ''): ?>
    <div class="alert alert-danger d-flex align-items-start" role="alert">
      <i class="bi bi-exclamation-octagon-fill me-2 fs-5"></i>
      <div><?php echo e($errorMessage); ?></div>
    </div>
  <?php endif; ?>

  <form method="post" action="<?php echo base_url('index.php'); ?>" autocomplete="off" novalidate>
    <?php echo csrf_field(); ?>

    <div class="mb-3">
      <label class="form-label" for="user_id">User ID <span class="required-mark">*</span></label>
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
        <input type="text" class="form-control <?php echo $errorField === 'user_id' ? 'is-invalid' : ''; ?>"
               id="user_id" name="user_id" value="<?php echo e($oldUserId); ?>"
               placeholder="contoh: ADMIN001" maxlength="50" required autofocus
               pattern="[A-Za-z0-9._\-]{3,30}">
      </div>
      <div class="form-text">User ID diberikan oleh Administrator (bukan email).</div>
    </div>

    <div class="mb-3">
      <label class="form-label" for="password">Password <span class="required-mark">*</span></label>
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
        <input type="password" class="form-control <?php echo $errorField === 'password' ? 'is-invalid' : ''; ?>"
               id="password" name="password" placeholder="Masukkan password" maxlength="72" required>
        <button class="btn btn-outline-secondary" type="button" id="togglePassword" aria-label="Tampilkan password">
          <i class="bi bi-eye"></i>
        </button>
      </div>
    </div>

    <button type="submit" class="btn btn-primary w-100 py-2">
      <i class="bi bi-box-arrow-in-right me-1"></i> Masuk
    </button>
  </form>

  <div class="divider-soft"></div>

  <div class="login-hint">
    <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>Akun bawaan setelah import <code>database.sql</code>:</div>
    <div>Administrator &rarr; User ID <code>ADMIN001</code> &middot; Password <code>admin123</code></div>
    <div>Member &rarr; User ID <code>MBR001</code> &middot; Password <code>member123</code></div>
    <div class="text-danger mt-1"><i class="bi bi-exclamation-triangle me-1"></i>Segera ganti password setelah instalasi.</div>
  </div>

  <p class="text-center text-muted fs-8 mt-3 mb-0">
    &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?>
    &middot; PHP Native + MySQL
  </p>
</div>

<script src="<?php echo base_url('assets/vendor/bootstrap.bundle.min.js'); ?>"></script>
<script>
  window.OMS = { baseUrl: <?php echo json_encode(base_url('')); ?>, csrfToken: <?php echo json_encode(csrf_token()); ?> };
</script>
<script src="<?php echo base_url('assets/js/app.js'); ?>"></script>
<script>
(function () {
  var btn = document.getElementById('togglePassword');
  var pass = document.getElementById('password');
  if (btn && pass) {
    btn.addEventListener('click', function () {
      var isText = pass.type === 'text';
      pass.type = isText ? 'password' : 'text';
      btn.innerHTML = '<i class="bi ' + (isText ? 'bi-eye' : 'bi-eye-slash') + '"></i>';
    });
  }
})();
</script>
</body>
</html>
