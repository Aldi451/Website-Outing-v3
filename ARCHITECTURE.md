# TAHAP 1 — ANALISIS ARSITEKTUR SISTEM (OUTING MANAGEMENT SYSTEM)

> Dokumen perencanaan **sebelum coding**. Teknologi: PHP Native + MySQL/MariaDB + HTML5 + CSS3 +
> Vanilla JS/AJAX + Bootstrap 5 + library PDF & Excel **pure PHP (tanpa Composer)**.
> Target deploy: **shared hosting InfinityFree** (`public_html`, PHP 7.4/8.x, MySQL 5.7/MariaDB 10.x).

---

## 1. Analisis Arsitektur

### 1.1 Pola arsitektur
Dipakai pola **3-layer ringan** yang umum & aman untuk shared hosting (tanpa framework, tanpa build tools):

```
┌──────────────────────────────────────────────────────────────────────┐
│  PRESENTATION  : .php halaman (admin/*, member/*, index.php)         │
│                  + partials layout (includes/header.php, footer.php) │
│                  + assets/css/style.css, assets/js/app.js (AJAX)     │
├──────────────────────────────────────────────────────────────────────┤
│  BUSINESS LOGIC: includes/functions.php  (helper, validasi, upload)  │
│                  includes/auth.php       (guard session & hak akses) │
│                  includes/csrf.php       (token & verifikasi)        │
│                  api/*.php               (endpoint AJAX → JSON)      │
│                  libs/SimplePDF.php      (generator PDF pure PHP)    │
│                  libs/Xlsx.php           (baca/tulis .xlsx pure PHP) │
├──────────────────────────────────────────────────────────────────────┤
│  DATA ACCESS   : config/database.php → 1 koneksi PDO (singleton)     │
│                  100% Prepared Statements (emulate = OFF)            │
└──────────────────────────────────────────────────────────────────────┘
                                   │
                            MySQL / MariaDB
```

### 1.2 Keputusan teknis penting

| Kebutuhan | Keputusan | Alasan |
|---|---|---|
| Koneksi DB | **PDO** + `ATTR_EMULATE_PREPARES = false` | Prepared statement asli → anti SQL Injection |
| Kredensial DB | Hanya di `config/database.php` | Sesuai permintaan; file dilindungi `.htaccess` (deny) |
| Password | `password_hash(PASSWORD_BCRYPT)` + `password_verify()` | Hash satu arah, salt otomatis |
| Session | Cookie `httponly` + `samesite=Lax`, `session_regenerate_id()` saat login | Anti session fixation & XSS cookie theft |
| CSRF | Token per-session, dicek di **semua** POST (form & AJAX header `X-CSRF-Token`) | Wajib di prompt |
| Output | Helper `e()` = `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` di semua echo variabel | Anti XSS |
| Upload | Whitelist ekstensi **+** cek MIME `finfo` **+** rename unik **+** `.htaccess` matikan eksekusi PHP di `/uploads` | Anti webshell |
| PDF | `libs/SimplePDF.php` (pure PHP, tanpa Composer/ekstensi tambahan) | InfinityFree tidak mengizinkan `composer install` |
| Excel | `libs/Xlsx.php` (ZipArchive + XML, murni PHP) | `.xlsx` = ZIP berisi XML; ZipArchive sudah aktif di shared hosting |
| Bootstrap | File lokal `assets/vendor/` (bukan CDN) | Tidak bergantung layanan eksternal, tetap jalan offline |
| Hak akses | Guard di **awal setiap file backend** (`require_admin()` / `require_member()`) | Bukan sekadar menyembunyikan tombol |
| Multi-outing | Semua data transaksional punya FK `outing_id`, upload dipisah `/uploads/outing/{outing_id}/` | Outing baru tidak pernah menimpa data lama |

### 1.3 Pembagian modul

```
MODUL AUTH        : login (User ID + Password), logout, guard role, reset password (oleh admin)
MODUL USER        : CRUD user, aktif/nonaktif, reset password, import Excel
MODUL OUTING      : CRUD outing, status (Planning/Active/Selesai/Cancelled), dashboard aktif & history
MODUL PESERTA     : tambah user ke outing, attendance_status, payment_status, nominal & tgl bayar
MODUL RUNDOWN     : CRUD jadwal + urutan (sortable), waktu, lokasi, PIC, keterangan
MODUL KATEGORI    : CRUD kategori pengeluaran (dinamis) + "Buat Kategori Otomatis" saat import
MODUL PEMBELIAN   : CRUD pengeluaran, total otomatis Qty × Harga, upload bukti (JPG/PNG/PDF)
MODUL IMPORT      : template .xlsx (Sheet DATA + Sheet PETUNJUK) → upload → validasi → preview → konfirmasi
MODUL EXPORT      : laporan PDF per `outing_id` (aktif maupun history)
MODUL MEMBER      : view-only (dashboard, outing saya, rundown timeline, peserta, status diri, pembelian, bukti)
```

