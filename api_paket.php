<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Jakarta');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function packageRow(array $row): array
{
    return [
        'id' => (int)$row['id_paket'],
        'tipe' => $row['tipe_listing'],
        'nama' => $row['nama_paket'],
        'deskripsi' => $row['deskripsi'] ?? '',
        'kategori' => (int)$row['id_kategori'],
        'toko_id' => (int)$row['id_toko'],
        'toko' => $row['nama_toko'],
        'alamat' => $row['alamat'],
        'rating' => (float)$row['rating_rata'],
        'ulasan' => (int)$row['jumlah_ulasan'],
        'rating_paket' => (float)($row['rating_paket'] ?? 0),
        'ulasan_paket' => (int)($row['ulasan_paket'] ?? 0),
        'normal' => (int)$row['harga_normal'],
        'jual' => (int)$row['harga_jual'],
        'stok' => (int)$row['stok_sisa'],
        'porsi' => (int)$row['jumlah_porsi'],
        'mulai' => strtotime($row['tanggal_ambil'] . ' ' . $row['jam_mulai']),
        'selesai' => strtotime($row['tanggal_ambil'] . ' ' . $row['jam_selesai']),
        'produksi' => substr($row['jam_produksi'], 0, 5),
        'foto' => $row['foto'],
        'komposisi' => $row['deskripsi'] ?? '',
        'alergen' => $row['alergen'] === '' ? [] : explode(',', $row['alergen']),
        'catatan_alergen' => $row['catatan_alergen'] ?? '',
        'konsumsi_sebelum' => '',
        'penyimpanan' => '',
        'kontaminasi_silang' => '',
        'premium' => (bool)$row['toko_premium'],
    ];
}

try {
    require __DIR__ . '/koneksi.php';
} catch (Throwable $exception) {
    error_log('Package API database connection failed: ' . $exception->getMessage());
    respond(503, ['ok' => false, 'message' => 'Database sedang tidak dapat diakses.']);
}

$action = $_GET['action'] ?? '';

if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->query(
            "SELECT p.id_paket, p.tipe_listing, p.nama_paket, p.deskripsi, p.id_kategori,
                    p.id_toko, p.harga_normal, p.harga_jual, p.stok_sisa, p.jumlah_porsi,
                    p.tanggal_ambil, p.jam_mulai, p.jam_selesai, p.jam_produksi, p.foto,
                    p.alergen, p.catatan_alergen, t.nama_toko, t.alamat,
                    t.rating_rata, t.jumlah_ulasan,
                    EXISTS (
                      SELECT 1 FROM langganan l
                      JOIN paket_langganan pl ON pl.id_paket_langganan = l.id_paket_langganan
                      WHERE l.id_user = t.id_user AND pl.untuk = 'umkm'
                        AND l.status = 'aktif' AND l.berakhir_pada > NOW()
                    ) AS toko_premium,
                    COALESCE(r.rating_paket, 0) AS rating_paket,
                    COALESCE(r.ulasan_paket, 0) AS ulasan_paket
             FROM paket p
             JOIN toko t ON t.id_toko = p.id_toko
             LEFT JOIN (
               SELECT o.id_paket, AVG(u.rating) AS rating_paket, COUNT(*) AS ulasan_paket
               FROM ulasan u
               JOIN pesanan o ON o.id_pesanan = u.id_pesanan
               GROUP BY o.id_paket
             ) r ON r.id_paket = p.id_paket
             WHERE p.status = 'tersedia' AND p.stok_sisa > 0
               AND t.status = 'aktif' AND p.tanggal_ambil = CURDATE()
               AND CURTIME() < p.jam_selesai
               AND p.tayang_umum_pada <= NOW()
             ORDER BY p.created_at DESC"
        );
        $packages = array_map('packageRow', $stmt->fetchAll());
        respond(200, ['ok' => true, 'packages' => $packages]);
    } catch (Throwable $exception) {
        error_log('Package API list failed: ' . $exception->getMessage());
        respond(500, ['ok' => false, 'message' => 'Daftar paket gagal dimuat dari database.']);
    }
}

if ($action !== 'create' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(404, ['ok' => false, 'message' => 'Aksi tidak ditemukan.']);
}

if (($_SESSION['role'] ?? '') !== 'penjual' || empty($_SESSION['user_id'])) {
    respond(401, ['ok' => false, 'message' => 'Silakan masuk dengan akun penjual untuk menambahkan paket.']);
}

$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $csrf)) {
    respond(403, ['ok' => false, 'message' => 'Sesi formulir kedaluwarsa. Muat ulang halaman dan coba lagi.']);
}

