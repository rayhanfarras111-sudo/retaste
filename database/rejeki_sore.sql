-- =====================================================================
-- REJEKI SORE - Skema Database (versi web) - REVISI 2
-- Target   : MySQL 8.0.16+ / MariaDB 10.4+ (butuh dukungan CHECK constraint)
-- Engine   : InnoDB, karakter utf8mb4
-- Isi      : 1) Skema tabel  2) Pengaturan/kategori/paket langganan awal
--            3) View  4) Tanpa akun, toko, paket, dan transaksi contoh
--            5) Contoh query inti (dalam komentar, untuk dipakai di PHP)
-- Perubahan dari revisi 1:
--   * Tipe listing paket: kejutan atau menu spesifik (+ alergen wajib, jam produksi)
--   * Paket premium UMKM dan pembeli (paket_langganan, langganan, voucher)
--   * Harga mahasiswa lewat verifikasi email kampus
--   * Komisi platform, rincian potongan, dan refund tersimpan di pesanan
--   * Favorit toko, notifikasi dalam web, dan pencatatan paket dilihat (analitik)
-- Catatan  : Jika memakai hosting bersama (phpMyAdmin), buat database
--            lewat panel hosting, pilih database tersebut, lalu Import
--            file ini. Karena itu baris CREATE DATABASE dikomentari.
-- Nilai harga dan batas paket langganan perlu disesuaikan dengan kebijakan layanan.
-- =====================================================================

-- CREATE DATABASE IF NOT EXISTS rejeki_sore
--   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE rejeki_sore;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW  IF EXISTS v_statistik_paket;
DROP VIEW  IF EXISTS v_ringkasan_penjualan_toko;
DROP VIEW  IF EXISTS v_porsi_terselamatkan;
DROP VIEW  IF EXISTS v_paket_tersedia;
DROP TABLE IF EXISTS paket_dilihat;
DROP TABLE IF EXISTS notifikasi;
DROP TABLE IF EXISTS toko_favorit;
DROP TABLE IF EXISTS laporan;
DROP TABLE IF EXISTS ulasan;
DROP TABLE IF EXISTS pesanan;
DROP TABLE IF EXISTS voucher;
DROP TABLE IF EXISTS langganan;
DROP TABLE IF EXISTS paket_langganan;
DROP TABLE IF EXISTS paket;
DROP TABLE IF EXISTS toko;
DROP TABLE IF EXISTS kategori;
DROP TABLE IF EXISTS pengaturan;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- 1. SKEMA TABEL
-- =====================================================================

-- ---------------------------------------------------------------------
-- users : akun untuk semua peran (pembeli, penjual, admin)
-- Status mahasiswa = email_kampus_terverifikasi_pada tidak NULL
-- Status premium TIDAK disimpan di sini, dihitung dari tabel langganan
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id_user                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nama                            VARCHAR(100) NOT NULL,
  email                           VARCHAR(100) NOT NULL,
  no_telp                         VARCHAR(20)  NULL,
  password                        VARCHAR(255) NOT NULL COMMENT 'Hash dari password_hash() PHP, bukan teks asli',
  role                            ENUM('pembeli','penjual','admin') NOT NULL DEFAULT 'pembeli',
  status_akun                     ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  email_kampus                    VARCHAR(100) NULL COMMENT 'Email institusi untuk harga mahasiswa',
  token_email_kampus              CHAR(64) NULL COMMENT 'Token tautan verifikasi, dihapus setelah terverifikasi',
  email_kampus_terverifikasi_pada DATETIME NULL,
  created_at                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_user),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_email_kampus (email_kampus),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- kategori : kategori isi paket (roti, nasi, kue, dll)