---

## 2. Struktur Database & Relasi

### 2.1 Daftar tabel

| Tabel | Fungsi | Kunci |
|---|---|---|
| `users` | Akun login (admin & member) | PK `id`, UNIQUE `user_id` |
| `outings` | Data outing (multi-outing & history) | PK `id`, UNIQUE `kode`, INDEX `status`, `tanggal_mulai` |
| `outing_participants` | Relasi user ↔ outing + kehadiran + pembayaran | PK `id`, UNIQUE (`outing_id`,`user_id`) |
| `rundown` | Jadwal acara per outing | PK `id`, INDEX (`outing_id`,`urutan`) |
| `categories` | Kategori pengeluaran (dinamis) | PK `id`, UNIQUE `nama` |
| `purchases` | Pengeluaran/pembelian per outing | PK `id`, INDEX `outing_id`, `category_id`, `tanggal_beli` |
| `activity_logs` | Jejak audit (siapa, apa, kapan, IP) | PK `id`, INDEX `user_id`, `created_at` |

### 2.2 Diagram relasi (ERD)

```
                        ┌───────────────┐
                        │     users     │
                        │---------------│
              ┌────────►│ id (PK)       │◄───────────┐
              │         │ user_id (UQ)  │            │
              │         │ nama          │            │
              │         │ password      │            │
              │         │ role          │            │
              │         │ status        │            │
              │         └───────┬───────┘            │
              │                 │ 1                  │
              │                 │                    │
              │                 │ N                  │
              │         ┌───────▼───────────────┐    │
              │         │  outing_participants  │    │
              │         │-----------------------│    │
              │         │ id (PK)               │    │
              │  N      │ outing_id (FK)        │    │
       ┌──────┴──────┐  │ user_id (FK)          │    │
       │   outings   │  │ attendance_status     │    │
       │-------------│  │ payment_status        │    │
       │ id (PK)     │◄─┤ nominal_bayar         │    │
       │ kode (UQ)   │  │ tanggal_bayar         │    │
       │ nama        │  └───────────────────────┘    │
       │ tujuan      │ 1                             │
       │ lokasi      │                               │
       │ tanggal_*   │ 1   ┌────────────┐            │
       │ status      │◄────┤  rundown   │            │
       │ anggaran    │  N  │------------│            │
       │ iuran       │     │ id (PK)    │            │
       │ created_by  │────►│ outing_id  │            │
       └──────┬──────┘  N  │ urutan     │            │
              │            │ acara      │            │
              │ 1          │ waktu_*    │            │
              │            │ lokasi/pic │            │
              │ N          └────────────┘            │
       ┌──────▼──────┐  N   ┌────────────┐           │
       │  purchases  │─────►│ categories │           │
       │-------------│      │------------│           │
       │ id (PK)     │      │ id (PK)    │           │
       │ outing_id   │      │ nama (UQ)  │           │
       │ category_id │      └────────────┘           │
       │ qty, harga  │                                │
       │ total_harga │      ┌────────────────┐        │
       │ bukti_file  │      │ activity_logs  │────────┘
       │ created_by  │      │ id, user_id,   │   (FK users.id ON DELETE SET NULL)
       └─────────────┘      │ aktivitas, ip  │
                            └────────────────┘
```

### 2.3 Aturan FK & integritas

| Relasi | ON DELETE | ON UPDATE | Catatan |
|---|---|---|---|
| `outing_participants.outing_id → outings.id` | CASCADE | CASCADE | Hapus outing → pesertanya ikut terhapus |
| `outing_participants.user_id → users.id` | CASCADE | CASCADE | Hapus user → kepesertaan ikut terhapus |
| `outings.created_by → users.id` | SET NULL | CASCADE | Riwayat outing tetap ada walau pembuat dihapus |
| `rundown.outing_id → outings.id` | CASCADE | CASCADE | |
| `purchases.outing_id → outings.id` | CASCADE | CASCADE | File bukti dihapus manual oleh PHP sebelum/sesudah query |
| `purchases.category_id → categories.id` | RESTRICT | CASCADE | Kategori yang sudah dipakai **tidak bisa** dihapus (mencegah data yatim) |
| `purchases.created_by → users.id` | SET NULL | CASCADE | |
| `activity_logs.user_id → users.id` | SET NULL | CASCADE | |

