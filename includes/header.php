<?php
/**
 * ==========================================================================
 *  OUTING MANAGEMENT SYSTEM — includes/header.php
 * --------------------------------------------------------------------------
 *  Layout atas bersama untuk halaman ADMIN dan MEMBER.
 *
 *  Variabel yang dibaca (opsional, set sebelum require file ini):
 *    $pageTitle    judul halaman
 *    $pageSubtitle deskripsi singkat
 *    $activeMenu   penanda menu aktif (dashboard|outings|history|users|
 *                   categories|import|activity|profile)
 *    $pageActions  HTML tombol di kanan judul halaman
 *    $outingCtx    data outing (array) -> menampilkan tab kelola outing
 *    $outingTab    tab aktif (ringkasan|peserta|rundown|pembelian|export)
 *    $bodyClass    class tambahan untuk <body>
 * ==========================================================================
 */

defined('APP_STARTED') or exit('Direct access is not allowed.');

$u            = current_user();
$isAdminView  = is_admin();
$pageTitle    = isset($pageTitle) ? $pageTitle : 'Dashboard';
$pageSubtitle = isset($pageSubtitle) ? $pageSubtitle : '';
$activeMenu   = isset($activeMenu) ? $activeMenu : '';
$pageActions  = isset($pageActions) ? $pageActions : '';
$bodyClass    = isset($bodyClass) ? $bodyClass : '';
$outingCtx    = isset($outingCtx) ? $outingCtx : null;
$outingTab    = isset($outingTab) ? $outingTab : '';

/** Helper penanda menu aktif */
if (!function_exists('menu_active')) {
    function menu_active($key)
    {
        global $activeMenu;

        return ($activeMenu === $key) ? ' active' : '';
    }
}

/** Jumlah outing aktif/planning untuk badge sidebar (khusus admin). */
$sidebarActiveCount = 0;
if ($isAdminView) {
    $sidebarActiveCount = (int) fetch_value(
        "SELECT COUNT(*) FROM outings WHERE status IN ('Planning','Active')",
        [],
        0
    );
}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Outing Management System - kelola peserta, rundown, dan anggaran outing perusahaan.">
<?php echo csrf_meta(); ?>
<title><?php echo e($pageTitle); ?> &middot; <?php echo APP_NAME; ?></title>
<link rel="stylesheet" href="<?php echo base_url('assets/vendor/bootstrap.min.css'); ?>">
<link rel="stylesheet" href="<?php echo base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css'); ?>">
<link rel="stylesheet" href="<?php echo base_url('assets/css/style.css'); ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#127957;&#65039;</text></svg>">
</head>
<body class="app-body <?php echo $isAdminView ? 'role-admin' : 'role-member'; ?> <?php echo e($bodyClass); ?>">

<!-- ============================ TOPBAR ============================ -->
<nav class="navbar app-navbar sticky-top">
  <div class="container-fluid px-3 px-lg-4">

    <?php if ($isAdminView): ?>
    <button class="btn btn-sm btn-light me-2 d-lg-none" type="button"
            data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu">
      <i class="bi bi-list fs-5"></i>
    </button>
    <?php endif; ?>

    <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo base_url($isAdminView ? 'admin/dashboard.php' : 'member/dashboard.php'); ?>">
      <span class="brand-logo"><i class="bi bi-compass"></i></span>
      <span class="d-none d-sm-inline">
        <?php echo APP_NAME; ?>
        <small class="brand-role d-block"><?php echo $isAdminView ? 'Panel Administrator' : 'Portal Member'; ?></small>
      </span>
    </a>

    <div class="d-flex align-items-center gap-2 ms-auto">
      <span class="badge rounded-pill <?php echo $isAdminView ? 'bg-danger-subtle text-danger-emphasis' : 'bg-primary-subtle text-primary-emphasis'; ?> d-none d-md-inline-flex align-items-center gap-1">
        <i class="bi <?php echo $isAdminView ? 'bi-shield-lock' : 'bi-person-badge'; ?>"></i>
        <?php echo $isAdminView ? 'ADMIN' : 'MEMBER'; ?>
      </span>

      <div class="dropdown">
        <button class="btn btn-sm btn-light d-flex align-items-center gap-2 dropdown-toggle" type="button"
                data-bs-toggle="dropdown" aria-expanded="false">
          <span class="avatar-circle"><?php echo e(strtoupper(substr(current_nama(), 0, 1))); ?></span>
          <span class="d-none d-sm-inline text-start lh-1">
            <?php echo e(current_nama()); ?>
            <small class="d-block text-muted"><?php echo e(current_user_id_login()); ?></small>
          </span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow">
          <li class="dropdown-header">Login sebagai<br><strong><?php echo e(current_nama()); ?></strong></li>
          <li><hr class="dropdown-divider"></li>
          <?php if ($isAdminView): ?>
            <li><a class="dropdown-item" href="<?php echo base_url('admin/dashboard.php'); ?>"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
            <li><a class="dropdown-item" href="<?php echo base_url('admin/users.php'); ?>"><i class="bi bi-people me-2"></i>Data Pengguna</a></li>
            <li><a class="dropdown-item" href="<?php echo base_url('admin/activity.php'); ?>"><i class="bi bi-clock-history me-2"></i>Log Aktivitas</a></li>
          <?php else: ?>
            <li><a class="dropdown-item" href="<?php echo base_url('member/dashboard.php'); ?>"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
            <li><a class="dropdown-item" href="<?php echo base_url('member/profile.php'); ?>"><i class="bi bi-person-gear me-2"></i>Profil &amp; Password</a></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li>
            <form method="post" action="<?php echo base_url('logout.php'); ?>" class="px-2">
              <?php echo csrf_field(); ?>
              <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                <i class="bi bi-box-arrow-right me-1"></i> Keluar
              </button>
            </form>
          </li>
        </ul>
      </div>
    </div>
  </div>
