<?php
/**
 * ==========================================================================
 *  OUTING MANAGEMENT SYSTEM — includes/footer.php
 * --------------------------------------------------------------------------
 *  Penutup layout + modal konfirmasi global + pemuatan script.
 *  Variabel opsional: $extraScripts (HTML <script> tambahan), $extraModals.
 * ==========================================================================
 */
defined('APP_STARTED') or exit('Direct access is not allowed.');
?>
      </div><!-- /.page-wrap -->
    </main>
  </div><!-- /.row -->
</div><!-- /.app-shell -->

<footer class="app-footer">
  <div class="container-fluid px-3 px-lg-4">
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <span class="me-auto">
        &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?>
        &middot; PHP Native + MySQL &middot; 100% kompatibel shared hosting
      </span>
      <span class="text-muted small">
        Login: <strong><?php echo e(current_user_id_login()); ?></strong>
        &middot; <?php echo date('d/m/Y H:i'); ?>
      </span>
    </div>
  </div>
</footer>

<!-- ================= MODAL KONFIRMASI GLOBAL (WAJIB SEBELUM HAPUS) ================= -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title d-flex align-items-center gap-2" id="confirmModalLabel">
          <span class="confirm-icon"><i class="bi bi-exclamation-triangle-fill"></i></span>
          <span id="confirmModalTitle">Konfirmasi</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Batal"></button>
      </div>
      <div class="modal-body pt-2">
        <p class="mb-0" id="confirmModalMessage">Apakah Anda yakin?</p>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-danger" id="confirmModalOk">Ya, Lanjutkan</button>
      </div>
    </div>
  </div>
</div>

<!-- ================= WADAH NOTIFIKASI TOAST ================= -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>

<?php if (!empty($extraModals)) {
    echo $extraModals;
} ?>

<script src="<?php echo base_url('assets/vendor/bootstrap.bundle.min.js'); ?>"></script>
<script>
  window.OMS = {
    baseUrl: <?php echo json_encode(base_url('')); ?>,
    csrfToken: <?php echo json_encode(csrf_token()); ?>,
    labels: {
      confirmTitle: 'Konfirmasi',
      confirmText: 'Ya, Lanjutkan',
      cancelText: 'Batal',
      loading: 'Memproses...'
    }
  };
</script>
<script src="<?php echo base_url('assets/js/app.js'); ?>"></script>
<?php if (!empty($extraScripts)) {
    echo $extraScripts;
} ?>
</body>
</html>
<?php
// Bersihkan variabel layout agar tidak bocor ke include berikutnya
unset($extraScripts, $extraModals, $outingCtx, $pageActions);