**Kolom default penting:**
`users.status = 'aktif'`, `users.role = 'member'`, `outings.status = 'Planning'`,
`outing_participants.attendance_status = 'Tidak Ikut'`, `outing_participants.payment_status = 'Belum'`,
`rundown.urutan = 0`, `purchases.qty = 1`, `purchases.total_harga = 0`.

**Enum status (sumber validasi PHP & Excel):**
- `outings.status` : `Planning` | `Active` | `Selesai` | `Cancelled`
- `attendance_status` : `Ikut` | `Batal` | `Tidak Ikut`
- `payment_status` : `Sudah` | `Belum`
- `users.role` : `admin` | `member`
- `users.status` : `aktif` | `nonaktif`

### 2.4 Perhitungan keuangan (server-side, tidak dipercaya dari client)
```
total_pembelian_item = qty × harga_satuan                    (selalu dihitung ulang PHP)
total_pengeluaran    = SUM(purchases.total_harga)  WHERE outing_id = ?
per_kategori         = SUM(total_harga) GROUP BY category_id
total_iuran_masuk    = SUM(nominal_bayar) WHERE payment_status = 'Sudah'
saldo / sisa         = anggaran − total_pengeluaran
persentase_per_kategori = total_kategori ÷ total_pengeluaran × 100
```
> Nilai kolom `total` pada file Excel import **diabaikan** dan dihitung ulang dari `Qty × Harga`.

---

## 3. Struktur Folder (siap upload ke `public_html`)

```
public_html/
├── index.php                 # Gerbang: form login + auto-redirect sesuai role
├── logout.php                # Destroy session (cek CSRF)
├── database.sql              # CREATE TABLE + index + data awal
├── README.md                 # Tutorial instalasi 8 langkah
├── ARCHITECTURE.md           # Dokumen Tahap 1 ini
├── .htaccess                 # Proteksi config/includes/libs, matikan directory listing
│
├── config/
│   ├── config.php            # Konstanta aplikasi, BASE_URL, session hardening
│   └── database.php          # ★ KREDENSIAL DB + PDO singleton
│
├── includes/
│   ├── functions.php         # e(), flash(), rupiah(), slug(), validasi, upload bukti
│   ├── auth.php              # require_login/require_admin/require_member, current_user
│   ├── csrf.php              # csrf_token(), csrf_field(), csrf_verify()
│   ├── icons.php             # Ikon SVG inline (tanpa font/CDN eksternal)
│   ├── header.php            # Layout atas (sidebar admin / navbar member)
│   ├── footer.php            # Layout bawah + <script>
│   └── activity.php          # Pencatat activity_logs
│
├── libs/
│   ├── SimplePDF.php         # Library PDF pure PHP (multi-halaman, tabel, header/footer)
│   └── Xlsx.php              # Library Excel .xlsx pure PHP (reader + writer)
│
├── api/                      # Endpoint AJAX (JSON) — semuanya wajib login + CSRF
│   ├── participants.php      # ubah kehadiran/pembayaran, tambah, hapus peserta
│   ├── purchases.php         # hitung total, simpan, hapus, hapus bukti
│   ├── rundown.php           # simpan urutan (drag/naik-turun), hapus
│   ├── outings.php           # ubah status outing cepat
│   └── users.php             # toggle aktif/nonaktif, reset password
│
├── admin/                    # ★ FULL CRUD — guard require_admin() di tiap file
│   ├── dashboard.php         # Ringkasan outing aktif, peserta, keuangan
│   ├── history.php           # Dashboard history (read-only + tombol Edit History)
│   ├── users.php             # CRUD user + import Excel
│   ├── outings.php           # Daftar + form outing (buat/edit/ubah status)
│   ├── participants.php      # Kelola peserta per outing
│   ├── rundown.php           # Kelola rundown per outing (atur urutan)
│   ├── categories.php        # Kelola kategori pengeluaran dinamis
│   ├── purchases.php         # Kelola pembelian + upload bukti
│   ├── import.php            # Wizard import Excel: upload → preview → konfirmasi
│   ├── template_excel.php    # Download template .xlsx (Sheet DATA + Sheet PETUNJUK)
│   ├── export_pdf.php        # Export laporan PDF per outing_id
│   └── activity.php          # Log aktivitas
│
├── member/                   # ★ VIEW ONLY — guard require_member() di tiap file
│   ├── dashboard.php         # Outing aktif & history yang saya ikuti
│   ├── outing.php            # Detail outing: rundown timeline, peserta, pembelian, status diri
│   ├── bukti.php             # Lihat/unduh bukti pembayaran (streaming file aman)
│   └── profile.php           # Data diri + ganti password sendiri
│
├── assets/
│   ├── css/style.css         # Tema kustom di atas Bootstrap 5
│   ├── js/app.js             # AJAX, konfirmasi hapus, kalkulasi Qty×Harga, filter tabel
│   └── vendor/               # bootstrap.min.css, bootstrap.bundle.min.js (lokal)
│
└── uploads/
    ├── .htaccess             # Matikan eksekusi PHP di folder upload
    └── outing/
        ├── 1/                # ★ bukti pembelian dipisah per outing_id
        ├── 2/
        └── .../              # nama file aman: {slug}-{uniqid}.{ext}
```

