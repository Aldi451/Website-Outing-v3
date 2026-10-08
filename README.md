# OUTING MANAGEMENT SYSTEM v3.0

Sistem manajemen outing kantor **100% PHP Native + MySQL** yang dirancang agar berjalan di
**shared hosting gratis (InfinityFree)** maupun cPanel biasa — **tanpa Node.js, tanpa `npm install`,
tanpa Composer, tanpa API/layanan eksternal, tanpa email verifikasi & OTP**.

> **Dokumen perencanaan (Tahap 1):** lihat [`ARCHITECTURE.md`](ARCHITECTURE.md) — berisi analisis
> arsitektur, struktur database & relasi (ERD), struktur folder, alur auth, alur admin, dan alur member.

---

## 1. RINGKASAN FITUR

| Modul | Administrator (Full CRUD) | Member (100% View Only) |
|---|---|---|
| Dashboard | Ringkasan outing aktif, peserta, keuangan, pengeluaran per kategori, rundown terdekat, log aktivitas | Outing aktif & history yang diikuti, status diri, tagihan |
| Outing | Buat/ubah/hapus, multi-outing, status **Planning · Active · Selesai · Cancelled** | Lihat info outing yang diikuti |
| History | Dashboard history (filter tahun/status/tujuan) + tombol **Edit History** berkonfirmasi | Lihat riwayat keikutsertaan |
| Peserta | Tambah user ke outing, set **Kehadiran** (Ikut/Batal/Tidak Ikut) & **Pembayaran** (Sudah/Belum) secara terpisah, nominal & tanggal bayar | Lihat daftar peserta & status dirinya sendiri |
| Rundown | CRUD jadwal + atur urutan (naik/turun/rapikan), hari, waktu, lokasi, PIC | Tampilan **timeline/card** ramah HP |
| Pembelian | CRUD pengeluaran, kategori **dinamis**, total otomatis **Qty × Harga**, upload bukti **JPG/PNG/PDF** | Lihat rincian pengeluaran & buka bukti |
| Kategori | Tambah/ubah/nonaktif/hapus kategori biaya | – |
| Import Excel | **User, Peserta, Pembelian**: template 2 sheet → upload → validasi → **preview per baris** → konfirmasi | – |
| Export PDF | Laporan lengkap per `outing_id` (aktif maupun history) | – |
| Pengguna | CRUD user, aktif/nonaktif, **reset password** | Ganti password sendiri |
| Keamanan | CSRF, PDO prepared statement, `password_hash()`, validasi MIME upload, guard role di server, log audit | Ditolak & di-redirect bila membuka URL admin |

---

## 2. PERSYARATAN SERVER

| Kebutuhan | Minimal | Keterangan |
|---|---|---|
| PHP | 7.4 (disarankan 8.0 – 8.2) | Kode ditulis kompatibel PHP 7.4+ |
| MySQL / MariaDB | MySQL 5.7 / MariaDB 10.2 | InnoDB + `utf8mb4` |
| Ekstensi PHP | `pdo_mysql`, `zip`, `json`, `fileinfo`, `mbstring` (opsional) | Semua sudah aktif di InfinityFree |
| Apache | `.htaccess` diizinkan (`AllowOverride All`) | Untuk proteksi folder & upload |
| Ruang | ± 5 MB (termasuk Bootstrap & ikon lokal) | Belum termasuk file bukti upload |

**Tidak dibutuhkan:** Composer, Node.js, npm, Git di server, koneksi internet keluar (semua aset CSS/JS bersifat lokal).

---

## 3. STRUKTUR FOLDER (SIAP UPLOAD KE `public_html`)

```
public_html/
├── index.php              ← gerbang login + auto-redirect sesuai role
├── logout.php             ← logout (POST + CSRF)
├── database.sql           ← CREATE TABLE + index + data awal
├── README.md              ← file ini
├── ARCHITECTURE.md        ← dokumen Tahap 1 (analisis arsitektur)
├── .htaccess              ← proteksi config/includes/libs + blokir file .sql/.md
├── config/
│   ├── config.php         ← konstanta app, BASE_URL otomatis, session hardening
│   └── database.php       ← ★ KREDENSIAL DATABASE (edit file ini)
├── includes/              ← init, functions, auth, csrf, activity, header, footer
├── libs/
│   ├── SimplePDF.php      ← generator PDF murni PHP (tanpa Composer)
│   └── Xlsx.php           ← pembaca/penulis .xlsx murni PHP (ZipArchive + XML)
├── api/                   ← endpoint AJAX (JSON): participants, purchases, rundown, outings, users
├── admin/                 ← 12 halaman CRUD admin
├── member/                ← 4 halaman view-only member
├── assets/
│   ├── css/style.css      ├── js/app.js
│   └── vendor/            ← bootstrap.min.css, bootstrap.bundle.min.js, bootstrap-icons (lokal)
└── uploads/
    ├── .htaccess          ← matikan eksekusi PHP di folder upload
    └── outing/{outing_id}/ ← bukti pembelian, TERPISAH per outing, nama file aman
```

