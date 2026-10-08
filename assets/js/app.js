/* ==========================================================================
   OUTING MANAGEMENT SYSTEM — assets/js/app.js
   Vanilla JS + AJAX (tanpa jQuery). Semua request tulis mengirim token CSRF.
   Fitur:
     1. Dialog konfirmasi global (WAJIB sebelum hapus/ubah data penting)
     2. Interceptor <form data-confirm="...">  dan  <a data-confirm-post>
     3. Aksi AJAX generik  [data-ajax-action]
     4. Ubah status cepat  <select data-quick-url>
     5. Hitung otomatis Qty x Harga  [data-calc]
     6. Filter/pencarian tabel  [data-table-filter]
     7. Pilih semua checkbox  #checkAll
     8. Notifikasi toast + auto-dismiss alert
     9. Preview file bukti sebelum upload
    10. Pindah urutan rundown (naik/turun) via AJAX
   ========================================================================== */
(function () {
  'use strict';

  var OMS = window.OMS || { baseUrl: '', csrfToken: '' };

  /* ---------------------------------------------------------------- util */
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function formatRupiah(angka) {
    var n = Math.round(parseFloat(angka) || 0);
    return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  function escapeHtml(str) {
    return String(str === null || str === undefined ? '' : str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  /* --------------------------------------------------------------- toast */
  function toast(message, type) {
    var wrap = $('#toastContainer');
    if (!wrap) { return; }
    type = type || 'success';
    var icons = {
      success: 'bi-check-circle-fill',
      danger: 'bi-exclamation-octagon-fill',
      warning: 'bi-exclamation-triangle-fill',
      info: 'bi-info-circle-fill'
    };
    var el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + type + ' border-0';
    el.setAttribute('role', 'alert');
    el.innerHTML =
      '<div class="d-flex">' +
      '<div class="toast-body"><i class="bi ' + (icons[type] || icons.info) + ' me-2"></i>' +
      escapeHtml(message) + '</div>' +
      '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
      '</div>';
    wrap.appendChild(el);
    var t = new bootstrap.Toast(el, { delay: 3500 });
    t.show();
    el.addEventListener('hidden.bs.toast', function () { el.remove(); });
  }
  window.omsToast = toast;

  /* ------------------------------------------------------ modal konfirmasi */
  function confirmDialog(options) {
    options = options || {};
    return new Promise(function (resolve) {
      var el = $('#confirmModal');
      if (!el || typeof bootstrap === 'undefined') {
        resolve(window.confirm(options.message || 'Apakah Anda yakin?'));
        return;
      }
      var titleEl = $('#confirmModalTitle');
      var msgEl = $('#confirmModalMessage');
      var okEl = $('#confirmModalOk');
      var iconEl = $('.confirm-icon i', el);

      titleEl.textContent = options.title || 'Konfirmasi';
      msgEl.innerHTML = options.message || 'Apakah Anda yakin?';
      okEl.innerHTML = '<i class="bi ' + (options.icon || 'bi-check2') + ' me-1"></i>' +
        escapeHtml(options.confirmText || 'Ya, Lanjutkan');
      okEl.className = 'btn ' + (options.confirmClass || 'btn-danger');
      if (iconEl) {
        iconEl.className = 'bi ' + (options.danger === false ? 'bi-question-circle-fill text-primary' : 'bi-exclamation-triangle-fill text-danger');
      }

      var modal = bootstrap.Modal.getOrCreateInstance(el);
      var settled = false;

      function finish(val) {
        if (settled) { return; }
        settled = true;
        okEl.removeEventListener('click', onOk);
        el.removeEventListener('hidden.bs.modal', onHide);
        resolve(val);
      }
      function onOk() { modal.hide(); finish(true); }
      function onHide() { finish(false); }

      okEl.addEventListener('click', onOk);
      el.addEventListener('hidden.bs.modal', onHide);
      modal.show();
    });
  }
  window.omsConfirm = confirmDialog;

  /* ------------------------------------------------------------ AJAX POST */
  function post(url, data) {
    data = data || {};
    data.csrf_token = OMS.csrfToken;

    var body = new URLSearchParams();
    Object.keys(data).forEach(function (key) {
      var val = data[key];
      if (Array.isArray(val)) {
        val.forEach(function (v) { body.append(key + '[]', v === null || v === undefined ? '' : v); });
      } else {
        body.append(key, val === null || val === undefined ? '' : val);
      }
    });

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': OMS.csrfToken
      },
      body: body.toString()
    }).then(function (res) {
      return res.json().catch(function () {
        return { ok: false, message: 'Respon server tidak dikenali (HTTP ' + res.status + ').' };
      });
    }).catch(function () {
      return { ok: false, message: 'Gagal menghubungi server. Periksa koneksi Anda.' };
    });
  }
  window.omsPost = post;

  function lockForm(form) {
    $$('button[type=submit], input[type=submit]', form).forEach(function (btn) {
      if (btn.dataset.locked === '1') { return; }
      btn.dataset.locked = '1';
      btn.dataset.originalHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Memproses...';
    });
  }

  function unlockForm(form) {
    $$('button[data-locked="1"]', form).forEach(function (btn) {
      btn.disabled = false;
      btn.innerHTML = btn.dataset.originalHtml || 'Kirim';
      delete btn.dataset.locked;
    });
  }

  function submitNative(form) {
    lockForm(form);
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  }

  /* ------------------------------------------ 1) form dengan data-confirm */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) { return; }
    if (form.dataset.omsConfirmed === '1') { return; } // lolos konfirmasi, biarkan jalan

    var message = form.getAttribute('data-confirm');
    if (message !== null) {
      e.preventDefault();
      confirmDialog({
        title: form.getAttribute('data-confirm-title') || 'Konfirmasi Tindakan',
        message: message,
        confirmText: form.getAttribute('data-confirm-text') || 'Ya, Lanjutkan',
        confirmClass: form.getAttribute('data-confirm-class') || 'btn-danger'
      }).then(function (ok) {
        if (!ok) { return; }
        form.dataset.omsConfirmed = '1';
        submitNative(form);
        window.setTimeout(function () { delete form.dataset.omsConfirmed; unlockForm(form); }, 4000);
      });
      return;
    }
    // form biasa: cegah double-submit
    if (form.getAttribute('data-no-lock') === null) { lockForm(form); }
  });

  /* --------------------------------------- 2) link POST dengan konfirmasi */
  document.addEventListener('click', function (e) {
    var link = e.target.closest('a[data-confirm-post]');
    if (link) {
      e.preventDefault();
      confirmDialog({
        title: link.getAttribute('data-confirm-title') || 'Konfirmasi Tindakan',
        message: link.getAttribute('data-confirm-post') || 'Apakah Anda yakin?',
        confirmText: link.getAttribute('data-confirm-text') || 'Ya, Lanjutkan'
      }).then(function (ok) {
        if (!ok) { return; }
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = link.getAttribute('href');
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = OMS.csrfToken;
        form.appendChild(input);
        var extra = link.getAttribute('data-post-extra');
        if (extra) {
          try {
            var obj = JSON.parse(extra);
            Object.keys(obj).forEach(function (k) {
              var i2 = document.createElement('input');
              i2.type = 'hidden';
              i2.name = k;
              i2.value = obj[k];
              form.appendChild(i2);
            });
          } catch (err) { /* abaikan */ }
        }
        document.body.appendChild(form);
        form.submit();
      });
      return;
    }
  });

  /* -------------------------------------------- 3) aksi AJAX [data-ajax] */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-ajax-action]');
    if (!btn) { return; }
    e.preventDefault();

    var url = btn.getAttribute('data-url');
    var needConfirm = btn.getAttribute('data-confirm');
    var action = function () {
      var payload = { action: btn.getAttribute('data-ajax-action') };
      var extra = btn.getAttribute('data-params');
      if (extra) {
        try {
          var obj = JSON.parse(extra);
          Object.keys(obj).forEach(function (k) { payload[k] = obj[k]; });
        } catch (err) { /* abaikan */ }
      }
      btn.disabled = true;
      post(url, payload).then(function (res) {
        btn.disabled = false;
        if (res && res.ok) {
          toast(res.message || 'Berhasil.', 'success');
          if (btn.getAttribute('data-reload') !== '0') {
            window.setTimeout(function () { window.location.reload(); }, 650);
          }
          if (res.reload_url) {
            window.setTimeout(function () { window.location.href = res.reload_url; }, 650);
          }
        } else {
          toast((res && res.message) ? res.message : 'Gagal memproses permintaan.', 'danger');
        }
      });
    };

    if (needConfirm !== null) {
      confirmDialog({
        title: btn.getAttribute('data-confirm-title') || 'Konfirmasi',
        message: needConfirm,
        confirmText: btn.getAttribute('data-confirm-text') || 'Ya, Lanjutkan'
      }).then(function (ok) { if (ok) { action(); } });
    } else {
      action();
    }
  });

  /* ------------------------------------ 4) ubah status cepat (select AJAX) */
  document.addEventListener('focusin', function (e) {
    var sel = e.target.closest('select[data-quick-url]');
    if (sel) { sel.dataset.prevValue = sel.value; }
  });

  document.addEventListener('change', function (e) {
    var sel = e.target.closest('select[data-quick-url]');
    if (!sel) { return; }

    var payload = {
      action: sel.getAttribute('data-action') || 'update',
      id: sel.getAttribute('data-id') || ''
    };
    var field = sel.getAttribute('data-field') || 'value';
    payload[field] = sel.value;

    var extra = sel.getAttribute('data-params');
    if (extra) {
      try {
        var obj = JSON.parse(extra);
        Object.keys(obj).forEach(function (k) { payload[k] = obj[k]; });
      } catch (err) { /* abaikan */ }
    }

    sel.disabled = true;
    post(sel.getAttribute('data-quick-url'), payload).then(function (res) {
      sel.disabled = false;
      if (res && res.ok) {
        toast(res.message || 'Perubahan tersimpan.', 'success');
        if (res.html && sel.getAttribute('data-badge-target')) {
          var target = $(sel.getAttribute('data-badge-target'));
          if (target) { target.innerHTML = res.html; }
        }
        if (res.reload) { window.setTimeout(function () { window.location.reload(); }, 700); }
      } else {
        sel.value = sel.dataset.prevValue || sel.value;
        toast((res && res.message) ? res.message : 'Gagal menyimpan perubahan.', 'danger');
      }
    });
  });

  /* ------------------------------------------- 5) hitung otomatis Qty x H */
  function recalc(form) {
    var qtyEl = form.querySelector('[data-calc="qty"]');
    var priceEl = form.querySelector('[data-calc="harga"]');
    var outEls = form.querySelectorAll('[data-calc="total"]');
    if (!qtyEl || !priceEl || outEls.length === 0) { return; }

    var qty = parseFloat(String(qtyEl.value).replace(/[^0-9.\-]/g, '')) || 0;
    var price = parseFloat(String(priceEl.value).replace(/[^0-9.\-]/g, '')) || 0;
    var total = Math.round(qty * price);

    Array.prototype.forEach.call(outEls, function (el) {
      if (el.tagName === 'INPUT') {
        el.value = formatRupiah(total);
      } else {
        el.textContent = 'Rp ' + formatRupiah(total);
      }
    });
  }

  document.addEventListener('input', function (e) {
    var el = e.target.closest('[data-calc]');
    if (!el || !el.form) { return; }
    recalc(el.form);
  });

  /* ------------------------------------------ 6) filter / pencarian tabel */
  document.addEventListener('input', function (e) {
    var input = e.target.closest('[data-table-filter]');
    if (!input) { return; }
    var target = document.querySelector(input.getAttribute('data-table-filter'));
    if (!target) { return; }

    var keyword = input.value.toLowerCase().trim();
    var rows = $$('tbody tr[data-searchable]', target);
    var visible = 0;

    rows.forEach(function (row) {
      var hay = (row.getAttribute('data-search') || row.textContent || '').toLowerCase();
      var show = keyword === '' || hay.indexOf(keyword) !== -1;
      row.style.display = show ? '' : 'none';
      if (show) { visible++; }
    });

    var counter = document.querySelector(input.getAttribute('data-filter-count') || '#filterCount');
    if (counter) { counter.textContent = visible + ' data'; }

    var emptyRow = target.querySelector('tbody tr[data-empty-row]');
    if (emptyRow) { emptyRow.style.display = visible === 0 ? '' : 'none'; }
  });

  /* ------------------------------------------------- 7) pilih semua (cek) */
  document.addEventListener('change', function (e) {
    if (!e.target.matches('#checkAll')) { return; }
    var scope = e.target.getAttribute('data-scope') || '';
    var boxes = $$('input[type=checkbox][data-check-item]' + (scope ? '[data-group="' + scope + '"]' : ''));
    boxes.forEach(function (b) { b.checked = e.target.checked; });
    updateSelectedCount();
  });

  document.addEventListener('change', function (e) {
    if (e.target.matches('input[type=checkbox][data-check-item]')) { updateSelectedCount(); }
  });

  function updateSelectedCount() {
    var count = $$('input[type=checkbox][data-check-item]:checked').length;
    $$('[data-selected-count]').forEach(function (el) { el.textContent = count; });
    $$('[data-selected-actions]').forEach(function (el) {
      el.style.display = count > 0 ? '' : 'none';
      el.classList.toggle('d-none', count === 0);
    });
  }

  /* --------------------------------------------------- 9) preview file */
  document.addEventListener('change', function (e) {
    var input = e.target.closest('input[type=file][data-preview-target]');
    if (!input) { return; }
    var target = document.querySelector(input.getAttribute('data-preview-target'));
    if (!target) { return; }
    var file = input.files && input.files[0];
    if (!file) { target.innerHTML = '<span class="text-muted small">Belum ada file dipilih.</span>'; return; }

    var size = file.size < 1024 * 1024
      ? (Math.round(file.size / 1024 * 10) / 10) + ' KB'
      : (Math.round(file.size / 1024 / 1024 * 100) / 100) + ' MB';

    var html = '<div class="d-flex align-items-center gap-2">' +
      '<i class="bi ' + (file.type.indexOf('pdf') !== -1 ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-primary') + ' fs-4"></i>' +
      '<div><div class="small text-strong">' + escapeHtml(file.name) + '</div>' +
      '<div class="fs-8 text-muted">' + size + ' &middot; ' + escapeHtml(file.type || 'tipe tidak diketahui') + '</div></div></div>';
    target.innerHTML = html;
  });

  /* ---------------------------------- 10) urutan rundown (naik / turun) */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-move]');
    if (!btn) { return; }
    e.preventDefault();
    var url = btn.getAttribute('data-url');
    btn.disabled = true;
    post(url, {
      action: 'reorder',
      id: btn.getAttribute('data-id') || '',
      direction: btn.getAttribute('data-move') || 'up'
    }).then(function (res) {
      if (res && res.ok) { window.location.reload(); }
      else {
        btn.disabled = false;
        toast((res && res.message) ? res.message : 'Gagal mengubah urutan.', 'danger');
      }
    });
  });

  /* ------------------------------------------------- perilaku saat siap */
  document.addEventListener('DOMContentLoaded', function () {
    // tooltip
    if (typeof bootstrap !== 'undefined') {
      $$('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el, { trigger: 'hover' });
      });
    }

    // auto-hide alert sukses
    $$('.alert-success, .alert-info').forEach(function (el) {
      window.setTimeout(function () {
        var alert = bootstrap.Alert.getOrCreateInstance(el);
        alert.close();
      }, 6000);
    });

    // hitung awal form pembelian
    $$('form').forEach(function (f) {
      if (f.querySelector('[data-calc="qty"]')) { recalc(f); }
    });

    updateSelectedCount();

    // fokus otomatis ke field pertama form modal
    $$('.modal').forEach(function (m) {
      m.addEventListener('shown.bs.modal', function () {
        var first = m.querySelector('input:not([type=hidden]), select, textarea');
        if (first) { first.focus(); }
      });
    });
  });

  // Ekspor util ke global (dipakai skrip halaman tertentu)
  window.OMS_UTIL = {
    $: $,
    $$: $$,
    post: post,
    toast: toast,
    confirm: confirmDialog,
    formatRupiah: formatRupiah,
    lockForm: lockForm,
    unlockForm: unlockForm,
    recalc: recalc,
    updateSelectedCount: updateSelectedCount
  };
})();