-- ---------------------------------------------------------------------
CREATE TABLE kategori (
  id_kategori   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nama          VARCHAR(50) NOT NULL,
  PRIMARY KEY (id_kategori),
  UNIQUE KEY uq_kategori_nama (nama)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- pengaturan : parameter sistem yang bisa diubah admin
-- ---------------------------------------------------------------------
CREATE TABLE pengaturan (
  kunci         VARCHAR(50)  NOT NULL,
  nilai         VARCHAR(255) NOT NULL,
  keterangan    VARCHAR(255) NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (kunci)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- paket_langganan : definisi paket premium (untuk UMKM atau pembeli)
-- Satu tabel dipakai dua sisi; kolom yang tidak relevan diisi NULL
-- ---------------------------------------------------------------------
CREATE TABLE paket_langganan (
  id_paket_langganan   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  untuk                ENUM('umkm','pembeli') NOT NULL,
  nama                 VARCHAR(50) NOT NULL,
  harga                INT UNSIGNED NOT NULL COMMENT 'Rupiah per periode',
  durasi_hari          SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  -- Fitur premium UMKM
  komisi_persen        DECIMAL(4,2) NULL COMMENT 'Komisi platform bagi pelanggan paket ini',
  maks_paket_aktif     SMALLINT UNSIGNED NULL COMMENT 'NULL = tanpa batas',
  boost_homepage       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Tampil di bagian Sorotan Premium',
  badge                TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Badge Toko Premium',
  analitik_lanjutan    TINYINT(1) NOT NULL DEFAULT 0,
  prioritas_verifikasi TINYINT(1) NOT NULL DEFAULT 0,
  -- Fitur premium pembeli
  voucher_per_bulan    TINYINT UNSIGNED NULL,
  nilai_voucher        INT UNSIGNED NULL COMMENT 'Potongan per voucher, rupiah',
  min_belanja_voucher  INT UNSIGNED NULL,
  akses_awal           TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Lihat paket toko favorit sebelum tayang umum',
  aktif                TINYINT(1) NOT NULL DEFAULT 1,
  keterangan           VARCHAR(255) NULL,
  PRIMARY KEY (id_paket_langganan),
  KEY idx_pl_untuk (untuk, aktif),
  CONSTRAINT chk_pl_komisi CHECK (komisi_persen IS NULL OR komisi_persen BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- toko : profil toko penjual (satu penjual satu toko)
-- ---------------------------------------------------------------------
CREATE TABLE toko (
  id_toko              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_user              INT UNSIGNED NOT NULL COMMENT 'Pemilik toko (role penjual)',
  nama_toko            VARCHAR(100) NOT NULL,
  kategori_usaha       ENUM('toko_roti','warung','katering','kafe','restoran','lainnya') NOT NULL DEFAULT 'lainnya',
  alamat               TEXT NOT NULL,
  lat                  DECIMAL(10,7) NOT NULL COMMENT 'Lintang, dipakai menghitung jarak (Haversine)',
  lng                  DECIMAL(10,7) NOT NULL COMMENT 'Bujur',
  jam_buka             TIME NOT NULL,
  jam_tutup            TIME NOT NULL,
  no_telp              VARCHAR(20) NOT NULL,
  foto                 VARCHAR(255) NOT NULL COMMENT 'Nama file acak hasil unggah',
  status               ENUM('menunggu','aktif','ditolak','ditangguhkan') NOT NULL DEFAULT 'menunggu',
  catatan_admin        TEXT NULL COMMENT 'Alasan penolakan atau penangguhan',
  diverifikasi_oleh    INT UNSIGNED NULL,
  diverifikasi_pada    DATETIME NULL,
  rating_rata          DECIMAL(3,2) NOT NULL DEFAULT 0.00 COMMENT 'Diperbarui setiap ada ulasan baru',
  jumlah_ulasan        INT UNSIGNED NOT NULL DEFAULT 0,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_toko),
  UNIQUE KEY uq_toko_user (id_user),
  KEY idx_toko_status (status),
  KEY idx_toko_lokasi (lat, lng),
  CONSTRAINT fk_toko_user      FOREIGN KEY (id_user)           REFERENCES users (id_user) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_toko_verif     FOREIGN KEY (diverifikasi_oleh) REFERENCES users (id_user) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_toko_lat      CHECK (lat BETWEEN -90 AND 90),
  CONSTRAINT chk_toko_lng      CHECK (lng BETWEEN -180 AND 180),
  CONSTRAINT chk_toko_rating   CHECK (rating_rata BETWEEN 0 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- paket : paket makanan sisa yang diposting penjual
-- tipe_listing = 'kejutan' : isi tidak pasti. nama_paket contoh "Paket Kejutan Roti",
--                            harga_normal = nilai normal MINIMAL isi paket,
--                            deskripsi = contoh isi (opsional), foto = foto contoh
-- tipe_listing = 'menu'    : satu nama menu + jumlah porsi, foto = foto menu asli
-- ---------------------------------------------------------------------
CREATE TABLE paket (
  id_paket          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_toko           INT UNSIGNED NOT NULL,
  id_kategori       INT UNSIGNED NOT NULL COMMENT 'Kategori isi (wajib terlihat pembeli, juga untuk paket kejutan)',
  tipe_listing      ENUM('kejutan','menu') NOT NULL DEFAULT 'kejutan',
  nama_paket        VARCHAR(100) NOT NULL,
  deskripsi         TEXT NULL COMMENT 'Kejutan: contoh isi (opsional). Menu: keterangan menu',
  jumlah_porsi      SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Porsi dalam satu paket (terutama tipe menu)',
  harga_normal      INT UNSIGNED NOT NULL COMMENT 'Rupiah. Tipe kejutan: nilai normal minimal',
  harga_jual        INT UNSIGNED NOT NULL COMMENT 'Harga Rejeki Sore, rupiah',
  stok_awal         INT UNSIGNED NOT NULL,
  stok_sisa         INT UNSIGNED NOT NULL,
  tanggal_ambil     DATE NOT NULL,
  jam_mulai         TIME NOT NULL,
  jam_selesai       TIME NOT NULL,
  jam_produksi      TIME NOT NULL COMMENT 'Label jam produksi yang tampil ke pembeli',
  foto              VARCHAR(255) NOT NULL,
  alergen           SET('susu','telur','kacang','gluten','kedelai','seafood','tidak_ada') NOT NULL
                    COMMENT 'Ceklis alergen wajib; pilih tidak_ada jika memang tidak ada',
  catatan_alergen   VARCHAR(255) NULL,
  pernyataan_layak  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = penjual menyatakan layak konsumsi dan dibuat hari yang sama',
  tayang_umum_pada  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu paket tampil untuk umum; pembeli premium dengan akses awal bisa melihat lebih dulu',
  status            ENUM('tersedia','habis','ditutup','ditangguhkan') NOT NULL DEFAULT 'tersedia',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_paket),
  KEY idx_paket_toko (id_toko),
  KEY idx_paket_kategori (id_kategori),
  KEY idx_paket_cari (status, tanggal_ambil, jam_selesai),
  CONSTRAINT fk_paket_toko     FOREIGN KEY (id_toko)     REFERENCES toko (id_toko)         ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_paket_kategori FOREIGN KEY (id_kategori) REFERENCES kategori (id_kategori) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_paket_harga   CHECK (harga_jual < harga_normal),
  CONSTRAINT chk_paket_stok    CHECK (stok_awal >= 1 AND stok_sisa <= stok_awal),
  CONSTRAINT chk_paket_porsi   CHECK (jumlah_porsi >= 1),
  CONSTRAINT chk_paket_jam     CHECK (jam_selesai > jam_mulai),
  CONSTRAINT chk_paket_alergen CHECK (alergen <> ''),
  CONSTRAINT chk_paket_layak   CHECK (pernyataan_layak = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Catatan: diskon minimal (pengaturan.diskon_minimal_persen) dan batas paket aktif
-- penjual gratis (pengaturan.maks_paket_aktif_gratis) divalidasi di PHP.

-- ---------------------------------------------------------------------
-- langganan : riwayat berlangganan premium (UMKM atau pembeli)
-- Langganan 'aktif' = status aktif DAN berakhir_pada > NOW()
-- Setelah berakhir, akun otomatis kembali ke gratis
-- ---------------------------------------------------------------------
CREATE TABLE langganan (
  id_langganan        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_user             INT UNSIGNED NOT NULL COMMENT 'Penjual (premium toko) atau pembeli',
  id_paket_langganan  INT UNSIGNED NOT NULL,
  status              ENUM('menunggu_pembayaran','aktif','berakhir','dibatalkan') NOT NULL DEFAULT 'menunggu_pembayaran',
  harga_dibayar       INT UNSIGNED NOT NULL COMMENT 'Salinan harga saat berlangganan',
  metode_bayar        VARCHAR(30) NULL,
  waktu_bayar         DATETIME NULL,
  mulai_pada          DATETIME NULL,
  berakhir_pada       DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_langganan),
  KEY idx_langganan_user (id_user, status, berakhir_pada),
  CONSTRAINT fk_langganan_user  FOREIGN KEY (id_user)            REFERENCES users (id_user)                      ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_langganan_paket FOREIGN KEY (id_paket_langganan) REFERENCES paket_langganan (id_paket_langganan) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- voucher : voucher potongan harga untuk pembeli premium
-- Satu baris = satu voucher sekali pakai milik satu pembeli.
-- Potongan voucher ditanggung PLATFORM (usulan), bukan penjual.
-- ---------------------------------------------------------------------
CREATE TABLE voucher (
  id_voucher      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_user         INT UNSIGNED NOT NULL COMMENT 'Pemilik voucher',
  id_langganan    INT UNSIGNED NOT NULL COMMENT 'Langganan yang menerbitkan',
  kode            CHAR(8) NOT NULL,
  nilai_potongan  INT UNSIGNED NOT NULL COMMENT 'Rupiah',
  min_belanja     INT UNSIGNED NOT NULL DEFAULT 0,
  berlaku_sampai  DATETIME NOT NULL COMMENT 'Tetap berlaku sampai tanggal ini walau langganan berakhir',
  status          ENUM('tersedia','terpakai','kedaluwarsa') NOT NULL DEFAULT 'tersedia',
  dipakai_pada    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_voucher),
  UNIQUE KEY uq_voucher_kode (kode),
  KEY idx_voucher_user (id_user, status, berlaku_sampai),
  CONSTRAINT fk_voucher_user      FOREIGN KEY (id_user)      REFERENCES users (id_user)           ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_voucher_langganan FOREIGN KEY (id_langganan) REFERENCES langganan (id_langganan) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_voucher_nilai    CHECK (nilai_potongan > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- pesanan : transaksi pembeli atas sebuah paket
-- Rumus (dihitung SERVER, jangan percaya angka dari form):
--   subtotal       = jumlah x harga_satuan
--   ppn_nominal    = pembulatan(harga_satuan x 11 / 100) x jumlah
--   total_bayar    = subtotal + ppn_nominal - potongan_mahasiswa - potongan_voucher
--   komisi_nominal = subtotal x komisi_persen / 100   (dari subtotal, bukan total_bayar,
--                    karena voucher ditanggung platform)
--   pendapatan penjual = subtotal - komisi_nominal
-- ---------------------------------------------------------------------
CREATE TABLE pesanan (
  id_pesanan          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kode_pesanan        VARCHAR(20) NOT NULL COMMENT 'Kode tampil, contoh RS-20261024-0001',
  id_pembeli          INT UNSIGNED NOT NULL,
  id_paket            INT UNSIGNED NOT NULL,
  id_voucher          INT UNSIGNED NULL COMMENT 'Maksimal satu voucher per pesanan',
  jumlah              TINYINT UNSIGNED NOT NULL DEFAULT 1,
  harga_satuan        INT UNSIGNED NOT NULL COMMENT 'Salinan harga_jual saat memesan',
  subtotal            INT UNSIGNED NOT NULL,
  potongan_mahasiswa  INT UNSIGNED NOT NULL DEFAULT 0,
  potongan_voucher    INT UNSIGNED NOT NULL DEFAULT 0,
  ppn_nominal         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'PPN 11% dari subtotal, masuk ke admin',
  total_bayar         INT UNSIGNED NOT NULL,
  komisi_persen       DECIMAL(4,2) NOT NULL DEFAULT 0.00 COMMENT 'Salinan komisi saat pesanan dibuat',
  komisi_nominal      INT UNSIGNED NOT NULL DEFAULT 0,
  status              ENUM('menunggu_pembayaran','siap_diambil','selesai','dibatalkan','kedaluwarsa','tidak_diambil')
                      NOT NULL DEFAULT 'menunggu_pembayaran',
  batas_bayar         DATETIME NOT NULL,
  metode_bayar        VARCHAR(30) NULL COMMENT 'Terisi saat membayar (simulasi atau transfer manual)',
  waktu_bayar         DATETIME NULL,
  kode_pengambilan    CHAR(6) NULL COMMENT 'Dibuat saat pembayaran berhasil, sekali pakai. Isi QR = kode ini',
  waktu_selesai       DATETIME NULL COMMENT 'Waktu makanan diambil',
  dibatalkan_oleh     ENUM('pembeli','penjual','admin','sistem') NULL,
  alasan_batal        VARCHAR(255) NULL,
  status_refund       ENUM('tidak_ada','diproses','selesai') NOT NULL DEFAULT 'tidak_ada' COMMENT 'Simulasi',
  nominal_refund      INT UNSIGNED NOT NULL DEFAULT 0,
  waktu_refund        DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_pesanan),
  UNIQUE KEY uq_pesanan_kode (kode_pesanan),
  UNIQUE KEY uq_pesanan_kode_ambil (kode_pengambilan),
  KEY idx_pesanan_pembeli (id_pembeli, status),
  KEY idx_pesanan_paket (id_paket, status),
  KEY idx_pesanan_voucher (id_voucher),
  KEY idx_pesanan_batas (status, batas_bayar),
  CONSTRAINT fk_pesanan_pembeli FOREIGN KEY (id_pembeli) REFERENCES users (id_user)      ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_pesanan_paket   FOREIGN KEY (id_paket)   REFERENCES paket (id_paket)     ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_pesanan_voucher FOREIGN KEY (id_voucher) REFERENCES voucher (id_voucher) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_pesanan_jumlah CHECK (jumlah >= 1),
  CONSTRAINT chk_pesanan_total  CHECK (total_bayar + potongan_mahasiswa + potongan_voucher = subtotal + ppn_nominal),
  CONSTRAINT chk_pesanan_komisi CHECK (komisi_nominal <= subtotal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Catatan: bila pesanan berbayar voucher dibatalkan/kedaluwarsa, aplikasi mengembalikan
-- voucher.status ke 'tersedia' (selama belum lewat berlaku_sampai). id_voucher tidak dibuat UNIQUE
-- agar voucher yang dikembalikan bisa dipakai lagi.

-- ---------------------------------------------------------------------
-- ulasan : rating dan komentar, satu ulasan per pesanan selesai
-- ---------------------------------------------------------------------
CREATE TABLE ulasan (
  id_ulasan     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_pesanan    INT UNSIGNED NOT NULL,
  id_toko       INT UNSIGNED NOT NULL,
  id_pembeli    INT UNSIGNED NOT NULL,
  rating        TINYINT UNSIGNED NOT NULL,
  komentar      TEXT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_ulasan),
  UNIQUE KEY uq_ulasan_pesanan (id_pesanan),
  KEY idx_ulasan_toko (id_toko),
  CONSTRAINT fk_ulasan_pesanan FOREIGN KEY (id_pesanan) REFERENCES pesanan (id_pesanan) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_ulasan_toko    FOREIGN KEY (id_toko)    REFERENCES toko (id_toko)       ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_ulasan_pembeli FOREIGN KEY (id_pembeli) REFERENCES users (id_user)      ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_ulasan_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Catatan: syarat "hanya pesanan berstatus selesai" divalidasi di PHP.

-- ---------------------------------------------------------------------
-- laporan : laporan masalah dari pembeli untuk ditangani admin
-- ---------------------------------------------------------------------
CREATE TABLE laporan (
  id_laporan      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_pesanan      INT UNSIGNED NOT NULL,
  id_pelapor      INT UNSIGNED NOT NULL,
  jenis           ENUM('isi_tidak_sesuai','tidak_layak','alergen','penjual_tidak_melayani','lainnya') NOT NULL DEFAULT 'lainnya'
                  COMMENT 'isi_tidak_sesuai = isi tidak sesuai kategori/nilai (kejutan) atau foto/deskripsi (menu)',
  alasan          TEXT NOT NULL,
  foto            VARCHAR(255) NULL COMMENT 'Opsional',
  status          ENUM('baru','diproses','selesai') NOT NULL DEFAULT 'baru',
  tindakan_admin  ENUM('abaikan','peringatan','tangguhkan_paket','tangguhkan_toko') NULL,
  catatan_admin   TEXT NULL,
  id_admin        INT UNSIGNED NULL COMMENT 'Admin yang menangani',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ditangani_pada  DATETIME NULL,
  PRIMARY KEY (id_laporan),
  KEY idx_laporan_status (status),
  KEY idx_laporan_pesanan (id_pesanan),
  CONSTRAINT fk_laporan_pesanan FOREIGN KEY (id_pesanan) REFERENCES pesanan (id_pesanan) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_laporan_pelapor FOREIGN KEY (id_pelapor) REFERENCES users (id_user)      ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_laporan_admin   FOREIGN KEY (id_admin)   REFERENCES users (id_user)      ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- toko_favorit : pembeli mengikuti toko (untuk notifikasi dan akses awal)
-- ---------------------------------------------------------------------
CREATE TABLE toko_favorit (
  id_user     INT UNSIGNED NOT NULL,
  id_toko     INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_user, id_toko),
  KEY idx_favorit_toko (id_toko),
  CONSTRAINT fk_favorit_user FOREIGN KEY (id_user) REFERENCES users (id_user) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_favorit_toko FOREIGN KEY (id_toko) REFERENCES toko (id_toko)  ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- notifikasi : pusat notifikasi di dalam web
-- ---------------------------------------------------------------------
CREATE TABLE notifikasi (
  id_notifikasi  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_user        INT UNSIGNED NOT NULL COMMENT 'Penerima',
  tipe           ENUM('status_pesanan','paket_baru','siaran_toko','voucher','sistem') NOT NULL,
  judul          VARCHAR(100) NOT NULL,
  isi            VARCHAR(255) NOT NULL,
  tautan         VARCHAR(255) NULL,
  dibaca         TINYINT(1) NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_notifikasi),
  KEY idx_notif_user (id_user, dibaca, created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (id_user) REFERENCES users (id_user) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- paket_dilihat : catatan tiap paket dibuka (bahan analitik lanjutan penjual)
-- ---------------------------------------------------------------------
CREATE TABLE paket_dilihat (
  id_dilihat   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_paket     INT UNSIGNED NOT NULL,
  id_user      INT UNSIGNED NULL COMMENT 'NULL = pengunjung belum login',
  dilihat_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_dilihat),
  KEY idx_dilihat_paket (id_paket, dilihat_pada),
  CONSTRAINT fk_dilihat_paket FOREIGN KEY (id_paket) REFERENCES paket (id_paket) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_dilihat_user  FOREIGN KEY (id_user)  REFERENCES users (id_user)  ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 2. DATA AWAL (wajib ada agar sistem berjalan)
-- =====================================================================

INSERT INTO pengaturan (kunci, nilai, keterangan) VALUES
  ('pajak_persen',               '11',   'PPN tetap 11% dari harga jual, dibayar pembeli dan dicatat untuk admin'),
  ('radius_km',                  '3',    'Radius pencarian paket bawaan (km). Lingkup awal satu area kampus'),
  ('diskon_minimal_persen',      '50',   'Diskon minimal harga Rejeki Sore dari harga normal (usulan)'),
  ('batas_bayar_menit',          '15',   'Batas waktu pembayaran sejak pesanan dibuat'),
  ('maks_per_pembeli',           '2',    'Maksimal jumlah satu paket per pembeli'),
  ('tutup_pesan_menit_sebelum',  '10',   'Pemesanan ditutup sekian menit sebelum jam ambil berakhir'),
  ('maks_ukuran_foto_kb',        '2048', 'Ukuran maksimal unggah foto (KB)'),
  ('komisi_standar_persen',      '10',   'Komisi platform untuk penjual gratis (angka dari makalah Tugas 2)'),
  ('maks_paket_aktif_gratis',    '2',    'Batas paket aktif bagi penjual gratis (contoh) - PLACEHOLDER'),
  ('diskon_mahasiswa_persen',    '5',    'Diskon tambahan otomatis bagi akun mahasiswa terverifikasi - PLACEHOLDER'),
  ('maks_total_potongan_persen', '30',   'Batas total potongan mahasiswa + voucher dari subtotal (usulan) - PLACEHOLDER'),
  ('tunda_tayang_umum_menit',    '5',    'Paket tampil untuk umum sekian menit setelah diposting; pembeli premium bisa lebih awal - PLACEHOLDER'),
  ('jumlah_sorotan_beranda',     '4',    'Banyak paket premium di bagian Sorotan (dirotasi agar adil)'),
  ('kebijakan_tidak_diambil',    'tanpa_refund', 'Usulan: pembeli yang tidak datang tidak mendapat refund, penjual tetap menerima pembayaran'),
  ('refund_batal_penjual',       'penuh', 'Pembatalan oleh penjual atau admin: refund penuh (simulasi)');

INSERT INTO kategori (nama) VALUES
  ('Roti & Kue'),
  ('Nasi & Lauk'),
  ('Minuman'),
  ('Camilan'),
  ('Paket Campur');

-- Harga langganan belum ada di makalah Tugas 2. Nilai harga = 0 dan voucher di bawah
-- adalah PLACEHOLDER: ganti sesuai bagian Pricing sebelum demo.
INSERT INTO paket_langganan
  (id_paket_langganan, untuk, nama, harga, komisi_persen, maks_paket_aktif, boost_homepage, badge,
   analitik_lanjutan, prioritas_verifikasi, voucher_per_bulan, nilai_voucher, min_belanja_voucher, akses_awal, keterangan)
VALUES
  (1, 'umkm',    'Rejeki Pro',  0, 6.00, NULL, 1, 1, 1, 1, NULL, NULL, NULL, 0,
   'Boost homepage, badge, kuota paket besar, analitik lanjutan, komisi lebih rendah, verifikasi prioritas'),
  (2, 'pembeli', 'Rejeki Plus', 0, NULL, NULL, 0, 0, 0, 0, 3, 5000, 10000, 1,
   'Jatah voucher bulanan dan akses awal ke paket toko favorit');

-- =====================================================================
-- 3. VIEW (memudahkan query di PHP)
-- =====================================================================

-- Paket yang boleh tampil di beranda: toko aktif, stok ada, hari ini, jam ambil belum lewat.
-- Filter waktu tayang umum DITAMBAHKAN di PHP: tayang_umum_pada <= NOW()
-- atau (pembeli premium akses awal DAN toko ada di favoritnya).
CREATE VIEW v_paket_tersedia AS
SELECT
  p.id_paket, p.tipe_listing, p.nama_paket, p.deskripsi, p.jumlah_porsi,
  p.harga_normal, p.harga_jual, (p.harga_normal - p.harga_jual) AS nominal_hemat,
  p.stok_sisa, p.tanggal_ambil, p.jam_mulai, p.jam_selesai, p.jam_produksi,
  p.foto, p.alergen, p.catatan_alergen, p.tayang_umum_pada,
  k.id_kategori, k.nama AS nama_kategori,
  t.id_toko, t.nama_toko, t.alamat, t.lat, t.lng, t.rating_rata, t.jumlah_ulasan,
  EXISTS (
    SELECT 1 FROM langganan l
    JOIN paket_langganan pl ON pl.id_paket_langganan = l.id_paket_langganan
    WHERE l.id_user = t.id_user AND pl.untuk = 'umkm'
      AND l.status = 'aktif' AND l.berakhir_pada > NOW()
  ) AS toko_premium
FROM paket p
JOIN toko t     ON t.id_toko = p.id_toko
JOIN kategori k ON k.id_kategori = p.id_kategori
WHERE p.status = 'tersedia'
  AND p.stok_sisa > 0
  AND t.status = 'aktif'
  AND p.tanggal_ambil = CURDATE()
  AND CURTIME() < p.jam_selesai;

-- Porsi terselamatkan = jumlah paket pada pesanan selesai (per toko)
CREATE VIEW v_porsi_terselamatkan AS
SELECT
  t.id_toko, t.nama_toko,
  COALESCE(SUM(o.jumlah), 0) AS porsi_terselamatkan
FROM toko t
LEFT JOIN paket p   ON p.id_toko = t.id_toko
LEFT JOIN pesanan o ON o.id_paket = p.id_paket AND o.status = 'selesai'
GROUP BY t.id_toko, t.nama_toko;

-- Penjualan toko per hari (berdasarkan waktu pengambilan selesai).
-- Pendapatan penjual = subtotal - komisi (voucher dan diskon mahasiswa tidak mengurangi,
-- karena potongan ditanggung platform)
CREATE VIEW v_ringkasan_penjualan_toko AS
SELECT
  p.id_toko,
  DATE(o.waktu_selesai)               AS tanggal,
  COUNT(o.id_pesanan)                 AS jumlah_pesanan,
  SUM(o.jumlah)                       AS paket_terjual,
  SUM(o.subtotal)                     AS penjualan_kotor,
  SUM(o.komisi_nominal)               AS komisi_platform,
  SUM(o.subtotal - o.komisi_nominal)  AS pendapatan_bersih
FROM pesanan o
JOIN paket p ON p.id_paket = o.id_paket
WHERE o.status = 'selesai'
GROUP BY p.id_toko, DATE(o.waktu_selesai);

-- Statistik per paket untuk analitik lanjutan (dilihat, dipesan, rasio)
CREATE VIEW v_statistik_paket AS
SELECT
  p.id_paket, p.id_toko, p.nama_paket, p.tanggal_ambil,
  (SELECT COUNT(*) FROM paket_dilihat d WHERE d.id_paket = p.id_paket) AS jumlah_dilihat,
  (SELECT COALESCE(SUM(o.jumlah), 0) FROM pesanan o
    WHERE o.id_paket = p.id_paket AND o.status IN ('siap_diambil','selesai','tidak_diambil')) AS jumlah_dipesan,
  p.stok_awal, p.stok_sisa
FROM paket p;

-- =====================================================================
-- 4. DATA OPERASIONAL
-- Tidak ada akun, toko, paket, transaksi, ulasan, atau data aktivitas contoh.
-- Akun dibuat melalui registrasi; data lain dibuat melalui aplikasi.
-- =====================================================================

-- =====================================================================
-- 5. CONTOH QUERY INTI (referensi untuk PHP, semuanya dikomentari)
--    Gunakan prepared statement; tanda ? adalah parameter.
-- =====================================================================

-- 5.1 Beranda, bagian "Sorotan Premium": hanya toko premium, tersedia, dalam radius,
--     sudah tayang untuk umum, dirotasi acak agar adil. Setelahnya baru daftar biasa.
--     Parameter: ?lat, ?lng, ?lat, ?radius_km, ?jumlah_sorotan
-- SELECT v.*,
--   (6371 * ACOS(LEAST(1,
--        COS(RADIANS(?)) * COS(RADIANS(v.lat)) * COS(RADIANS(v.lng) - RADIANS(?))
--      + SIN(RADIANS(?)) * SIN(RADIANS(v.lat))))) AS jarak_km
-- FROM v_paket_tersedia v
-- WHERE v.toko_premium = 1 AND v.tayang_umum_pada <= NOW()
-- HAVING jarak_km <= ?
-- ORDER BY RAND()
-- LIMIT ?;

-- 5.2 Beranda, daftar biasa terdekat. Akses awal: tampilkan juga paket yang belum tayang umum
--     jika pembeli punya langganan Plus aktif DAN toko ada di favoritnya.
--     Parameter: ?lat, ?lng, ?lat, ?id_user, ?id_user, ?radius_km
-- SELECT v.*,
--   (6371 * ACOS(LEAST(1,
--        COS(RADIANS(?)) * COS(RADIANS(v.lat)) * COS(RADIANS(v.lng) - RADIANS(?))
--      + SIN(RADIANS(?)) * SIN(RADIANS(v.lat))))) AS jarak_km
-- FROM v_paket_tersedia v
-- WHERE v.tayang_umum_pada <= NOW()
--    OR ( EXISTS (SELECT 1 FROM toko_favorit f WHERE f.id_user = ? AND f.id_toko = v.id_toko)
--         AND EXISTS (SELECT 1 FROM langganan l JOIN paket_langganan pl ON pl.id_paket_langganan = l.id_paket_langganan
--                      WHERE l.id_user = ? AND l.status = 'aktif' AND l.berakhir_pada > NOW() AND pl.akses_awal = 1) )
-- HAVING jarak_km <= ?
-- ORDER BY jarak_km ASC;

-- 5.3 Membuat pesanan dengan stok atomik dan voucher (satu transaksi).
--     PHP menghitung: subtotal = jumlah x harga_satuan; potongan_mahasiswa (jika email kampus
--     terverifikasi); potongan_voucher; total potongan dibatasi maks_total_potongan_persen;
--     komisi_persen dari langganan toko (6) atau pengaturan komisi_standar_persen (10).
--     Jika affected rows = 0 pada langkah 3 (stok habis) atau langkah 2 (voucher tidak sah): ROLLBACK.
-- START TRANSACTION;
--   -- 1) cek batas per pembeli:
--   -- SELECT COALESCE(SUM(jumlah),0) FROM pesanan
--   --  WHERE id_pembeli = ? AND id_paket = ? AND status IN ('menunggu_pembayaran','siap_diambil','selesai');
--   -- 2) kunci voucher (jika dipakai) dan pastikan sah:
--   -- UPDATE voucher SET status = 'terpakai', dipakai_pada = NOW()
--   --  WHERE id_voucher = ? AND id_user = ? AND status = 'tersedia'
--   --    AND berlaku_sampai > NOW() AND min_belanja <= ?;   -- ? = subtotal
--   -- 3) tahan stok:
--   UPDATE paket
--      SET stok_sisa = stok_sisa - ?,
--          status = IF(stok_sisa = 0, 'habis', status)  -- MySQL memakai nilai stok_sisa yang sudah dikurangi
--    WHERE id_paket = ? AND status = 'tersedia' AND stok_sisa >= ?
--      AND tanggal_ambil = CURDATE() AND CURTIME() < jam_selesai;
--   -- 4) bila affected rows = 1, buat pesanan:
--   INSERT INTO pesanan (kode_pesanan, id_pembeli, id_paket, id_voucher, jumlah, harga_satuan, subtotal,
--                        potongan_mahasiswa, potongan_voucher, total_bayar, komisi_persen, komisi_nominal, batas_bayar)
--   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE));
-- COMMIT;

-- 5.4 Pembayaran berhasil: ubah status dan simpan kode pengambilan
-- UPDATE pesanan
--    SET status = 'siap_diambil', waktu_bayar = NOW(), metode_bayar = ?, kode_pengambilan = ?
--  WHERE id_pesanan = ? AND id_pembeli = ? AND status = 'menunggu_pembayaran' AND batas_bayar >= NOW();

-- 5.5 Pesanan kedaluwarsa: kembalikan stok dan voucher (satu transaksi; jalankan tiap halaman
--     terkait dibuka, atau lewat cron). Voucher yang sudah lewat masa berlaku tidak dikembalikan.
-- START TRANSACTION;
--   UPDATE paket p
--   JOIN (SELECT id_paket, SUM(jumlah) AS qty FROM pesanan
--          WHERE status = 'menunggu_pembayaran' AND batas_bayar < NOW()
--          GROUP BY id_paket) x ON x.id_paket = p.id_paket
--      SET p.stok_sisa = p.stok_sisa + x.qty,
--          p.status = IF(p.status = 'habis' AND p.tanggal_ambil = CURDATE() AND CURTIME() < p.jam_selesai, 'tersedia', p.status);
--   UPDATE voucher v
--   JOIN pesanan o ON o.id_voucher = v.id_voucher
--      SET v.status = 'tersedia', v.dipakai_pada = NULL
--    WHERE o.status = 'menunggu_pembayaran' AND o.batas_bayar < NOW()
--      AND v.status = 'terpakai' AND v.berlaku_sampai > NOW();
--   UPDATE pesanan
--      SET status = 'kedaluwarsa', dibatalkan_oleh = 'sistem', alasan_batal = 'Melewati batas pembayaran'
--    WHERE status = 'menunggu_pembayaran' AND batas_bayar < NOW();
-- COMMIT;

-- 5.6 Tutup paket yang jam ambilnya berakhir, dan tandai pesanan tidak diambil
--     (kebijakan_tidak_diambil = tanpa_refund, jadi status_refund tetap tidak_ada)
-- UPDATE pesanan o JOIN paket p ON p.id_paket = o.id_paket
--    SET o.status = 'tidak_diambil'
--  WHERE o.status = 'siap_diambil'
--    AND (p.tanggal_ambil < CURDATE() OR (p.tanggal_ambil = CURDATE() AND p.jam_selesai < CURTIME()));
-- UPDATE paket SET status = 'ditutup'
--  WHERE status IN ('tersedia','habis')
--    AND (tanggal_ambil < CURDATE() OR (tanggal_ambil = CURDATE() AND jam_selesai < CURTIME()));

-- 5.7 Penjual memverifikasi kode pengambilan (hasil scan QR atau ketik 6 karakter)
-- UPDATE pesanan o JOIN paket p ON p.id_paket = o.id_paket
--    SET o.status = 'selesai', o.waktu_selesai = NOW()
--  WHERE o.kode_pengambilan = ? AND o.status = 'siap_diambil' AND p.id_toko = ?
--    AND p.tanggal_ambil = CURDATE() AND CURTIME() BETWEEN p.jam_mulai AND p.jam_selesai;

-- 5.8 Pembatalan oleh penjual atau admin setelah bayar: refund penuh (simulasi)
-- UPDATE pesanan
--    SET status = 'dibatalkan', dibatalkan_oleh = ?, alasan_batal = ?,
--        status_refund = 'selesai', nominal_refund = total_bayar, waktu_refund = NOW()
--  WHERE id_pesanan = ? AND status = 'siap_diambil';
-- (lalu kembalikan stok paket dan voucher seperti 5.5)

-- 5.9 Langganan dan voucher berakhir otomatis
-- UPDATE langganan SET status = 'berakhir' WHERE status = 'aktif' AND berakhir_pada < NOW();
-- UPDATE voucher   SET status = 'kedaluwarsa' WHERE status = 'tersedia' AND berlaku_sampai < NOW();

-- 5.10 Menerbitkan voucher bulanan saat langganan Plus aktif / awal periode baru
--      (PHP mengulang INSERT sebanyak voucher_per_bulan; kode acak 8 karakter)
-- INSERT INTO voucher (id_user, id_langganan, kode, nilai_potongan, min_belanja, berlaku_sampai)
-- SELECT l.id_user, l.id_langganan, ?, pl.nilai_voucher, pl.min_belanja_voucher, DATE_ADD(NOW(), INTERVAL 30 DAY)
--   FROM langganan l JOIN paket_langganan pl ON pl.id_paket_langganan = l.id_paket_langganan
--  WHERE l.id_langganan = ? AND l.status = 'aktif';

-- 5.11 Batas paket aktif penjual gratis (cek sebelum menyimpan paket baru)
-- SELECT COUNT(*) FROM paket WHERE id_toko = ? AND status IN ('tersedia','habis')
--    AND tanggal_ambil = CURDATE();
-- bandingkan dengan pengaturan.maks_paket_aktif_gratis jika toko tidak punya langganan aktif.

-- 5.12 Siaran toko premium ke pengikut (pembeli yang memfavoritkan toko)
-- INSERT INTO notifikasi (id_user, tipe, judul, isi, tautan)
-- SELECT f.id_user, 'paket_baru', ?, ?, ?
--   FROM toko_favorit f WHERE f.id_toko = ?;

-- 5.13 Rating toko dan penghitung porsi terselamatkan
-- UPDATE toko t
--    SET t.rating_rata = (SELECT ROUND(AVG(rating), 2) FROM ulasan WHERE id_toko = t.id_toko),
--        t.jumlah_ulasan = (SELECT COUNT(*) FROM ulasan WHERE id_toko = t.id_toko)
--  WHERE t.id_toko = ?;
-- SELECT COALESCE(SUM(jumlah), 0) AS total_porsi FROM pesanan WHERE status = 'selesai';

-- 5.14 Dashboard penjual: pendapatan bersih hari ini, minggu ini, bulan ini
-- SELECT
--   COALESCE(SUM(CASE WHEN DATE(o.waktu_selesai) = CURDATE() THEN o.subtotal - o.komisi_nominal END), 0) AS hari_ini,
--   COALESCE(SUM(CASE WHEN YEARWEEK(o.waktu_selesai, 1) = YEARWEEK(CURDATE(), 1) THEN o.subtotal - o.komisi_nominal END), 0) AS minggu_ini,
--   COALESCE(SUM(CASE WHEN YEAR(o.waktu_selesai) = YEAR(CURDATE()) AND MONTH(o.waktu_selesai) = MONTH(CURDATE()) THEN o.subtotal - o.komisi_nominal END), 0) AS bulan_ini
-- FROM pesanan o JOIN paket p ON p.id_paket = o.id_paket
-- WHERE o.status = 'selesai' AND p.id_toko = ?;

-- 5.15 Analitik lanjutan (premium): paket terlaris dan jam ramai
-- SELECT * FROM v_statistik_paket WHERE id_toko = ? ORDER BY jumlah_dipesan DESC;
-- SELECT HOUR(waktu_bayar) AS jam, COUNT(*) AS jumlah_pesanan
--   FROM pesanan o JOIN paket p ON p.id_paket = o.id_paket
--  WHERE p.id_toko = ? AND o.waktu_bayar IS NOT NULL GROUP BY HOUR(waktu_bayar) ORDER BY jam;

-- 5.16 Menangguhkan toko: tutup paket aktif dan batalkan pesanan aktif (satu transaksi,
--      lalu kembalikan voucher dan catat refund seperti 5.8)
-- UPDATE toko SET status = 'ditangguhkan', catatan_admin = ? WHERE id_toko = ?;
-- UPDATE paket SET status = 'ditangguhkan' WHERE id_toko = ? AND status IN ('tersedia','habis');
-- UPDATE pesanan o JOIN paket p ON p.id_paket = o.id_paket
--    SET o.status = 'dibatalkan', o.dibatalkan_oleh = 'admin', o.alasan_batal = 'Toko ditangguhkan'
--  WHERE p.id_toko = ? AND o.status IN ('menunggu_pembayaran','siap_diambil');

-- =====================================================================
-- SELESAI
-- =====================================================================