---

## 4. TUTORIAL INSTALASI 8 LANGKAH (DARI NOL)

### ✅ LANGKAH 1 — Buat Akun & Hosting (InfinityFree)

1. Buka <https://infinityfree.com> → **Sign Up** → buat akun (email + password).
2. Klik **Create Account** → pilih subdomain gratis (mis. `outingku.rf.gd`) atau pakai domain sendiri.
3. Tunggu status hosting **Active** (biasanya beberapa menit).
4. Catat informasi di *Control Panel*:
   - **MySQL Host Name** (mis. `sql123.infinityfree.com` atau `localhost`)
   - **MySQL Database Name** (mis. `epiz_12345678_outing`)
   - **MySQL Username** (mis. `epiz_12345678`)
   - **MySQL Password** (dilihat di panel, *bukan* password login akun)
5. Buka **Control Panel → phpMyAdmin** dan **Online File Manager / FTP** (dipakai pada langkah berikutnya).

> **XAMPP/Lokal?** Lewati langkah 1–2. Cukup jalankan Apache + MySQL, lalu buka
> `http://localhost/phpmyadmin`. Salin proyek ke `C:\xampp\htdocs\outing\` (Windows) atau
> `/opt/lampp/htdocs/outing/` (Linux). BASE_URL otomatis menyesuaikan sub-folder.

---

### ✅ LANGKAH 2 — Buat Database MySQL

**Di InfinityFree / cPanel:**

1. Control Panel → **MySQL Databases**.
2. Isi *Database Name* → contoh `outing` (sistem akan menambahkan prefix akun, menjadi `epiz_12345678_outing`).
3. Klik **Create Database**.
4. Username & password MySQL umumnya sudah otomatis (username = `epiz_12345678`, password = password MySQL akun).
5. Catat **nama database lengkap**, **username**, **password**, dan **host**.

**Di phpMyAdmin lokal:**

```sql
CREATE DATABASE outing_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

> ⚠️ Charset **wajib `utf8mb4`** agar nama/tujuan berisi tanda kutip atau karakter khusus tersimpan benar.

---

### ✅ LANGKAH 3 — Import `database.sql`

1. Buka **phpMyAdmin** → pilih database yang baru dibuat (klik namanya di panel kiri).
2. Tab **Import** → **Choose File** → pilih `database.sql` dari folder proyek ini.
3. *Format* biarkan **SQL** → klik **Go / Import**.
4. Tunggu hingga muncul pesan hijau *"Import has been successfully finished"*.
5. Verifikasi: harus ada **7 tabel** → `users`, `outings`, `outing_participants`, `rundown`,
   `categories`, `purchases`, `activity_logs` beserta data awal (2 admin, 10 member, 8 kategori, 4 contoh outing).

> Bila phpMyAdmin membatasi ukuran upload, file `database.sql` proyek ini kecil (< 40 KB) sehingga aman.
> **Jangan** menjalankan bagian `CREATE DATABASE`/`USE` (sudah dikomentari) bila Anda mengimport ke database yang sudah dipilih.

---

### ✅ LANGKAH 4 — Upload Seluruh File ke `public_html`

**Opsi A — Online File Manager (paling mudah):**

1. Control Panel → **Online File Manager** → masuk folder `htdocs` (= `public_html`).
2. **Zip** seluruh isi folder proyek di komputer Anda (isinya: `index.php`, `admin/`, `member/`, `api/`,
   `config/`, `includes/`, `libs/`, `assets/`, `uploads/`, `.htaccess`, `database.sql`, `README.md`) →
   upload file `.zip` → klik kanan → **Extract**.
3. Pastikan `index.php` berada **langsung** di dalam `htdocs`, bukan di `htdocs/Website-Outing-v3/`.