$name = trim((string)($_POST['nama'] ?? ''));
$description = trim((string)($_POST['deskripsi'] ?? ''));
$type = (string)($_POST['tipe'] ?? '');
$categoryId = filter_var($_POST['kategori'] ?? null, FILTER_VALIDATE_INT);
$normalPrice = filter_var($_POST['normal'] ?? null, FILTER_VALIDATE_INT);
$salePrice = filter_var($_POST['jual'] ?? null, FILTER_VALIDATE_INT);
$stock = filter_var($_POST['stok'] ?? null, FILTER_VALIDATE_INT);
$portions = filter_var($_POST['porsi'] ?? null, FILTER_VALIDATE_INT);
$date = (string)($_POST['tanggal'] ?? '');
$pickupStart = (string)($_POST['mulai'] ?? '');
$pickupEnd = (string)($_POST['selesai'] ?? '');
$productionTime = (string)($_POST['produksi'] ?? '');
$allergens = $_POST['alergen'] ?? [];
$allergenNote = trim((string)($_POST['catatan_alergen'] ?? ''));

$validAllergens = ['susu', 'telur', 'kacang', 'gluten', 'kedelai', 'seafood', 'tidak_ada'];
if (!is_array($allergens)) {
    respond(422, ['ok' => false, 'message' => 'Daftar alergen tidak valid.']);
}
$allergens = array_values(array_unique(array_filter($allergens, static fn($value): bool => is_string($value) && in_array($value, $validAllergens, true))));
$validDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
$dateErrors = DateTimeImmutable::getLastErrors();
$dateIsValid = $validDate && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0)) && $validDate->format('Y-m-d') === $date;
$validTime = static fn(string $value): bool => (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value);

if ($name === '' || mb_strlen($name) > 100 ||
    !in_array($type, ['kejutan', 'menu'], true) ||
    !$categoryId || !$normalPrice || !$salePrice || !$stock || !$portions ||
    $normalPrice < 1 || $salePrice < 1 || $stock < 1 || $portions < 1 ||
    !$dateIsValid || !$validTime($pickupStart) || !$validTime($pickupEnd) || !$validTime($productionTime) ||
    $pickupEnd <= $pickupStart ||     count($allergens) === 0 ||
    ($allergens !== [] && in_array('tidak_ada', $allergens, true) && count($allergens) > 1) ||
    mb_strlen($description) > 65535 || mb_strlen($allergenNote) > 255) {
    respond(422, ['ok' => false, 'message' => 'Periksa kembali nama, harga, stok, tanggal, jam, kategori, dan alergen paket.']);
}

if ($salePrice >= $normalPrice) {
    respond(422, ['ok' => false, 'message' => 'Harga jual harus lebih rendah dari harga normal.']);
}