</nav>

<div class="app-shell container-fluid">
  <div class="row g-0">

    <?php if ($isAdminView): ?>
    <!-- ============================ SIDEBAR ADMIN ============================ -->
    <div class="col-lg-2 sidebar-col">
      <div class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="sidebarMenu" aria-labelledby="sidebarMenuLabel">
        <div class="offcanvas-header">
          <h5 class="offcanvas-title" id="sidebarMenuLabel"><i class="bi bi-compass me-2"></i>Menu Admin</h5>
          <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu" aria-label="Tutup"></button>
        </div>
        <div class="offcanvas-body d-lg-block p-0">
          <p class="sidebar-caption">MAIN MENU</p>
          <ul class="nav nav-pills flex-column gap-1">
            <li class="nav-item">
              <a class="nav-link<?php echo menu_active('dashboard'); ?>" href="<?php echo base_url('admin/dashboard.php'); ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link<?php echo menu_active('outings'); ?>" href="<?php echo base_url('admin/outings.php'); ?>">
                <i class="bi bi-calendar2-heart"></i> Data Outing
                <span class="badge bg-light text-dark ms-auto"><?php echo $sidebarActiveCount; ?></span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link<?php echo menu_active('history'); ?>" href="<?php echo base_url('admin/history.php'); ?>">
                <i class="bi bi-archive"></i> History Outing
              </a>
            </li>
          </ul>

          <p class="sidebar-caption mt-4">MASTER DATA</p>
          <ul class="nav nav-pills flex-column gap-1">
            <li class="nav-item">
              <a class="nav-link<?php echo menu_active('users'); ?>" href="<?php echo base_url('admin/users.php'); ?>">
                <i class="bi bi-people"></i> Pengguna
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link<?php echo menu_active('categories'); ?>" href="<?php echo base_url('admin/categories.php'); ?>">
                <i class="bi bi-tags"></i> Kategori Biaya
              </a>
            </li>
          </ul>

          <p class="sidebar-caption mt-4">TOOLS</p>
          <ul class="nav nav-pills flex-column gap-1">
            <li class="nav-item">
              <a class="nav-link<?php echo menu_active('import'); ?>" href="<?php echo base_url('admin/import.php'); ?>">
                <i class="bi bi-file-earmark-spreadsheet"></i> Import Excel
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link<?php echo menu_active('activity'); ?>" href="<?php echo base_url('admin/activity.php'); ?>">
                <i class="bi bi-clock-history"></i> Log Aktivitas
              </a>
            </li>
          </ul>

          <div class="sidebar-box mt-4">
            <p class="sidebar-caption mb-2">AKSI CEPAT</p>
            <a href="<?php echo base_url('admin/outings.php?action=create'); ?>" class="btn btn-primary btn-sm w-100 mb-2">
              <i class="bi bi-plus-circle me-1"></i> Buat Outing Baru
            </a>
            <a href="<?php echo base_url('admin/template_excel.php?type=pembelian'); ?>" class="btn btn-outline-secondary btn-sm w-100">
              <i class="bi bi-download me-1"></i> Template Excel
            </a>
          </div>

          <p class="sidebar-foot">v<?php echo APP_VERSION; ?> &middot; <?php echo date('d/m/Y'); ?></p>
        </div>
      </div>
    </div>
    <!-- ======================= AKHIR SIDEBAR ADMIN ======================= -->
    <?php endif; ?>

    <main class="<?php echo $isAdminView ? 'col-lg-10' : 'col-12'; ?> app-main">
      <div class="page-wrap">

        <?php if (!$isAdminView): ?>
        <!-- ==================== NAVIGASI MEMBER (VIEW ONLY) ==================== -->
        <div class="member-nav mb-3">
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <a href="<?php echo base_url('member/dashboard.php'); ?>" class="btn btn-sm <?php echo menu_active('dashboard') ? 'btn-primary' : 'btn-outline-primary'; ?>">
              <i class="bi bi-speedometer2 me-1"></i> Dashboard
            </a>
            <a href="<?php echo base_url('member/profile.php'); ?>" class="btn btn-sm <?php echo menu_active('profile') ? 'btn-primary' : 'btn-outline-primary'; ?>">
              <i class="bi bi-person-gear me-1"></i> Profil Saya
            </a>
            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle ms-auto">
              <i class="bi bi-eye me-1"></i> Mode Lihat Saja (Read Only)
            </span>
          </div>
        </div>
        <?php endif; ?>

        <!-- ==================== JUDUL HALAMAN ==================== -->
        <div class="page-head d-flex flex-wrap align-items-center gap-2 mb-3">
          <div class="me-auto">
            <h1 class="page-title"><?php echo e($pageTitle); ?></h1>
            <?php if ($pageSubtitle !== ''): ?>
              <p class="page-subtitle mb-0"><?php echo $pageSubtitle; ?></p>
            <?php endif; ?>
          </div>
          <?php echo $pageActions; ?>
        </div>

        <?php echo render_flash(); ?>

        <?php if (is_array($outingCtx) && !empty($outingCtx['id'])): ?>
        <!-- ==================== TAB KELOLA OUTING ==================== -->
        <?php $oid = (int) $outingCtx['id']; ?>
        <div class="card outing-ctx-card mb-3">
          <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
            <div class="me-auto">
              <span class="text-muted small d-block">Sedang mengelola</span>
              <strong><?php echo e($outingCtx['nama']); ?></strong>
              <span class="badge bg-light text-dark border ms-1"><?php echo e($outingCtx['kode']); ?></span>
              <?php echo badge_status_outing($outingCtx['status']); ?>
            </div>
            <div class="small text-muted">
              <i class="bi bi-geo-alt"></i> <?php echo e($outingCtx['tujuan']); ?> &nbsp;|&nbsp;
              <i class="bi bi-calendar3"></i> <?php echo rentang_tanggal($outingCtx['tanggal_mulai'], $outingCtx['tanggal_selesai']); ?>
            </div>
          </div>
          <div class="card-footer bg-white p-0">
            <ul class="nav nav-tabs outing-tabs mb-0">
              <?php
              $tabs = [
                  'ringkasan'  => ['bi-card-list', 'Ringkasan', 'admin/outings.php?action=view&outing_id=' . $oid],
                  'peserta'    => ['bi-people', 'Peserta', 'admin/participants.php?outing_id=' . $oid],
                  'rundown'    => ['bi-clock-history', 'Rundown', 'admin/rundown.php?outing_id=' . $oid],
                  'pembelian'  => ['bi-receipt', 'Pembelian', 'admin/purchases.php?outing_id=' . $oid],
                  'export'     => ['bi-file-earmark-pdf', 'Export PDF', 'admin/export_pdf.php?outing_id=' . $oid],
              ];
              foreach ($tabs as $key => $tab):
                  $isLink = ($key !== 'export');
              ?>
                <li class="nav-item">
                  <a class="nav-link<?php echo ($outingTab === $key) ? ' active' : ''; ?>"
                     href="<?php echo base_url($tab[2]); ?>"
                     <?php echo $isLink ? '' : 'target="_blank" rel="noopener"'; ?>>
                    <i class="bi <?php echo $tab[0]; ?> me-1"></i><?php echo $tab[1]; ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
        <?php endif; ?>