**Opsi B — FTP (FileZilla):**

```
Host     : ftpupload.net   (lihat panel InfinityFree)
Username : epiz_12345678
Password : password FTP akun Anda
Port     : 21
```
Salin **seluruh isi** folder proyek ke `/htdocs/`.

**Opsi C — XAMPP:** salin isi proyek ke `htdocs/outing/`.

> 🔒 File `.htaccess` **wajib ikut terupload** (aktifkan "Show hidden files" di FileZilla/File Manager).
> Tanpa `.htaccess`, folder `config/` dan file `database.sql` dapat diunduh pengunjung.

---

### ✅ LANGKAH 5 — Edit `config/database.php`

Buka `config/database.php` (File Manager → Edit, atau editor lokal lalu upload ulang) dan sesuaikan **5 baris** ini:

```php
define('DB_HOST', 'sql123.infinityfree.com');       // atau 'localhost'
define('DB_NAME', 'epiz_12345678_outing');          // nama database LENGKAP
define('DB_USER', 'epiz_12345678');                 // username MySQL
define('DB_PASS', 'passwordMySQLAnda');             // password MySQL
define('DB_PORT', '3306');
```

Untuk XAMPP lokal umumnya:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'outing_db');
define('DB_USER', 'root');
define('DB_PASS', '');
```

Simpan file. **Tidak ada konfigurasi lain yang wajib** — `BASE_URL` dideteksi otomatis
(mendukung install di root maupun sub-folder, HTTP maupun HTTPS).

> Bila muncul pesan *"Koneksi database gagal"*, periksa: nama host, nama database (termasuk prefix),
> username, password, dan pastikan langkah 3 sudah dilakukan.
> Untuk debug sementara, ubah `define('APP_DEBUG', false);` menjadi `true` di `config/config.php`
> — **kembalikan ke `false` setelah selesai**.

---

### ✅ LANGKAH 6 — Set Permission Folder `uploads`

Folder bukti pembelian harus dapat ditulis PHP:

```
uploads/                → 755 (atau 775)
uploads/outing/         → 755
uploads/outing/{id}/    → dibuat otomatis oleh aplikasi saat bukti pertama diupload
```

- **File Manager InfinityFree:** klik kanan folder → *Permissions* → `755`.
- **FTP:** klik kanan → *File permissions* → centang *Read+Write+Execute* untuk owner & group.
- **Linux/XAMPP:** `chmod -R 755 uploads/`

> Bila permission salah, upload bukti akan ditolak dengan pesan
> *"Folder upload tidak dapat ditulis"*.

---

### ✅ LANGKAH 7 — Buka Website di Browser

1. Kunjungi alamat situs Anda, contoh:
   - InfinityFree: `http://outingku.rf.gd/`
   - XAMPP root: `http://localhost/`
   - XAMPP sub-folder: `http://localhost/outing/`
2. Halaman **Login** akan tampil (kartu putih di atas latar gelap biru-ungu).
3. Bila muncul peringatan database → ulangi **Langkah 5**.
4. Bila halaman 500 (Internal Server Error) → biasanya `.htaccess` tidak didukung;
   coba hapus sementara `.htaccess` di root untuk memastikan, lalu hubungi dukungan hosting
   atau pindahkan `config/`, `includes/`, `libs/` ke luar `public_html`.

---

### ✅ LANGKAH 8 — Login Admin Default & Uji Sistem

**Akun bawaan (hasil import `database.sql`):**

| Role | User ID | Password | Keterangan |
|---|---|---|---|
| Administrator | `ADMIN001` | `admin123` | Akses penuh |
| Administrator | `ADMIN002` | `admin123` | Akses penuh |
| Member | `MBR001` … `MBR010` | `member123` | Hanya lihat (MBR010 nonaktif untuk uji) |

**Checklist setelah login (± 10 menit):**

1. 🔐 **Ganti password admin** → *Pengguna → Edit ADMIN001 → isi Password Baru*.
2. ➕ **Buat outing baru** → *Data Outing → Buat Outing* (kode otomatis `OUT-YYYY-NNN`).
3. 👥 **Tambah peserta** → tab *Peserta → Tambah Peserta* (centang beberapa user) → ubah dropdown
   kehadiran/pembayaran (tersimpan otomatis via AJAX).
