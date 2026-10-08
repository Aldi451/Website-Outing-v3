-- ==========================================================================
--  OUTING MANAGEMENT SYSTEM v3.0 — database.sql
-- --------------------------------------------------------------------------
--  Berisi: CREATE TABLE + PRIMARY KEY + FOREIGN KEY + INDEX + DEFAULT
--          + DATA AWAL (admin default, kategori, contoh outing)
--
--  CARA IMPORT (InfinityFree / cPanel / XAMPP):
--    1. Buat database & user MySQL di panel hosting.
--    2. Buka phpMyAdmin -> pilih database tsb -> tab "Import" -> pilih file ini -> Go.
--    3. Sesuaikan config/database.php dengan nama DB/user/password Anda.
--
--  LOGIN DEFAULT SETELAH IMPORT:
--    Admin  : User ID = ADMIN001   Password = admin123
--    Member : User ID = MBR001     Password = member123
--    (SEGERA ganti password setelah instalasi!)
-- ==========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+07:00';

-- --------------------------------------------------------------------------
-- Jika ingin membuat database baru (lewati bila import via phpMyAdmin
-- ke database yang sudah dipilih). SESUAIKAN NAMA DATABASE-nya.
-- --------------------------------------------------------------------------
-- CREATE DATABASE IF NOT EXISTS `outing_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `outing_db`;

-- --------------------------------------------------------------------------
-- Hapus tabel lama (urutan dari yang punya FK ke yang direferensikan)
-- --------------------------------------------------------------------------
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `purchases`;
DROP TABLE IF EXISTS `rundown`;
DROP TABLE IF EXISTS `outing_participants`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `outings`;
DROP TABLE IF EXISTS `users`;

-- ==========================================================================
-- TABEL 1: users — akun login (admin & member)
-- ==========================================================================
CREATE TABLE `users` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     VARCHAR(50)  NOT NULL                COMMENT 'ID login unik, contoh ADMIN001 / MBR001',
  `nama`        VARCHAR(100) NOT NULL,
  `jabatan`     VARCHAR(100) NOT NULL DEFAULT '',
  `departemen`  VARCHAR(100) NOT NULL DEFAULT '',
  `no_hp`       VARCHAR(30)  NOT NULL DEFAULT '',
  `email`       VARCHAR(120) NOT NULL DEFAULT ''     COMMENT 'Opsional - sistem TIDAK memakai verifikasi email',
  `password`    VARCHAR(255) NOT NULL                COMMENT 'Hash bcrypt dari password_hash()',
  `role`        ENUM('admin','member') NOT NULL DEFAULT 'member',
  `status`      ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `last_login`  DATETIME     NULL DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_user_id` (`user_id`),
  KEY `idx_users_nama` (`nama`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Data pengguna (admin & member)';

-- ==========================================================================
-- TABEL 2: outings — multi-outing & history
--   Planning / Active  -> Dashboard Outing Aktif
--   Selesai / Cancelled -> Dashboard History
-- ==========================================================================
CREATE TABLE `outings` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode`             VARCHAR(20)  NOT NULL              COMMENT 'Kode unik, contoh OUT-2026-001',
  `nama`             VARCHAR(150) NOT NULL,
  `tujuan`           VARCHAR(150) NOT NULL              COMMENT 'Kota / tempat tujuan',
  `lokasi`           VARCHAR(150) NOT NULL DEFAULT ''   COMMENT 'Nama lokasi spesifik / titik kumpul',
  `tanggal_mulai`    DATE         NOT NULL,
  `tanggal_selesai`  DATE         NOT NULL,
  `anggaran`         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `iuran_per_orang`  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status`           ENUM('Planning','Active','Selesai','Cancelled') NOT NULL DEFAULT 'Planning',
  `keterangan`       TEXT         NULL,
  `created_by`       INT UNSIGNED NULL DEFAULT NULL,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outings_kode` (`kode`),
  KEY `idx_outings_status` (`status`),
  KEY `idx_outings_tanggal_mulai` (`tanggal_mulai`),
  KEY `idx_outings_tujuan` (`tujuan`),
  KEY `idx_outings_created_by` (`created_by`),
  CONSTRAINT `fk_outings_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
      ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Data outing (banyak outing + history)';

-- ==========================================================================
-- TABEL 3: outing_participants — kepesertaan (kehadiran & pembayaran TERPISAH)
--   UNIQUE (outing_id,user_id): satu user hanya 1 baris per outing,
--   tetapi satu user boleh ikut BANYAK outing.
-- ==========================================================================
CREATE TABLE `outing_participants` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `outing_id`          INT UNSIGNED NOT NULL,
  `user_id`            INT UNSIGNED NOT NULL,
  `attendance_status`  ENUM('Ikut','Batal','Tidak Ikut') NOT NULL DEFAULT 'Tidak Ikut',
  `payment_status`     ENUM('Sudah','Belum') NOT NULL DEFAULT 'Belum',
  `nominal_bayar`      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `tanggal_bayar`      DATE         NULL DEFAULT NULL,
  `catatan`            VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_participant_outing_user` (`outing_id`,`user_id`),
  KEY `idx_participant_user` (`user_id`),
  KEY `idx_participant_attendance` (`attendance_status`),
  KEY `idx_participant_payment` (`payment_status`),
  CONSTRAINT `fk_participant_outing` FOREIGN KEY (`outing_id`) REFERENCES `outings` (`id`)
      ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_participant_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`)
      ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Peserta outing: status kehadiran + status pembayaran';

