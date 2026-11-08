<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Jakarta');

const PPN_PERSEN = 11;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['pembeli', 'penjual'], true)) {
    respond(401, ['ok' => false, 'message' => 'Silakan masuk untuk memproses pesanan.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'message' => 'Metode permintaan tidak diizinkan.']);
}
if (empty($_SESSION['csrf']) || !isset($_POST['csrf'])
    || !hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf'])) {
    respond(403, ['ok' => false, 'message' => 'Sesi berakhir. Muat ulang halaman lalu coba lagi.']);
}

try {
    require __DIR__ . '/koneksi.php';
} catch (Throwable $exception) {
    error_log('Order API database connection failed: ' . $exception->getMessage());
    respond(503, ['ok' => false, 'message' => 'Database sedang tidak dapat diakses.']);
}

$action = (string)($_POST['action'] ?? '');
$userId = (int)$_SESSION['user_id'];
$role = (string)$_SESSION['role'];

try {
    if ($action === 'create' && $role === 'pembeli') {
        $packageId = filter_var($_POST['paket_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($_POST['jumlah'] ?? null, FILTER_VALIDATE_INT);
        if ($packageId === false || $packageId === null || $packageId < 1
            || $quantity === false || $quantity === null || $quantity < 1 || $quantity > 255) {
            respond(422, ['ok' => false, 'message' => 'Paket atau jumlah pesanan tidak valid.']);
        }

        $pdo->beginTransaction();
        $buyerStmt = $pdo->prepare(
            "SELECT status_akun, email_kampus_terverifikasi_pada
             FROM users WHERE id_user = ? AND role = 'pembeli' FOR UPDATE"
        );
        $buyerStmt->execute([$userId]);
        $buyer = $buyerStmt->fetch();
        if (!$buyer || $buyer['status_akun'] !== 'aktif') {
            $pdo->rollBack();
            respond(403, ['ok' => false, 'message' => 'Akun pembeli tidak aktif.']);
        }

        $packageStmt = $pdo->prepare(
            "SELECT p.id_paket, p.id_toko, p.id_kategori, p.nama_paket, p.foto,
                    p.harga_normal, p.harga_jual, p.stok_sisa, p.status,
                    p.tanggal_ambil, p.jam_mulai, p.jam_selesai,
                    t.nama_toko, t.status AS status_toko, t.id_user AS pemilik
             FROM paket p JOIN toko t ON t.id_toko = p.id_toko
             WHERE p.id_paket = ? FOR UPDATE"
        );
        $packageStmt->execute([$packageId]);
        $package = $packageStmt->fetch();
        if (!$package || $package['status_toko'] !== 'aktif') {
            $pdo->rollBack();
            respond(409, ['ok' => false, 'message' => 'Paket tidak ditemukan atau toko tidak aktif.']);
        }

        $settings = $pdo->query(
            "SELECT kunci, nilai FROM pengaturan
             WHERE kunci IN ('batas_bayar_menit', 'maks_per_pembeli', 'tutup_pesan_menit_sebelum',
                             'diskon_mahasiswa_persen', 'komisi_standar_persen')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $paymentMinutes = max(1, (int)($settings['batas_bayar_menit'] ?? 15));
        $maxPerBuyer = max(1, (int)($settings['maks_per_pembeli'] ?? 2));
        $closeMinutes = max(0, (int)($settings['tutup_pesan_menit_sebelum'] ?? 10));
        $pickupEnd = strtotime($package['tanggal_ambil'] . ' ' . $package['jam_selesai']);
        $pickupStart = strtotime($package['tanggal_ambil'] . ' ' . $package['jam_mulai']);
        if ($package['status'] !== 'tersedia' || (int)$package['stok_sisa'] < $quantity
            || $package['tanggal_ambil'] !== date('Y-m-d')
            || time() >= $pickupEnd - $closeMinutes * 60) {
            $pdo->rollBack();
            respond(409, ['ok' => false, 'message' => 'Paket sudah tidak tersedia, stok tidak cukup, atau waktu pemesanan sudah ditutup.']);
        }

        $quotaStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(jumlah), 0) FROM pesanan
             WHERE id_pembeli = ? AND id_paket = ?
               AND status IN ('menunggu_pembayaran', 'siap_diambil', 'selesai')"
        );
        $quotaStmt->execute([$userId, $packageId]);
        if ((int)$quotaStmt->fetchColumn() + $quantity > $maxPerBuyer) {
            $pdo->rollBack();
            respond(409, ['ok' => false, 'message' => 'Jumlah pesanan melebihi batas ' . $maxPerBuyer . ' paket per pembeli.']);
        }

        $subtotal = (int)$package['harga_jual'] * $quantity;
        $ppn = (int)round((int)$package['harga_jual'] * PPN_PERSEN / 100) * $quantity;
        $studentPercent = (int)($settings['diskon_mahasiswa_persen'] ?? 0);
        $studentDiscount = $buyer['email_kampus_terverifikasi_pada'] !== null
            ? (int)round($subtotal * $studentPercent / 100)
            : 0;
        $totalToPay = $subtotal + $ppn - $studentDiscount;
        $premiumStmt = $pdo->prepare(
            "SELECT pl.komisi_persen FROM langganan l
             JOIN paket_langganan pl ON pl.id_paket_langganan = l.id_paket_langganan
             WHERE l.id_user = ? AND pl.untuk = 'umkm'
               AND l.status = 'aktif' AND l.berakhir_pada > NOW()
             ORDER BY l.berakhir_pada DESC LIMIT 1"
        );
        $premiumStmt->execute([(int)$package['pemilik']]);
        $premiumCommission = $premiumStmt->fetchColumn();
        $commissionPercent = $premiumCommission !== false && $premiumCommission !== null
            ? (float)$premiumCommission
            : (float)($settings['komisi_standar_persen'] ?? 0);
        $commission = (int)round($subtotal * $commissionPercent / 100);

        $stockStmt = $pdo->prepare(
            "UPDATE paket
             SET stok_sisa = stok_sisa - ?,
                 status = IF(stok_sisa = 0, 'habis', status)
             WHERE id_paket = ? AND status = 'tersedia'
               AND stok_sisa >= ? AND tanggal_ambil = CURDATE()"
        );
        $stockStmt->execute([$quantity, $packageId, $quantity]);
        if ($stockStmt->rowCount() !== 1) {
            $pdo->rollBack();
            respond(409, ['ok' => false, 'message' => 'Stok paket baru saja berubah. Muat ulang daftar paket.']);
        }

        $orderCode = 'RS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $insert = $pdo->prepare(
            'INSERT INTO pesanan
             (kode_pesanan, id_pembeli, id_paket, jumlah, harga_satuan, subtotal,
              potongan_mahasiswa, ppn_nominal, total_bayar, komisi_persen, komisi_nominal, batas_bayar)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
        );
        $insert->execute([
            $orderCode, $userId, $packageId, $quantity, (int)$package['harga_jual'], $subtotal,
            $studentDiscount, $ppn, $totalToPay, $commissionPercent, $commission, $paymentMinutes,
        ]);
        $orderId = (int)$pdo->lastInsertId();
        $pdo->commit();

        respond(201, [
            'ok' => true,
            'message' => 'Pesanan tersimpan. Selesaikan pembayaran sebelum batas waktunya.',
            'order' => [
                'id' => $orderId, 'kode' => $orderCode, 'paket_id' => (int)$package['id_paket'],
                'nama' => $package['nama_paket'], 'toko' => $package['nama_toko'],
                'toko_id' => (int)$package['id_toko'], 'foto' => $package['foto'],
                'kategori' => (int)$package['id_kategori'], 'jumlah' => $quantity,
                'normal' => (int)$package['harga_normal'], 'satuan' => (int)$package['harga_jual'],
                'pot_mhs' => $studentDiscount, 'pajak' => $ppn, 'total_bayar' => $totalToPay,
                'status' => 'menunggu_pembayaran',
                'batas_bayar' => time() + $paymentMinutes * 60, 'kode_ambil' => null,
                'ambil_mulai' => $pickupStart, 'ambil_selesai' => $pickupEnd,
                'dibuat' => time(), 'ulasan' => null, 'dilaporkan' => false,
            ],
            'stock' => (int)$package['stok_sisa'] - $quantity,
        ]);
    }

    if ($action === 'pay' && $role === 'pembeli') {
        $orderId = filter_var($_POST['pesanan_id'] ?? null, FILTER_VALIDATE_INT);
        $method = trim((string)($_POST['metode_bayar'] ?? ''));
        $methods = ['QRIS (simulasi)', 'E-wallet (simulasi)', 'Transfer bank (simulasi)'];
        if ($orderId === false || $orderId === null || $orderId < 1 || !in_array($method, $methods, true)) {
            respond(422, ['ok' => false, 'message' => 'Pesanan atau metode pembayaran tidak valid.']);
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT status, batas_bayar, ppn_nominal, total_bayar FROM pesanan
             WHERE id_pesanan = ? AND id_pembeli = ? FOR UPDATE'
        );
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch();
        if (!$order || $order['status'] !== 'menunggu_pembayaran') {
            $pdo->rollBack();
            respond(409, ['ok' => false, 'message' => 'Pesanan tidak ditemukan atau sudah diproses.']);
        }
        if (strtotime($order['batas_bayar']) < time()) {
            $pdo->rollBack();
            respond(409, ['ok' => false, 'message' => 'Batas pembayaran sudah habis. Muat ulang halaman dan buat pesanan baru.']);
        }

        $code = '';
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codeStmt = $pdo->prepare(
            "UPDATE pesanan
             SET status = 'siap_diambil', waktu_bayar = NOW(), metode_bayar = ?, kode_pengambilan = ?
             WHERE id_pesanan = ? AND id_pembeli = ? AND status = 'menunggu_pembayaran'"
        );
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            try {
                $codeStmt->execute([$method, $code, $orderId, $userId]);
                break;
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000' || $attempt === 4) {
                    throw $exception;
                }
            }
        }
        if ($codeStmt->rowCount() !== 1) {
            $pdo->rollBack();
            respond(409, ['ok' => false, 'message' => 'Pembayaran tidak dapat diproses. Muat ulang pesanan.']);
        }
        $pdo->commit();
        respond(200, [
            'ok' => true,
            'message' => 'Pembayaran simulasi berhasil. Kode pengambilan sudah tersimpan.',
            'order' => [
                'status' => 'siap_diambil', 'kode_ambil' => $code, 'metode' => $method,
                'waktu_bayar' => time(), 'pajak' => (int)$order['ppn_nominal'],
                'total_bayar' => (int)$order['total_bayar'],
            ],
        ]);
    }

    if ($action === 'verify' && $role === 'penjual') {
        $code = strtoupper(trim((string)($_POST['kode'] ?? '')));
        if (!preg_match('/^[A-Z0-9]{6}$/', $code)) {
            respond(422, ['ok' => false, 'message' => 'Kode pengambilan harus terdiri dari 6 karakter.']);
        }
        $pdo->beginTransaction();
        $shopStmt = $pdo->prepare(
            'SELECT id_toko, status FROM toko WHERE id_user = ? FOR UPDATE'
        );
        $shopStmt->execute([$userId]);
        $shop = $shopStmt->fetch();
        if (!$shop || $shop['status'] !== 'aktif') {
            $pdo->rollBack();
            respond(403, ['ok' => false, 'message' => 'Toko belum aktif, jadi pesanan belum bisa diverifikasi.']);
        }
        $stmt = $pdo->prepare(
            'SELECT o.id_pesanan, o.kode_pesanan, o.id_paket, o.status, o.jumlah,
                    o.harga_satuan, o.potongan_mahasiswa, o.potongan_voucher,
                    o.komisi_persen, UNIX_TIMESTAMP(o.created_at) AS dibuat,
                    UNIX_TIMESTAMP(o.waktu_bayar) AS bayar, UNIX_TIMESTAMP(o.batas_bayar) AS batas_bayar,
                    u.nama AS pembeli, p.nama_paket, p.tanggal_ambil, p.jam_mulai, p.jam_selesai
             FROM pesanan o JOIN paket p ON p.id_paket = o.id_paket
             JOIN users u ON u.id_user = o.id_pembeli
             WHERE o.kode_pengambilan = ? AND p.id_toko = ? FOR UPDATE'
        );
        $stmt->execute([$code, (int)$shop['id_toko']]);
        $order = $stmt->fetch();
        if (!$order) {
            $pdo->rollBack();
            respond(404, ['ok' => false, 'message' => 'Kode tidak ditemukan untuk tokomu. Periksa kembali dengan pembeli.']);
        }
        if ($order['status'] !== 'siap_diambil') {
            $pdo->rollBack();
            respond(409, [
                'ok' => false,
                'message' => $order['status'] === 'selesai'
                    ? 'Kode ini sudah pernah digunakan.'
                    : 'Pesanan ini tidak berstatus siap diambil.',
            ]);
        }
        $pickupStart = strtotime($order['tanggal_ambil'] . ' ' . $order['jam_mulai']);
        $pickupEnd = strtotime($order['tanggal_ambil'] . ' ' . $order['jam_selesai']);
        if (time() < $pickupStart || time() > $pickupEnd) {
            $pdo->rollBack();
            respond(409, [
                'ok' => false,
                'message' => 'Di luar jam ambil (' . date('H:i', $pickupStart) . '–' . date('H:i', $pickupEnd) . ').',
            ]);
        }
        $update = $pdo->prepare(
            "UPDATE pesanan SET status = 'selesai', waktu_selesai = NOW()
             WHERE id_pesanan = ? AND status = 'siap_diambil'"
        );
        $update->execute([(int)$order['id_pesanan']]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            respond(409, ['ok' => false, 'message' => 'Kode baru saja digunakan. Muat ulang pesanan.']);
        }
        $pdo->commit();
        respond(200, [
            'ok' => true,
            'message' => 'Berhasil. Serahkan ' . (int)$order['jumlah'] . ' × ' . $order['nama_paket'] . ' kepada ' . $order['pembeli'] . '.',
            'order' => [
                'id' => (int)$order['id_pesanan'], 'kode' => $order['kode_pesanan'],
                'paket_id' => (int)$order['id_paket'], 'status' => 'selesai',
                'jumlah' => (int)$order['jumlah'], 'paket_nama' => $order['nama_paket'],
                'pembeli' => $order['pembeli'], 'satuan' => (int)$order['harga_satuan'],
                'pot_mhs' => (int)$order['potongan_mahasiswa'], 'pot_voucher' => (int)$order['potongan_voucher'],
                'komisi' => (float)$order['komisi_persen'], 'dibuat' => (int)$order['dibuat'],
                'bayar' => (int)$order['bayar'], 'batas_bayar' => (int)$order['batas_bayar'],
                'selesai_ts' => time(),
            ],
        ]);
    }

    respond(404, ['ok' => false, 'message' => 'Aksi pesanan tidak ditemukan.']);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Order API action failed (' . $action . '): ' . $exception->getMessage());
    respond(500, ['ok' => false, 'message' => 'Pesanan gagal diproses. Coba lagi.']);
}