4. 📅 **Susun rundown** → tab *Rundown → Tambah Rundown* → coba tombol ↑ ↓ untuk mengatur urutan.
5. 🧾 **Input pembelian** → tab *Pembelian → Tambah Pembelian* → isi Qty & Harga → total terhitung otomatis →
   upload bukti JPG/PDF.
6. 📊 **Import Excel** → *Import Excel → Download Template Excel* → isi → upload → periksa **preview per baris** →
   *Konfirmasi Import*.
7. 📄 **Export PDF** → tab *Export PDF* → laporan lengkap terbuka di browser (`?download=1` untuk mengunduh).
8. ✔️ **Set Selesai** → ubah status outing menjadi **Selesai** → otomatis pindah ke **History Outing**
   (read-only, dengan tombol *Edit History* berkonfirmasi).
9. 👤 **Login sebagai member** (`MBR001` / `member123`) → pastikan hanya bisa melihat, dan cobalah buka
   `admin/dashboard.php` manual → sistem menolak & me-redirect ke dashboard member.

---

## 5. ALUR PAKAI SISTEM (WORKFLOW)

```
Buat Outing  →  Tambah Peserta  →  Susun Rundown  →  Input Pembelian + Bukti
     →  Set status "Selesai"  →  Update Pembayaran Akhir  →  Download PDF
```

**Import Excel (User / Peserta / Pembelian):**

```
Download Template (.xlsx)  →  isi Sheet "DATA" (baca Sheet "PETUNJUK")
   →  Upload  →  Validasi per baris  →  Halaman PREVIEW (hijau/kuning/merah)
   →  [opsional] centang "Buat Kategori Otomatis"
   →  Konfirmasi Import  →  hanya baris VALID yang disimpan, baris error DIABAIKAN
```

**Nilai status yang valid di Excel:**

| Kolom | Nilai yang diterima |
|---|---|
| Role | `admin`, `member` |
| Status akun | `aktif`, `nonaktif` |
| Kehadiran | `Ikut`, `Batal`, `Tidak Ikut` |
| Pembayaran | `Sudah`, `Belum` |
| Tanggal | `YYYY-MM-DD` (contoh `2026-11-20`) |
| Angka | tanpa pemisah ribuan (contoh `4500000`) |

> Kolom **Total** pada template pembelian **selalu diabaikan** — sistem menghitung ulang `Qty × Harga`.

---

## 6. KEAMANAN (SUDAH DITERAPKAN DI KODE)

| Ancaman | Mitigasi |
|---|---|
| SQL Injection | 100% PDO **prepared statement** (`ATTR_EMULATE_PREPARES = false`); `ORDER BY` memakai whitelist |
| XSS | Semua output lewat helper `e()` (`htmlspecialchars` ENT_QUOTES) |
| CSRF | Token per-session, diverifikasi di **setiap** POST (form & header `X-CSRF-Token` untuk AJAX) |
| Password | `password_hash()` bcrypt cost 12 + `password_verify()` + auto rehash |
| Session hijack/fixation | Cookie `HttpOnly` + `SameSite=Lax` + `Secure` saat HTTPS + `session_regenerate_id(true)` + idle timeout 2 jam |
| Upload berbahaya | Whitelist `jpg/jpeg/png/pdf`, cek **MIME asli** (`finfo`), cek silang ekstensi↔MIME, nama file diacak, `.htaccess` mematikan eksekusi PHP di `/uploads` |
| Path traversal | `basename()` + `realpath()` harus berada di dalam `/uploads/outing/{id}/` |
| Privilege escalation | Guard `require_admin()` / `require_member()` / `require_participant()` di **baris awal setiap file** (bukan sekadar menyembunyikan tombol) |
| Bocor konfigurasi | `.htaccess` menolak akses `config/`, `includes/`, `libs/`, serta file `.sql/.md/.log/.env` |
| Audit | Seluruh tindakan penting dicatat di `activity_logs` (user, aksi, IP, waktu) |
| Data hilang | History read-only, `ON DELETE RESTRICT` untuk kategori terpakai, outing baru tidak pernah menimpa data lama |

**Yang sengaja TIDAK ada** (sesuai spesifikasi): registrasi member mandiri, verifikasi email, OTP,
lupa password via email, integrasi API/layanan eksternal.

---

## 7. TROUBLESHOOTING