---

## 4. Alur Autentikasi (Auth)

```
[Buka website]
     │
     ▼
index.php ── session aktif? ──YA──► role=admin  ──► /admin/dashboard.php
     │                          └─► role=member ──► /member/dashboard.php
     NO
     ▼
Tampilkan form login (User ID + Password) + hidden CSRF token
     │  POST
     ▼
1. csrf_verify()            ── gagal ──► flash error, kembali ke form
2. Validasi input (trim, panjang)
3. SELECT * FROM users WHERE user_id = ? LIMIT 1      (prepared statement)
4. password_verify($input, $hash)  ── gagal ──► flash "User ID / password salah"
                                                (+ catat percobaan, anti timing: verify dummy)
5. Cek status = 'aktif'     ── nonaktif ──► flash "Akun dinonaktifkan, hubungi admin"
6. session_regenerate_id(true)
7. Simpan ke SESSION: uid, user_id, nama, role
8. activity_logs: "Login berhasil"
     ▼
Redirect sesuai role
```

**Aturan guard (dijalankan di baris awal setiap file):**

| File berada di | Guard | Jika dilanggar |
|---|---|---|
| `/admin/*`, `/api/*` (aksi tulis) | `require_admin()` | member → redirect `/member/dashboard.php` + flash "Akses ditolak"; tamu → `index.php` |
| `/member/*` | `require_member()` | admin → redirect `/admin/dashboard.php` |
| `/uploads/*` | `.htaccess` + `member/bukti.php` | akses langsung diblok, file PDF/gambar di-stream lewat PHP dengan cek kepesertaan |

> **Prinsip:** menyembunyikan tombol di HTML **tidak** dianggap proteksi. Setiap endpoint memverifikasi
> session + role + (untuk member) kepesertaan pada `outing_id` yang diminta.

---

## 5. Alur Admin (Full Akses)

### 5.1 Workflow utama sistem
```
1. Buat Outing ─────────► admin/outings.php (kode, nama, tujuan, tanggal, anggaran, iuran, status=Planning)
2. Tambah Peserta ──────► admin/participants.php (pilih user / import Excel) → set Ikut|Batal|Tidak Ikut
3. Buat Rundown ────────► admin/rundown.php (urutan, waktu, acara, lokasi, PIC)
4. Input Pembelian ─────► admin/purchases.php (kategori dinamis, Qty × Harga otomatis) + upload bukti JPG/PNG/PDF
5. Set Selesai ─────────► admin/outings.php / api/outings.php (status = Selesai) → otomatis pindah ke History
6. Update Pembayaran ───► admin/participants.php (payment_status = Sudah, nominal, tanggal bayar)
7. Download PDF ────────► admin/export_pdf.php?outing_id=… (laporan lengkap)
```

### 5.2 Alur CRUD umum (form-based)
```
Halaman list → klik tombol → form (create/edit) → POST
   → require_admin() → csrf_verify() → validasi & sanitasi
   → prepared statement INSERT/UPDATE/DELETE
   → (khusus hapus bukti) unlink file lama di /uploads/outing/{id}/
   → activity_logs → flash sukses → redirect (PRG pattern, anti double-submit)
```

### 5.3 Alur hapus (wajib konfirmasi)
```
Klik "Hapus" → Bootstrap Modal konfirmasi (nama data ditampilkan) → "Ya, Hapus"
   → POST + CSRF → hapus → flash → redirect
```