-- ==========================================================================
-- TABEL 4: rundown — jadwal acara per outing (bisa diurutkan)
-- ==========================================================================
CREATE TABLE `rundown` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `outing_id`      INT UNSIGNED NOT NULL,
  `urutan`         INT          NOT NULL DEFAULT 0     COMMENT 'Nomor urut tampilan (sortable)',
  `hari`           VARCHAR(30)  NOT NULL DEFAULT ''    COMMENT 'Contoh: Hari 1',
  `tanggal`        DATE         NULL DEFAULT NULL,
  `waktu_mulai`    TIME         NULL DEFAULT NULL,
  `waktu_selesai`  TIME         NULL DEFAULT NULL,
  `acara`          VARCHAR(150) NOT NULL,
  `lokasi`         VARCHAR(150) NOT NULL DEFAULT '',
  `pic`            VARCHAR(100) NOT NULL DEFAULT ''    COMMENT 'Penanggung jawab acara',
  `keterangan`     TEXT         NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rundown_outing_urutan` (`outing_id`,`urutan`),
  KEY `idx_rundown_hari` (`hari`),
  CONSTRAINT `fk_rundown_outing` FOREIGN KEY (`outing_id`) REFERENCES `outings` (`id`)
      ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Rundown / jadwal acara outing';

-- ==========================================================================
-- TABEL 5: categories — kategori pengeluaran DINAMIS (bisa ditambah manual)
-- ==========================================================================
CREATE TABLE `categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama`        VARCHAR(100) NOT NULL,
  `keterangan`  VARCHAR(255) NOT NULL DEFAULT '',
  `is_aktif`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_nama` (`nama`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Kategori pembelian/pengeluaran (dinamis)';

-- ==========================================================================
-- TABEL 6: purchases — pengeluaran (total_harga = qty * harga_satuan,
--          SELALU dihitung ulang oleh PHP, tidak dipercaya dari client/Excel)
-- ==========================================================================
CREATE TABLE `purchases` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `outing_id`     INT UNSIGNED NOT NULL,
  `category_id`   INT UNSIGNED NOT NULL,
  `nama_barang`   VARCHAR(150) NOT NULL,
  `qty`           DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `satuan`        VARCHAR(20)  NOT NULL DEFAULT 'pcs',
  `harga_satuan`  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_harga`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `tanggal_beli`  DATE         NULL DEFAULT NULL,
  `bukti_file`    VARCHAR(255) NOT NULL DEFAULT ''   COMMENT 'Nama file di /uploads/outing/{outing_id}/',
  `keterangan`    VARCHAR(255) NOT NULL DEFAULT '',
  `created_by`    INT UNSIGNED NULL DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_purchases_outing` (`outing_id`),
  KEY `idx_purchases_category` (`category_id`),
  KEY `idx_purchases_tanggal` (`tanggal_beli`),
  KEY `idx_purchases_created_by` (`created_by`),
  CONSTRAINT `fk_purchases_outing`   FOREIGN KEY (`outing_id`)   REFERENCES `outings` (`id`)
      ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_purchases_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
      ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_purchases_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
      ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pembelian / pengeluaran outing + bukti (JPG/PNG/PDF)';

-- ==========================================================================
-- TABEL 7: activity_logs — jejak audit (siapa, apa, kapan, dari IP mana)
-- ==========================================================================
CREATE TABLE `activity_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NULL DEFAULT NULL,
  `aktivitas`   VARCHAR(255) NOT NULL,
  `tabel_ref`   VARCHAR(50)  NOT NULL DEFAULT '',
  `ref_id`      INT UNSIGNED NOT NULL DEFAULT 0,
  `ip_address`  VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_logs_user` (`user_id`),
  KEY `idx_logs_created` (`created_at`),
  KEY `idx_logs_tabel` (`tabel_ref`),
  CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
      ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log aktivitas pengguna';

SET FOREIGN_KEY_CHECKS = 1;

-- ==========================================================================
-- DATA AWAL
-- ==========================================================================

-- --------------------------------------------------------------------------
-- users (password: admin123 untuk admin, member123 untuk member)
-- Hash bcrypt cost 12 (prefix $2y$ = kompatibel semua versi PHP >= 5.3.7)
-- --------------------------------------------------------------------------
INSERT INTO `users` (`id`,`user_id`,`nama`,`jabatan`,`departemen`,`no_hp`,`email`,`password`,`role`,`status`) VALUES
(1 ,'ADMIN001','Rizky Pratama'      ,'Administrator'      ,'IT & General Affair','081234567001','admin@company.local'   ,'$2y$12$y//Xsy0cnFhCA5KfwKMFvOj859IfKivQYDwNrzzuUsAjprZeMSWze','admin' ,'aktif'),
(2 ,'ADMIN002','Siti Nurhaliza'     ,'HR Officer'         ,'Human Resource'     ,'081234567002','hr@company.local'      ,'$2y$12$y//Xsy0cnFhCA5KfwKMFvOj859IfKivQYDwNrzzuUsAjprZeMSWze','admin' ,'aktif'),
(3 ,'MBR001'  ,'Andi Wijaya'        ,'Staff Keuangan'     ,'Finance'            ,'081234567003','andi@company.local'    ,'$2y$12$BUbV5eRtAkob7SElFyweNOwB7aCchzauF5W6lidowS.OipTL8gK3K','member','aktif'),
(4 ,'MBR002'  ,'Dewi Lestari'       ,'Staff Marketing'    ,'Marketing'          ,'081234567004','dewi@company.local'    ,'$2y$12$BUbV5eRtAkob7SElFyweNOwB7aCchzauF5W6lidowS.OipTL8gK3K','member','aktif'),
(5 ,'MBR003'  ,'Budi Santoso'       ,'Teknisi'            ,'Engineering'        ,'081234567005','budi@company.local'    ,'$2y$12$BUbV5eRtAkob7SElFyweNOwB7aCchzauF5W6lidowS.OipTL8gK3K','member','aktif'),
(6 ,'MBR004'  ,'Rina Marlina'       ,'Staff HR'           ,'Human Resource'     ,'081234567006','rina@company.local'    ,'$2y$12$BUbV5eRtAkob7SElFyweNOwB7aCchzauF5W6lidowS.OipTL8gK3K','member','aktif'),
(7 ,'MBR005'  ,'Fajar Nugroho'      ,'Supervisor Gudang'  ,'Warehouse'          ,'081234567007','fajar@company.local'   ,'$2y$12$BUbV5eRtAkob7SElFyweNOwB7aCchzauF5W6lidowS.OipTL8gK3K','member','aktif'),
(8 ,'MBR006'  ,'Maya Anggraini'     ,'Desainer Grafis'    ,'Creative'           ,'081234567008','maya@company.local'    ,'$2y$12$BUbV5eRtAkob7SElFyweNOwB7aCchzauF5W6lidowS.OipTL8gK3K','member','aktif'),
(9 ,'MBR007'  ,'Yoga Prasetyo'      ,'Staff IT'           ,'IT'                 ,'081234567009','yoga@company.local'    ,'$2y$12$BUbV5eRtAkob7SElFyweNOwB7aCchzauF5W6lidowS.OipTL8gK3K','member','aktif'),
(10,'MBR008'  ,'Lina Kusuma'        ,'Staff Purchasing'   ,'Procurement'        ,'081234567010','lina@company.local'    ,'$2y$12$BUbV5eRtAkob7SElFyweNOwB7aCchzauF5W6lidowS.OipTL8gK3K','member','aktif'),
(11,'MBR009'  ,'Hendra Gunawan'     ,'Driver'             ,'General Affair'     ,'081234567011','hendra@company.local'  ,'$2y$12$BUbV5eRtAkob7SElFyweNOwB7aCchzauF5W6lidowS.OipTL8gK3K','member','aktif'),
(12,'MBR010'  ,'Putri Amelia'       ,'Staff Legal'        ,'Legal'              ,'081234567012','putri@company.local'   ,'$2y$12$BUbV5eRtAkob7SElFyweNOwB7aCchzauF5W6lidowS.OipTL8gK3K','member','nonaktif');

-- --------------------------------------------------------------------------
-- categories (dinamis — admin dapat menambah lewat menu Kategori)
-- --------------------------------------------------------------------------
INSERT INTO `categories` (`id`,`nama`,`keterangan`,`is_aktif`) VALUES
(1,'Transportasi'    ,'Sewa bus, BBM, tol, parkir, tiket kereta/pesawat',1),
(2,'Konsumsi'        ,'Makan berat, snack, air minum, kopi',1),
(3,'Akomodasi'       ,'Sewa villa/hotel/kemah',1),
(4,'Tiket & Wisata'  ,'Tiket masuk objek wisata dan wahana',1),
(5,'Perlengkapan'    ,'Atribut, kaos, banner, sound system, obat-obatan',1),
(6,'Dokumentasi'     ,'Fotografer, videografer, cetak foto, drone',1),
(7,'Hiburan & Game'  ,'MC, doorprize, hadiah lomba, properti games',1),
(8,'Lain-lain'       ,'Pengeluaran di luar kategori utama',1);

-- --------------------------------------------------------------------------
-- outings (contoh: 2 aktif/planning + 2 history)
-- --------------------------------------------------------------------------
INSERT INTO `outings` (`id`,`kode`,`nama`,`tujuan`,`lokasi`,`tanggal_mulai`,`tanggal_selesai`,`anggaran`,`iuran_per_orang`,`status`,`keterangan`,`created_by`) VALUES
(1,'OUT-2026-001','Outing Kantor 2026 - Yogyakarta','Yogyakarta','Hotel Grand Tjokro, Jl. Affandi No.37, Yogyakarta','2026-11-20','2026-11-22',25000000.00,350000.00,'Active'  ,'Outing tahunan seluruh karyawan. Berangkat Jumat 20 November 2026 pukul 06.00 dari kantor pusat.',1),
(2,'OUT-2026-002','Team Building Q1 2026 - Sentul','Bogor','The Highland Park Resort, Sentul, Bogor','2026-03-14','2026-03-15',12500000.00,200000.00,'Selesai' ,'Kegiatan team building divisi Marketing & Creative. Sudah selesai dan laporan telah diarsipkan.',1),
(3,'OUT-2025-003','Family Gathering 2025 - Bandung','Bandung','Grafika Cikole, Lembang, Bandung','2025-11-08','2025-11-09',18000000.00,300000.00,'Selesai' ,'Family gathering beserta keluarga karyawan (anak & pasangan).',2),
(4,'OUT-2026-004','Outing Akhir Tahun 2026 - Bali','Bali','Belum ditentukan','2026-12-18','2026-12-21',40000000.00,500000.00,'Planning','Masih tahap perencanaan dan pengumpulan suara destinasi dari peserta.',1);

-- --------------------------------------------------------------------------
-- outing_participants — outing 1 (Active)
-- --------------------------------------------------------------------------
INSERT INTO `outing_participants` (`outing_id`,`user_id`,`attendance_status`,`payment_status`,`nominal_bayar`,`tanggal_bayar`,`catatan`) VALUES
(1,1 ,'Ikut'      ,'Sudah',350000.00,'2026-09-10','Bayar tunai ke bendahara'),
(1,2 ,'Ikut'      ,'Sudah',350000.00,'2026-09-12','Transfer BCA'),
(1,3 ,'Ikut'      ,'Sudah',350000.00,'2026-09-15','Transfer BCA'),
(1,4 ,'Ikut'      ,'Belum',0.00,NULL,'Menunggu gajian Oktober'),
(1,5 ,'Ikut'      ,'Belum',0.00,NULL,''),
(1,6 ,'Ikut'      ,'Sudah',350000.00,'2026-09-20','Potong gaji'),
(1,7 ,'Ikut'      ,'Belum',0.00,NULL,''),
(1,8 ,'Ikut'      ,'Sudah',350000.00,'2026-09-22','Transfer Mandiri'),
(1,9 ,'Batal'     ,'Belum',0.00,NULL,'Sedang cuti melahirkan'),
(1,10,'Tidak Ikut','Belum',0.00,NULL,'Tugas luar kota'),
(1,11,'Ikut'      ,'Belum',0.00,NULL,'');

-- --------------------------------------------------------------------------
-- outing_participants — outing 2 (Selesai) & outing 3 (Selesai) & outing 4 (Planning)
-- --------------------------------------------------------------------------
INSERT INTO `outing_participants` (`outing_id`,`user_id`,`attendance_status`,`payment_status`,`nominal_bayar`,`tanggal_bayar`,`catatan`) VALUES
(2,2 ,'Ikut'      ,'Sudah',200000.00,'2026-02-20',''),
(2,4 ,'Ikut'      ,'Sudah',200000.00,'2026-02-21',''),
(2,6 ,'Ikut'      ,'Sudah',200000.00,'2026-02-21',''),
(2,8 ,'Ikut'      ,'Sudah',200000.00,'2026-02-25',''),
(2,1 ,'Ikut'      ,'Sudah',200000.00,'2026-02-18','Panitia'),
(3,1 ,'Ikut'      ,'Sudah',300000.00,'2025-10-01',''),
(3,2 ,'Ikut'      ,'Sudah',300000.00,'2025-10-02',''),
(3,3 ,'Ikut'      ,'Sudah',300000.00,'2025-10-03',''),
(3,5 ,'Ikut'      ,'Sudah',300000.00,'2025-10-05',''),
(3,7 ,'Batal'     ,'Belum',0.00,NULL,'Acara keluarga mendadak'),
(3,11,'Ikut'      ,'Sudah',300000.00,'2025-10-06',''),
(4,1 ,'Ikut'      ,'Belum',0.00,NULL,'Panitia'),
(4,2 ,'Ikut'      ,'Belum',0.00,NULL,''),
(4,3 ,'Ikut'      ,'Belum',0.00,NULL,''),
(4,4 ,'Ikut'      ,'Belum',0.00,NULL,'');

-- --------------------------------------------------------------------------
-- rundown — outing 1
-- --------------------------------------------------------------------------
INSERT INTO `rundown` (`outing_id`,`urutan`,`hari`,`tanggal`,`waktu_mulai`,`waktu_selesai`,`acara`,`lokasi`,`pic`,`keterangan`) VALUES
(1,1 ,'Hari 1','2026-11-20','05:30','06:00','Registrasi & Kumpul Peserta'   ,'Lobi Kantor Pusat'    ,'Rizky Pratama' ,'Peserta wajib hadir 15 menit sebelum keberangkatan'),
(1,2 ,'Hari 1','2026-11-20','06:00','12:00','Perjalanan ke Yogyakarta'      ,'Bus Pariwisata'       ,'Hendra Gunawan','Snack dibagikan di dalam bus'),
(1,3 ,'Hari 1','2026-11-20','12:00','13:30','Istirahat & Makan Siang'       ,'RM Padang Sederhana'  ,'Siti Nurhaliza','Menu prasmanan'),
(1,4 ,'Hari 1','2026-11-20','13:30','16:00','Check-in Hotel'                ,'Hotel Grand Tjokro'   ,'Rizky Pratama' ,'Pembagian kamar oleh panitia'),
(1,5 ,'Hari 1','2026-11-20','18:30','21:00','Makan Malam & Welcome Party'   ,'Ballroom Hotel'       ,'Maya Anggraini','Dress code: batik'),
(1,6 ,'Hari 2','2026-11-21','06:00','07:30','Sarapan'                       ,'Restoran Hotel'       ,'Siti Nurhaliza',NULL),
(1,7 ,'Hari 2','2026-11-21','08:00','12:00','Outbound & Team Building'      ,'Lapangan Hotel'       ,'Budi Santoso'  ,'Dibagi 5 kelompok, siapkan pakaian olahraga'),
(1,8 ,'Hari 2','2026-11-21','12:00','13:00','Istirahat, Sholat, Makan'      ,'Restoran Hotel'       ,'Siti Nurhaliza',NULL),
(1,9 ,'Hari 2','2026-11-21','13:00','17:00','Wisata Malioboro & Oleh-oleh'  ,'Malioboro'            ,'Lina Kusuma'   ,'Waktu bebas, kumpul pukul 17.00 di titik parkir bus'),
(1,10,'Hari 2','2026-11-21','19:00','22:00','Gala Dinner & Pembagian Doorprize','Ballroom Hotel'    ,'Maya Anggraini','Pengumuman pemenang lomba'),
(1,11,'Hari 3','2026-11-22','07:00','09:00','Sarapan & Check-out'           ,'Hotel Grand Tjokro'   ,'Rizky Pratama' ,'Koper dikumpulkan di lobi'),
(1,12,'Hari 3','2026-11-22','09:00','15:00','Perjalanan Pulang'             ,'Bus Pariwisata'       ,'Hendra Gunawan','Diperkirakan tiba di kantor pukul 15.00');

-- --------------------------------------------------------------------------
-- rundown — outing 2 (history)
-- --------------------------------------------------------------------------
INSERT INTO `rundown` (`outing_id`,`urutan`,`hari`,`tanggal`,`waktu_mulai`,`waktu_selesai`,`acara`,`lokasi`,`pic`,`keterangan`) VALUES
(2,1,'Hari 1','2026-03-14','07:00','09:00','Kumpul & Perjalanan ke Sentul','Kantor Pusat'   ,'Rizky Pratama' ,'Menggunakan 2 minibus'),
(2,2,'Hari 1','2026-03-14','09:00','10:00','Check-in & Welcome Drink'     ,'Resort Sentul'  ,'Siti Nurhaliza',NULL),
(2,3,'Hari 1','2026-03-14','10:00','12:00','Sesi 1: Ice Breaking'         ,'Aula Resort'    ,'Maya Anggraini','Fasilitator internal'),
(2,4,'Hari 1','2026-03-14','13:00','16:00','Sesi 2: Problem Solving Game' ,'Lapangan Rumput'  ,'Budi Santoso'  ,'Lomba antar tim'),
(2,5,'Hari 2','2026-03-15','08:00','10:00','Evaluasi & Rencana Kerja'     ,'Ruang Meeting'    ,'Dewi Lestari'  ,'Output: action plan Q2'),
(2,6,'Hari 2','2026-03-15','10:00','12:00','Check-out & Perjalanan Pulang','Resort Sentul'    ,'Rizky Pratama' ,NULL);

-- --------------------------------------------------------------------------
-- purchases — outing 1 (Active). total_harga = qty * harga_satuan
-- --------------------------------------------------------------------------
INSERT INTO `purchases` (`outing_id`,`category_id`,`nama_barang`,`qty`,`satuan`,`harga_satuan`,`total_harga`,`tanggal_beli`,`bukti_file`,`keterangan`,`created_by`) VALUES
(1,1,'Sewa Bus Pariwisata 50 seat (3 hari)',3.00,'unit' ,4500000.00,13500000.00,'2026-09-05','','Sudah termasuk driver & BBM',1),
(1,1,'Tol & Parkir (estimasi)'            ,1.00,'paket',  750000.00,  750000.00,'2026-09-05','',NULL,1),
(1,3,'Sewa Kamar Hotel (twin sharing)'    ,6.00,'kamar' ,  950000.00, 5700000.00,'2026-09-08','','2 malam, sudah termasuk sarapan',2),
(1,2,'Katering Makan Siang Hari-1'        ,11.00,'pax' ,   45000.00,  495000.00,'2026-09-10','',NULL,2),
(1,2,'Snack Perjalanan'                   ,22.00,'box' ,   15000.00,  330000.00,'2026-09-10','',NULL,2),
(1,5,'Kaos Peserta Outing 2026'           ,11.00,'pcs' ,   85000.00,  935000.00,'2026-09-15','','Ukuran campuran S-XXL',1),
(1,5,'Obat-obatan & P3K'                  ,1.00,'paket',  350000.00,  350000.00,'2026-09-16','',NULL,1),
(1,7,'Doorprize & Hadiah Lomba'           ,1.00,'paket', 1250000.00, 1250000.00,'2026-09-20','','Voucher, tumbler, powerbank',2),
(1,4,'Tiket Masuk Wisata Outbound'        ,11.00,'org' ,  120000.00, 1320000.00,'2026-09-22','',NULL,2),
(1,6,'Jasa Dokumentasi (foto & video)'    ,1.00,'paket', 1500000.00, 1500000.00,'2026-09-25','','Termasuk album cetak',1);

-- --------------------------------------------------------------------------
-- purchases — outing 2 (Selesai)
-- --------------------------------------------------------------------------
INSERT INTO `purchases` (`outing_id`,`category_id`,`nama_barang`,`qty`,`satuan`,`harga_satuan`,`total_harga`,`tanggal_beli`,`bukti_file`,`keterangan`,`created_by`) VALUES
(2,1,'Sewa Minibus 15 seat'              ,2.00,'unit', 1200000.00, 2400000.00,'2026-02-10','',NULL,1),
(2,3,'Paket Meeting Fullboard (2 hari)'  ,5.00,'pax' , 1350000.00, 6750000.00,'2026-02-12','','Termasuk 3x makan & 2x coffee break',2),
(2,5,'Spanduk Kegiatan 3x1 m'            ,1.00,'pcs' ,  150000.00,  150000.00,'2026-02-20','',NULL,1),
(2,7,'Perlengkapan Games & Hadiah'       ,1.00,'paket',  900000.00,  900000.00,'2026-02-25','',NULL,2),
(2,2,'Air Mineral Dus'                   ,6.00,'dus' ,   45000.00,  270000.00,'2026-03-01','',NULL,1),
(2,8,'Biaya Tak Terduga (ban bocor)'     ,1.00,'kali',  250000.00,  250000.00,'2026-03-14','','Dokumentasi tersimpan di folder outing',1);

-- --------------------------------------------------------------------------
-- purchases — outing 3 (Selesai / history)
-- --------------------------------------------------------------------------
INSERT INTO `purchases` (`outing_id`,`category_id`,`nama_barang`,`qty`,`satuan`,`harga_satuan`,`total_harga`,`tanggal_beli`,`bukti_file`,`keterangan`,`created_by`) VALUES
(3,1,'Sewa Bus 3/4 (2 hari)'             ,1.00,'unit', 5500000.00, 5500000.00,'2025-10-10','',NULL,2),
(3,3,'Sewa Cottage Grafika Cikole'       ,4.00,'unit', 1800000.00, 7200000.00,'2025-10-12','',NULL,2),
(3,2,'Paket Makan 3x + Snack'            ,6.00,'pax' ,  375000.00, 2250000.00,'2025-10-15','',NULL,1),
(3,4,'Tiket Masuk & Wahana'              ,12.00,'org' ,  95000.00, 1140000.00,'2025-10-20','',NULL,1),
(3,6,'Fotografer + Cetak Album'          ,1.00,'paket', 1200000.00, 1200000.00,'2025-10-25','',NULL,2);

-- --------------------------------------------------------------------------
-- activity_logs (contoh entri awal)
-- --------------------------------------------------------------------------
INSERT INTO `activity_logs` (`user_id`,`aktivitas`,`tabel_ref`,`ref_id`,`ip_address`) VALUES
(1,'Login berhasil','users',1,'127.0.0.1'),
(1,'Membuat outing OUT-2026-001','outings',1,'127.0.0.1'),
(2,'Login berhasil','users',2,'127.0.0.1');

-- --------------------------------------------------------------------------
-- Reset auto increment agar data baru melanjutkan nomor terakhir
-- --------------------------------------------------------------------------
ALTER TABLE `users`              AUTO_INCREMENT = 13;
ALTER TABLE `outings`            AUTO_INCREMENT = 5;
ALTER TABLE `categories`         AUTO_INCREMENT = 9;
ALTER TABLE `outing_participants` AUTO_INCREMENT = 32;

-- ==========================================================================
-- SELESAI. Silakan login dengan ADMIN001 / admin123 lalu segera ganti password.
-- ==========================================================================