| Gejala | Penyebab & Solusi |
|---|---|
| `Koneksi database gagal` | Kredensial di `config/database.php` salah / database belum diimport. Set `APP_DEBUG` = `true` untuk melihat pesan asli. |
| Halaman putih kosong | Aktifkan `APP_DEBUG` sementara; umumnya kesalahan pada `config/database.php` atau versi PHP < 7.4. |
| `500 Internal Server Error` | `.htaccess` tidak diizinkan hosting → hapus `.htaccess` root, atau pindahkan `config/includes/libs` ke luar `public_html`. |
| CSS/tampilan berantakan | Folder `assets/` belum terupload lengkap atau `.htaccess` memblokir aset. |
| Ikon tidak muncul (kotak kosong) | `assets/vendor/bootstrap-icons/fonts/` belum terupload. |
| Upload bukti gagal | Permission folder `uploads/` bukan 755/775, atau `upload_max_filesize` PHP < 3 MB (naikkan lewat cPanel → *Select PHP Version → Options*). |
| Template Excel gagal dibuat | Ekstensi `zip` tidak aktif (`php -m` → cari `zip`); aktifkan lewat cPanel. |
| Import Excel menolak file | File harus `.xlsx` (bukan `.xls` lama), maksimal 2 MB, dan baris 1 adalah header kolom. |
| Tombol AJAX tidak merespons | `assets/js/app.js` tidak termuat, atau sesi berakhir (token CSRF kedaluwarsa) → muat ulang halaman. |
| Member bisa membuka halaman admin | Tidak mungkin bila semua file terupload utuh — pastikan `require_admin()` ada di baris awal file admin. |

---

## 8. PEMELIHARAAN

**Backup rutin**

1. phpMyAdmin → pilih database → tab **Export** → *Go* (simpan `.sql`).
2. File Manager → zip folder `uploads/` (berisi seluruh bukti transaksi).

**Membuat akun admin baru**
`admin/users.php → Tambah Pengguna → Role = admin` — atau reset password lewat ikon 🔑 (kunci).

**Menambah kategori biaya**
`admin/categories.php → Tambah Kategori` (kategori baru otomatis tersedia di form pembelian,
template Excel, dan import "Buat Kategori Otomatis").

**Upgrade kode aplikasi**
Timpa folder `admin/`, `member/`, `api/`, `includes/`, `libs/`, `assets/` dengan versi baru.
**Jangan** menimpa `config/database.php` dan **jangan** menghapus folder `uploads/`.
Bila ada perubahan skema, jalankan `ALTER TABLE` yang disertakan pada rilis tsb (bukan re-import `database.sql`,
karena re-import akan **menghapus** seluruh data).

---

## 9. CATATAN TEKNIS LIBRARY BAWAAN

| Library | Lokasi | Keterangan |
|---|---|---|
| **SimplePDF** | `libs/SimplePDF.php` | Generator PDF murni PHP (A4 portrait/landscape, font inti Helvetica/Courier, tabel bergaris dengan header berulang, auto page break, header & footer + nomor halaman "x dari y", kompresi FlateDecode). Tanpa Composer/FPDF/TCPDF. |
| **Xlsx** | `libs/Xlsx.php` | Pembaca & penulis `.xlsx` murni PHP memakai `ZipArchive` + SimpleXML (inline string, shared string, multi-sheet, lebar kolom, freeze pane, style header/zebra). Tanpa PhpSpreadsheet. |
| **Bootstrap 5.3.3** | `assets/vendor/` | File CSS/JS **lokal** (bukan CDN) agar tidak bergantung layanan eksternal. |
| **Bootstrap Icons 1.11.3** | `assets/vendor/bootstrap-icons/` | Font ikon lokal. |

Kedua library di atas hanya memakai fitur yang tersedia di shared hosting biasa:
`zip`, `libxml`/`simplexml`, `zlib` (opsional — otomatis nonaktif bila tidak ada), `fileinfo`, dan `gd` (opsional, untuk validasi gambar).

---

## 10. LISENSI & KREDIT

Kode aplikasi ini bebas dipakai dan dimodifikasi untuk kebutuhan internal perusahaan.
Bootstrap & Bootstrap Icons berada di bawah lisensi MIT (hak cipta The Bootstrap Authors).

---

**Selamat menggunakan Outing Management System.** 🏝️
Bila ada kendala, mulai dari `ARCHITECTURE.md` (arsitektur) dan bagian *Troubleshooting* di atas.
