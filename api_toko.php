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

if (($_SESSION['role'] ?? '') !== 'penjual' || empty($_SESSION['user_id'])) {
    respond(401, ['ok' => false, 'message' => 'Silakan masuk dengan akun penjual.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'message' => 'Metode permintaan tidak diizinkan.']);
}
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
    respond(403, ['ok' => false, 'message' => 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.']);
}

try {
    require __DIR__ . '/koneksi.php';
} catch (Throwable $exception) {
    error_log('Shop API database connection failed: ' . $exception->getMessage());
    respond(503, ['ok' => false, 'message' => 'Database sedang tidak dapat diakses.']);
}

$userId = (int)$_SESSION['user_id'];
$action = (string)($_POST['action'] ?? 'save');

if ($action === 'resubmit') {
    try {
        $stmt = $pdo->prepare(
            "UPDATE toko SET status = 'menunggu', catatan_admin = NULL,
                    diverifikasi_oleh = NULL, diverifikasi_pada = NULL
             WHERE id_user = ? AND status = 'ditolak'"
        );
        $stmt->execute([$userId]);
        if ($stmt->rowCount() !== 1) {
            respond(409, ['ok' => false, 'message' => 'Tidak ada pengajuan ditolak yang perlu diajukan ulang.']);
        }
        respond(200, ['ok' => true, 'message' => 'Pengajuan ulang berhasil dikirim. Admin akan memeriksa kembali.']);
    } catch (Throwable $exception) {
        error_log('Shop resubmission failed: ' . $exception->getMessage());
        respond(500, ['ok' => false, 'message' => 'Pengajuan ulang gagal disimpan. Coba lagi.']);
    }
}

if ($action !== 'save') {
    respond(404, ['ok' => false, 'message' => 'Aksi tidak ditemukan.']);
}

$name = trim((string)($_POST['nama'] ?? ''));
$category = (string)($_POST['kategori'] ?? '');
$address = trim((string)($_POST['alamat'] ?? ''));
$opening = (string)($_POST['buka'] ?? '');
$closing = (string)($_POST['tutup'] ?? '');
$phone = trim((string)($_POST['telp'] ?? ''));
$categories = ['toko_roti', 'warung', 'katering', 'kafe', 'restoran', 'lainnya'];
$timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || !in_array($category, $categories, true)
    || $address === '' || mb_strlen($address) > 2000
    || !preg_match($timePattern, $opening) || !preg_match($timePattern, $closing) || $closing <= $opening
    || !preg_match('/^[0-9+\-\s().]{8,20}$/', $phone)) {
    respond(422, ['ok' => false, 'message' => 'Periksa nama, kategori, alamat, jam operasional, dan telepon.']);
}

$photo = $_FILES['foto'] ?? null;
$photoProvided = is_array($photo) && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($photoProvided && (($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$photo['tmp_name']))) {
    respond(422, ['ok' => false, 'message' => 'Foto toko gagal diunggah. Pilih ulang foto JPG atau PNG.']);
}

$oldPhoto = null;
$newPhotoPath = null;
try {
    $shopStmt = $pdo->prepare(
        'SELECT id_toko, foto, status, catatan_admin, rating_rata, jumlah_ulasan
         FROM toko WHERE id_user = ? LIMIT 1'
    );
    $shopStmt->execute([$userId]);
    $shop = $shopStmt->fetch();

    $settings = $pdo->query("SELECT nilai FROM pengaturan WHERE kunci IN ('maks_foto_kb', 'maks_ukuran_foto_kb')")->fetchAll(PDO::FETCH_COLUMN);
    $maxPhotoKb = $settings ? (int)$settings[0] : 2048;
    if (!$shop && !$photoProvided) {
        respond(422, ['ok' => false, 'message' => 'Unggah foto toko untuk mengirim profil pertama kali.']);
    }

    $photoName = $shop['foto'] ?? '';
    if ($photoProvided) {
        if ((int)$photo['size'] > $maxPhotoKb * 1024) {
            respond(422, ['ok' => false, 'message' => 'Ukuran foto melebihi batas ' . $maxPhotoKb . ' KB.']);
        }
        $image = @getimagesize((string)$photo['tmp_name']);
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png'][$image['mime'] ?? ''] ?? null;
        if ($extension === null) {
            respond(422, ['ok' => false, 'message' => 'Foto harus berupa gambar JPG atau PNG yang valid.']);
        }
        $directory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'toko';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create shop photo upload directory.');
        }
        $photoName = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $directory . DIRECTORY_SEPARATOR . $photoName;
        if (!move_uploaded_file((string)$photo['tmp_name'], $target)) {
            throw new RuntimeException('Could not store uploaded shop photo.');
        }
        $newPhotoPath = $target;
        $photoName = 'uploads/toko/' . $photoName;
    }

    $pdo->beginTransaction();
    if ($shop) {
        $oldPhoto = $shop['foto'];
        $stmt = $pdo->prepare(
            'UPDATE toko SET nama_toko = ?, kategori_usaha = ?, alamat = ?,
                    jam_buka = ?, jam_tutup = ?, no_telp = ?, foto = ?
             WHERE id_toko = ? AND id_user = ?'
        );
        $stmt->execute([$name, $category, $address, $opening, $closing, $phone, $photoName, $shop['id_toko'], $userId]);
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO toko (id_user, nama_toko, kategori_usaha, alamat, lat, lng, jam_buka, jam_tutup, no_telp, foto)
             VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $name, $category, $address, $opening, $closing, $phone, $photoName]);
    }
    $pdo->commit();

    if ($newPhotoPath !== null && is_string($oldPhoto) && preg_match('~^uploads/toko/[A-Za-z0-9._-]+$~', $oldPhoto)) {
        $previousPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldPhoto);
        if (is_file($previousPath)) {
            unlink($previousPath);
        }
    }
    respond($shop ? 200 : 201, [
        'ok' => true,
        'message' => $shop ? 'Profil toko berhasil disimpan.' : 'Profil toko berhasil dikirim. Toko kini menunggu verifikasi admin.',
        'shop' => [
            'nama' => $name, 'kategori_usaha' => $category, 'alamat' => $address,
            'jam_buka' => $opening, 'jam_tutup' => $closing, 'no_telp' => $phone,
            'foto' => $photoName, 'status' => $shop['status'] ?? 'menunggu',
            'catatan_admin' => $shop['catatan_admin'] ?? '', 'rating' => (float)($shop['rating_rata'] ?? 0),
            'jumlah_ulasan' => (int)($shop['jumlah_ulasan'] ?? 0),
        ],
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($newPhotoPath !== null && is_file($newPhotoPath)) {
        unlink($newPhotoPath);
    }
    error_log('Shop profile save failed: ' . $exception->getMessage());
    respond(500, ['ok' => false, 'message' => 'Profil toko gagal disimpan. Periksa koneksi lalu coba lagi.']);
}