### 5.4 Alur Import Excel (User / Peserta / Pembelian)
```
[A] Klik "Download Template Excel"  → template_excel.php?type=…
      Sheet 1 "DATA"     : baris 1 = header kolom, baris 2-3 = contoh
      Sheet 2 "PETUNJUK" : daftar kolom wajib, format tanggal, nilai status yang VALID

[B] admin/import.php?type=… → upload .xlsx (validasi ekstensi + MIME zip + ukuran ≤ 2 MB)

[C] Sistem baca Sheet "DATA" → validasi BARIS PER BARIS → simpan hasil ke SESSION (payload preview)

[D] Halaman PREVIEW:
      ✔ baris valid   → badge hijau "SIAP IMPORT"
      ✖ baris error   → badge merah + pesan spesifik (kolom kosong / format salah / duplikat / user tidak ditemukan)
      ⚠ kategori tidak ada → badge kuning + tombol "Buat Kategori Otomatis" (atau baris dibatalkan)
      Ringkasan: X baris valid, Y baris error

[E] Klik "Konfirmasi Import" (POST + CSRF)
      → proses HANYA baris valid, baris error diabaikan (tidak membatalkan keseluruhan)
      → kolom total di Excel diabaikan, dihitung ulang Qty × Harga
      → hasil: "Berhasil import N data, M baris dilewati" + activity_logs

[F] Batal → session preview dibuang
```

### 5.5 Alur Export PDF
```
admin/export_pdf.php?outing_id=N (aktif / history)
  → ambil data: outing, ringkasan peserta, ringkasan keuangan, daftar peserta,
    rundown, rekap per kategori, detail pembelian
  → render SimplePDF (A4 portrait, header & footer + nomor halaman)
  → output inline (preview browser) atau ?download=1 (attachment)
```

---

## 6. Alur Member (100% View Only)

```
Login (role=member) → /member/dashboard.php
   │
   ├─ Kartu "Outing Aktif Saya"  : hanya outing dengan status Planning/Active
   │                               DAN user tercatat di outing_participants
   │      → status diri (badge Ikut/Batal/Tidak Ikut + Sudah/Belum bayar + nominal)
   │      → progres iuran terkumpul vs anggaran (bar CSS, tanpa library eksternal)
   │
   ├─ Kartu "History Saya"       : outing berstatus Selesai/Cancelled yang pernah diikuti
   │
   └─ Detail: /member/outing.php?id=N
         1) Cek kepesertaan (bukan peserta → tolak + redirect dashboard)
         2) Info outing (tujuan, tanggal, lokasi, anggaran, iuran)
         3) Rundown → tampilan TIMELINE/CARD ramah HP
         4) Daftar peserta (nama + kehadiran, tanpa data sensitif lain)
         5) Pembelian (kategori, qty, harga, total) + rekap per kategori
         6) Bukti bayar/bukti pembelian → lihat lewat /member/bukti.php (cek izin, stream file)

   TIDAK ADA: tombol tambah/ubah/hapus, form POST tulis, akses /admin/*, akses /api/* aksi tulis
   Jika member mencoba membuka URL admin → require_admin() menolak → redirect /member/dashboard.php
                                            + flash "Akses ditolak: halaman khusus Administrator."
```

---

## 7. Peta Keamanan (checklist implementasi)

| Ancaman | Mitigasi di kode |
|---|---|
| SQL Injection | PDO prepared statement semua query; `EMULATE_PREPARES=false`; whitelist nama kolom untuk ORDER BY |
| XSS | `e()` di semua output; `strip_tags` untuk input teks; header `X-Content-Type-Options: nosniff` |
| CSRF | Token per session, cek di semua POST (form + AJAX header) |
| Session hijack/fixation | `httponly`, `samesite=Lax`, `session_regenerate_id(true)` saat login |
| Brute force ringan | Delay + pencatatan `activity_logs` tiap kegagalan login |
| Upload webshell | Whitelist `jpg/jpeg/png/pdf`, cek MIME `finfo_file`, rename unik, `.htaccess` di `/uploads` (`php_flag engine off`, `Options -ExecCGI`) |
| Path traversal | `basename()` + `realpath()` harus berada di dalam `/uploads/outing/{id}/` |
| Privilege escalation | Guard role di **setiap** file backend + cek kepesertaan untuk member |
| Data hilang | FK RESTRICT pada kategori terpakai; history read-only; hapus outing butuh konfirmasi + tidak menimpa outing lain |
| Info bocor | Kredensial hanya di `config/database.php`; folder `config/includes/libs` diblok `.htaccess`; `display_errors=Off` di produksi |

---

## 8. Rencana Deliverable (Tahap 2)

1. Seluruh **source code** PHP/HTML/CSS/JS sesuai struktur folder di atas.
2. **`database.sql`** — CREATE DATABASE/TABLE, PK, FK, INDEX, DEFAULT, dan data awal (admin + contoh outing).
3. **`README.md`** — tutorial instalasi **8 langkah** dari nol (hosting → DB → import SQL → upload →
   edit config → buka web → login admin default → uji fitur & checklist keamanan).