try {
    $userId = (int)$_SESSION['user_id'];
    $shopStmt = $pdo->prepare(
        "SELECT t.id_toko, t.status,
                COALESCE(MAX(pl.maks_paket_aktif),
                  (SELECT CAST(nilai AS UNSIGNED) FROM pengaturan WHERE kunci = 'maks_paket_aktif_gratis')) AS batas_paket,
                MAX(CASE WHEN l.id_langganan IS NOT NULL THEN 1 ELSE 0 END) AS premium,
                COALESCE((SELECT CAST(nilai AS UNSIGNED) FROM pengaturan WHERE kunci = 'diskon_minimal_persen'), 50) AS diskon_minimal
         FROM toko t
         LEFT JOIN langganan l ON l.id_user = t.id_user AND l.status = 'aktif' AND l.berakhir_pada > NOW()
         LEFT JOIN paket_langganan pl ON pl.id_paket_langganan = l.id_paket_langganan AND pl.untuk = 'umkm'
         WHERE t.id_user = ?
         GROUP BY t.id_toko, t.status"
    );
    $shopStmt->execute([$userId]);
    $shop = $shopStmt->fetch();
    if (!$shop) {
        respond(409, ['ok' => false, 'message' => 'Akun ini belum memiliki toko. Minta admin membuat dan memverifikasi toko sebelum memposting paket.']);
    }
    if ($shop['status'] !== 'aktif') {
        respond(403, ['ok' => false, 'message' => 'Toko belum aktif atau sedang ditangguhkan, jadi paket belum bisa diposting.']);
    }
    if ($salePrice > (int)floor((int)$normalPrice * (100 - (int)$shop['diskon_minimal']) / 100)) {
        respond(422, ['ok' => false, 'message' => 'Diskon paket belum mencapai batas minimal ' . (int)$shop['diskon_minimal'] . '%.']);
    }
    if ($date < date('Y-m-d')) {
        respond(422, ['ok' => false, 'message' => 'Tanggal pengambilan tidak boleh sudah lewat.']);
    }
    if ($date === date('Y-m-d') && $pickupEnd <= date('H:i')) {
        respond(422, ['ok' => false, 'message' => 'Jam pengambilan paket hari ini sudah lewat.']);
    }

    $activeLimit = (int)$shop['batas_paket'];
    if (!(int)$shop['premium'] && $activeLimit > 0) {
        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM paket
             WHERE id_toko = ? AND status IN ('tersedia','habis') AND tanggal_ambil = CURDATE()"
        );
        $countStmt->execute([(int)$shop['id_toko']]);
        if ((int)$countStmt->fetchColumn() >= $activeLimit) {
            respond(409, ['ok' => false, 'message' => 'Batas paket aktif toko gratis sudah tercapai.']);
        }
    }

    $photo = $_FILES['foto'] ?? null;
    if (!$photo || $photo['error'] !== UPLOAD_ERR_OK || $photo['size'] < 1) {
        respond(422, ['ok' => false, 'message' => 'Foto paket wajib diunggah.']);
    }
    $maxPhotoKb = (int)$pdo->query(
        "SELECT CAST(nilai AS UNSIGNED) FROM pengaturan WHERE kunci = 'maks_ukuran_foto_kb'"
    )->fetchColumn();
    $maxPhotoKb = $maxPhotoKb > 0 ? $maxPhotoKb : 2048;
    if ($photo['size'] > $maxPhotoKb * 1024) {
        respond(422, ['ok' => false, 'message' => 'Ukuran foto melebihi batas ' . $maxPhotoKb . ' KB.']);
    }
    $imageInfo = getimagesize($photo['tmp_name']);
    $mime = $imageInfo['mime'] ?? '';
    $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
    if (!$extension) {
        respond(422, ['ok' => false, 'message' => 'Foto harus berupa gambar JPG atau PNG yang valid.']);
    }

    $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'paket';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Could not create package upload directory.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $target = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($photo['tmp_name'], $target)) {
        throw new RuntimeException('Could not store uploaded package photo.');
    }

    $pdo->beginTransaction();
    $insert = $pdo->prepare(
        "INSERT INTO paket
          (id_toko, id_kategori, tipe_listing, nama_paket, deskripsi, jumlah_porsi,
           harga_normal, harga_jual, stok_awal, stok_sisa, tanggal_ambil,
           jam_mulai, jam_selesai, jam_produksi, foto, alergen, catatan_alergen,
           pernyataan_layak, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'tersedia')"
    );
    $insert->execute([
        (int)$shop['id_toko'], $categoryId, $type, $name, $description !== '' ? $description : null,
        $portions, $normalPrice, $salePrice, $stock, $stock, $date,
        $pickupStart, $pickupEnd, $productionTime, 'uploads/paket/' . $filename,
        implode(',', $allergens), $allergenNote !== '' ? $allergenNote : null,
    ]);
    $packageId = (int)$pdo->lastInsertId();
    $read = $pdo->prepare(
        "SELECT p.id_paket, p.tipe_listing, p.nama_paket, p.deskripsi, p.id_kategori,
                p.id_toko, p.harga_normal, p.harga_jual, p.stok_sisa, p.jumlah_porsi,
                p.tanggal_ambil, p.jam_mulai, p.jam_selesai, p.jam_produksi, p.foto,
                p.alergen, p.catatan_alergen, t.nama_toko, t.alamat,
                t.rating_rata, t.jumlah_ulasan,
                EXISTS (
                  SELECT 1 FROM langganan l
                  JOIN paket_langganan pl ON pl.id_paket_langganan = l.id_paket_langganan
                  WHERE l.id_user = t.id_user AND pl.untuk = 'umkm'
                    AND l.status = 'aktif' AND l.berakhir_pada > NOW()
                ) AS toko_premium, 0 AS rating_paket, 0 AS ulasan_paket
         FROM paket p JOIN toko t ON t.id_toko = p.id_toko
         WHERE p.id_paket = ? AND p.id_toko = ?"
    );
    $read->execute([$packageId, (int)$shop['id_toko']]);
    $row = $read->fetch();
    $pdo->commit();
    respond(201, ['ok' => true, 'message' => 'Paket berhasil disimpan dan tampil di daftar pembeli.', 'package' => packageRow($row)]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($target) && is_file($target)) {
        unlink($target);
    }
    error_log('Package API create failed: ' . $exception->getMessage());
    respond(500, ['ok' => false, 'message' => 'Paket gagal disimpan. Periksa koneksi database dan coba lagi.']);
}
