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

if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['user_id'])) {
    respond(401, ['ok' => false, 'message' => 'Silakan masuk dengan akun admin.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'message' => 'Metode permintaan tidak diizinkan.']);
}
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
    respond(403, ['ok' => false, 'message' => 'Sesi berakhir. Muat ulang halaman lalu coba lagi.']);
}

$action = (string)($_POST['action'] ?? '');
$adminId = (int)$_SESSION['user_id'];
$settingRules = [
    'diskon_minimal_persen' => [10, 90],
    'batas_bayar_menit' => [5, 60],
    'maks_per_pembeli' => [1, 10],
    'tutup_pesan_menit_sebelum' => [0, 60],
    'maks_ukuran_foto_kb' => [256, 10240],
    'komisi_standar_persen' => [0, 50],
    'maks_paket_aktif_gratis' => [1, 20],
    'diskon_mahasiswa_persen' => [0, 30],
];

function cancelOrders(PDO $pdo, string $where, array $params, string $reason): int
{
    $select = $pdo->prepare(
        "SELECT id_pesanan, id_paket, id_voucher, jumlah, total_bayar, status
         FROM pesanan WHERE {$where}
           AND status IN ('siap_diambil', 'menunggu_pembayaran')
         FOR UPDATE"
    );
    $select->execute($params);
    $orders = $select->fetchAll();
    $cancel = $pdo->prepare(
        "UPDATE pesanan
         SET status = 'dibatalkan', dibatalkan_oleh = 'admin', alasan_batal = ?,
             status_refund = ?, nominal_refund = ?, waktu_refund = ?
         WHERE id_pesanan = ?"
    );
    $restock = $pdo->prepare(
        'UPDATE paket SET stok_sisa = LEAST(stok_awal, stok_sisa + ?) WHERE id_paket = ?'
    );
    $restoreVoucher = $pdo->prepare(
        "UPDATE voucher SET status = 'tersedia'
         WHERE id_voucher = ? AND status = 'terpakai' AND berlaku_sampai > NOW()"
    );
    foreach ($orders as $order) {
        $paid = $order['status'] === 'siap_diambil';
        $cancel->execute([
            $reason,
            $paid ? 'selesai' : 'tidak_ada',
            $paid ? (int)$order['total_bayar'] : 0,
            $paid ? date('Y-m-d H:i:s') : null,
            (int)$order['id_pesanan'],
        ]);
        $restock->execute([(int)$order['jumlah'], (int)$order['id_paket']]);
        if ($order['id_voucher'] !== null) {
            $restoreVoucher->execute([(int)$order['id_voucher']]);
        }
    }
    return count($orders);
}

function suspendShop(PDO $pdo, int $shopId, string $reason): array
{
    $stmt = $pdo->prepare("SELECT id_user, status FROM toko WHERE id_toko = ? FOR UPDATE");
    $stmt->execute([$shopId]);
    $shop = $stmt->fetch();
    if (!$shop || $shop['status'] !== 'aktif') {
        throw new DomainException('Toko tidak ditemukan atau bukan berstatus aktif.');
    }

    $packages = $pdo->prepare(
        "UPDATE paket SET status = 'ditangguhkan'
         WHERE id_toko = ? AND status IN ('tersedia', 'habis')"
    );
    $packages->execute([$shopId]);
    $packageCount = $packages->rowCount();
    $stmt = $pdo->prepare(
        "UPDATE toko SET status = 'ditangguhkan', catatan_admin = ?
         WHERE id_toko = ? AND status = 'aktif'"
    );
    $stmt->execute([$reason, $shopId]);
    $orders = cancelOrders($pdo, 'id_paket IN (SELECT id_paket FROM paket WHERE id_toko = ?)', [$shopId], $reason);
    $notify = $pdo->prepare(
        "INSERT INTO notifikasi (id_user, tipe, judul, isi)
         VALUES (?, 'sistem', 'Toko ditangguhkan', ?)"
    );
    $notify->execute([(int)$shop['id_user'], mb_substr($reason, 0, 255)]);
    return ['paket' => $packageCount, 'pesanan' => $orders];
}

try {
    require __DIR__ . '/koneksi.php';
    $adminStmt = $pdo->prepare(
        "SELECT 1 FROM users
         WHERE id_user = ? AND role = 'admin' AND status_akun = 'aktif'
         LIMIT 1"
    );
    $adminStmt->execute([$adminId]);
    if (!$adminStmt->fetchColumn()) {
        $_SESSION = [];
        session_destroy();
        respond(401, ['ok' => false, 'message' => 'Akun admin tidak aktif. Silakan masuk kembali.']);
    }

    if (in_array($action, ['approve', 'reject'], true)) {
        $shopId = filter_var($_POST['toko_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string)($_POST['alasan'] ?? ''));
        if ($shopId === false || $shopId === null || $shopId < 1) {
            respond(422, ['ok' => false, 'message' => 'ID toko tidak valid.']);
        }
        if ($action === 'reject' && (mb_strlen($reason) < 5 || mb_strlen($reason) > 2000)) {
            respond(422, ['ok' => false, 'message' => 'Alasan penolakan wajib diisi (5–2000 karakter).']);
        }
        $pdo->beginTransaction();
        $shopStmt = $pdo->prepare(
            'SELECT t.id_user, t.status, u.status_akun
             FROM toko t JOIN users u ON u.id_user = t.id_user
             WHERE t.id_toko = ? FOR UPDATE'
        );
        $shopStmt->execute([$shopId]);
        $shop = $shopStmt->fetch();
        if (!$shop || $shop['status'] !== 'menunggu' || $shop['status_akun'] !== 'aktif') {
            $pdo->rollBack();
            respond(409, ['ok' => false, 'message' => 'Toko tidak dapat diverifikasi: toko sudah diproses atau akun pemilik tidak aktif.']);
        }
        $status = $action === 'approve' ? 'aktif' : 'ditolak';
        $note = $action === 'approve' ? null : $reason;
        $stmt = $pdo->prepare(
            "UPDATE toko
             SET status = ?, catatan_admin = ?, diverifikasi_oleh = ?, diverifikasi_pada = NOW()
             WHERE id_toko = ? AND status = 'menunggu'"
        );
        $stmt->execute([$status, $note, $adminId, $shopId]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            respond(409, ['ok' => false, 'message' => 'Toko tidak ditemukan atau sudah diproses admin lain. Muat ulang halaman.']);
        }
        $notify = $pdo->prepare(
            'INSERT INTO notifikasi (id_user, tipe, judul, isi, tautan) VALUES (?, \'sistem\', ?, ?, ?)'
        );
        $notify->execute([
            (int)$shop['id_user'],
            $action === 'approve' ? 'Toko disetujui' : 'Pengajuan toko perlu diperbaiki',
            mb_substr($action === 'approve' ? 'Toko kamu sudah aktif dan dapat mulai berjualan.' : $reason, 0, 255),
            'dashboard_penjual.php',
        ]);
        $pdo->commit();
        respond(200, [
            'ok' => true,
            'message' => $action === 'approve' ? 'Toko disetujui dan kini aktif.' : 'Toko ditolak. Alasan dikirim ke penjual.',
            'shop' => ['status' => $status, 'catatan_admin' => $note ?? ''],
        ]);
    }

    if ($action === 'suspend_shop' || $action === 'activate_shop') {
        $shopId = filter_var($_POST['toko_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string)($_POST['alasan'] ?? ''));
        if ($shopId === false || $shopId === null || $shopId < 1
            || ($action === 'suspend_shop' && (mb_strlen($reason) < 5 || mb_strlen($reason) > 2000))) {
            respond(422, ['ok' => false, 'message' => 'ID toko atau alasan penangguhan tidak valid.']);
        }
        $pdo->beginTransaction();
        if ($action === 'suspend_shop') {
            $impact = suspendShop($pdo, $shopId, $reason);
            $message = 'Toko ditangguhkan. ' . $impact['paket'] . ' paket ditutup dan ' . $impact['pesanan'] . ' pesanan dibatalkan.';
        } else {
            $ownerStmt = $pdo->prepare(
                'SELECT u.status_akun FROM toko t
                 JOIN users u ON u.id_user = t.id_user
                 WHERE t.id_toko = ? AND t.status = \'ditangguhkan\' FOR UPDATE'
            );
            $ownerStmt->execute([$shopId]);
            $ownerStatus = $ownerStmt->fetchColumn();
            if ($ownerStatus !== 'aktif') {
                $pdo->rollBack();
                respond(409, ['ok' => false, 'message' => 'Toko tidak ditemukan atau akun pemilik belum dipulihkan.']);
            }
            $stmt = $pdo->prepare(
                "UPDATE toko SET status = 'aktif', catatan_admin = NULL
                 WHERE id_toko = ? AND status = 'ditangguhkan'"
            );
            $stmt->execute([$shopId]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                respond(409, ['ok' => false, 'message' => 'Toko tidak ditemukan atau bukan berstatus ditangguhkan.']);
            }
            $message = 'Toko diaktifkan kembali. Paket yang ditutup tetap tertutup.';
        }
        $pdo->commit();
        respond(200, ['ok' => true, 'message' => $message]);
    }

    if ($action === 'suspend_user' || $action === 'activate_user') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string)($_POST['alasan'] ?? ''));
        if ($userId === false || $userId === null || $userId < 1
            || ($action === 'suspend_user' && (mb_strlen($reason) < 5 || mb_strlen($reason) > 2000))) {
            respond(422, ['ok' => false, 'message' => 'ID pengguna atau alasan penangguhan tidak valid.']);
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT role, status_akun FROM users WHERE id_user = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $expectedStatus = $action === 'suspend_user' ? 'aktif' : 'nonaktif';
        if (!$user || $user['role'] === 'admin' || $user['status_akun'] !== $expectedStatus) {
            $pdo->rollBack();
            respond(409, ['ok' => false, 'message' => 'Akun tidak ditemukan, admin, atau statusnya sudah berubah.']);
        }
        $status = $action === 'suspend_user' ? 'nonaktif' : 'aktif';
        $stmt = $pdo->prepare('UPDATE users SET status_akun = ? WHERE id_user = ?');
        $stmt->execute([$status, $userId]);
        $impact = ['paket' => 0, 'pesanan' => 0];
        if ($action === 'suspend_user' && $user['role'] === 'penjual') {
            $shops = $pdo->prepare(
                "SELECT id_toko FROM toko WHERE id_user = ? AND status = 'aktif' FOR UPDATE"
            );
            $shops->execute([$userId]);
            foreach ($shops->fetchAll(PDO::FETCH_COLUMN) as $shopId) {
                $result = suspendShop($pdo, (int)$shopId, 'Akun pemilik ditangguhkan: ' . $reason);
                $impact['paket'] += $result['paket'];
                $impact['pesanan'] += $result['pesanan'];
            }
        } elseif ($action === 'suspend_user' && $user['role'] === 'pembeli') {
            $impact['pesanan'] = cancelOrders($pdo, 'id_pembeli = ?', [$userId], 'Akun pembeli ditangguhkan: ' . $reason);
        }
        $pdo->commit();
        respond(200, [
            'ok' => true,
            'message' => $action === 'suspend_user' ? 'Akun ditangguhkan.' : 'Akun dipulihkan.',
            'impact' => $impact,
        ]);
    }

    if ($action === 'report_process' || $action === 'report_decide') {
        $reportId = filter_var($_POST['laporan_id'] ?? null, FILTER_VALIDATE_INT);
        $note = trim((string)($_POST['catatan'] ?? ''));
        if ($reportId === false || $reportId === null || $reportId < 1 || mb_strlen($note) > 2000
            || ($action === 'report_decide' && mb_strlen($note) < 5)) {
            respond(422, ['ok' => false, 'message' => 'Laporan atau catatan admin tidak valid.']);
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT l.status, p.id_toko, p.id_paket, o.id_pembeli, t.id_user AS penjual
             FROM laporan l JOIN pesanan o ON o.id_pesanan = l.id_pesanan
             JOIN paket p ON p.id_paket = o.id_paket
             JOIN toko t ON t.id_toko = p.id_toko
             WHERE l.id_laporan = ? FOR UPDATE"
        );
        $stmt->execute([$reportId]);
        $report = $stmt->fetch();
        if (!$report || !in_array($report['status'], ['baru', 'diproses'], true)) {
            $pdo->rollBack();
            respond(409, ['ok' => false, 'message' => 'Laporan tidak ditemukan atau sudah selesai ditangani.']);
        }
        if ($action === 'report_process') {
            $stmt = $pdo->prepare(
                "UPDATE laporan SET status = 'diproses', catatan_admin = ?, id_admin = ?
                 WHERE id_laporan = ? AND status = 'baru'"
            );
            $stmt->execute([$note, $adminId, $reportId]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                respond(409, ['ok' => false, 'message' => 'Laporan sudah diproses admin lain. Muat ulang halaman.']);
            }
            $message = 'Laporan ditandai sedang diproses.';
        } else {
            $decision = (string)($_POST['tindakan'] ?? '');
            if (!in_array($decision, ['abaikan', 'peringatan', 'tangguhkan_paket', 'tangguhkan_toko'], true)) {
                $pdo->rollBack();
                respond(422, ['ok' => false, 'message' => 'Tindakan laporan tidak valid.']);
            }
            if ($decision === 'tangguhkan_toko') {
                $impact = suspendShop($pdo, (int)$report['id_toko'], $note);
                $message = 'Toko ditangguhkan. ' . $impact['paket'] . ' paket ditutup dan ' . $impact['pesanan'] . ' pesanan dibatalkan.';
            } elseif ($decision === 'tangguhkan_paket') {
                $stmt = $pdo->prepare(
                    "UPDATE paket SET status = 'ditangguhkan'
                     WHERE id_paket = ? AND status IN ('tersedia', 'habis')"
                );
                $stmt->execute([(int)$report['id_paket']]);
                if ($stmt->rowCount() !== 1) {
                    $pdo->rollBack();
                    respond(409, ['ok' => false, 'message' => 'Paket tidak lagi tersedia untuk ditangguhkan.']);
                }
                $count = cancelOrders($pdo, 'id_paket = ?', [(int)$report['id_paket']], 'Paket ditangguhkan');
                $message = 'Paket ditangguhkan dan ' . $count . ' pesanan dibatalkan.';
            } else {
                $message = $decision === 'peringatan' ? 'Peringatan dikirim ke penjual.' : 'Laporan ditutup tanpa tindakan.';
            }
            if ($decision === 'peringatan') {
                $notify = $pdo->prepare(
                    "INSERT INTO notifikasi (id_user, tipe, judul, isi)
                     VALUES (?, 'sistem', 'Peringatan dari admin', ?)"
                );
                $notify->execute([(int)$report['penjual'], mb_substr($note, 0, 255)]);
            }
            $stmt = $pdo->prepare(
                "UPDATE laporan
                 SET status = 'selesai', tindakan_admin = ?, catatan_admin = ?,
                     id_admin = ?, ditangani_pada = NOW()
                 WHERE id_laporan = ? AND status IN ('baru', 'diproses')"
            );
            $stmt->execute([$decision, $note, $adminId, $reportId]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                respond(409, ['ok' => false, 'message' => 'Laporan sudah ditangani admin lain. Muat ulang halaman.']);
            }
        }
        $pdo->commit();
        respond(200, ['ok' => true, 'message' => $message]);
    }

    if ($action === 'category_create' || $action === 'category_update' || $action === 'category_delete') {
        $categoryId = filter_var($_POST['kategori_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string)($_POST['nama'] ?? ''));
        if ($action !== 'category_create' && ($categoryId === false || $categoryId === null || $categoryId < 1)) {
            respond(422, ['ok' => false, 'message' => 'ID kategori tidak valid.']);
        }
        if ($action !== 'category_delete' && (mb_strlen($name) < 2 || mb_strlen($name) > 50)) {
            respond(422, ['ok' => false, 'message' => 'Nama kategori harus berisi 2–50 karakter.']);
        }
        try {
            if ($action === 'category_create') {
                $stmt = $pdo->prepare('INSERT INTO kategori (nama) VALUES (?)');
                $stmt->execute([$name]);
                $categoryId = (int)$pdo->lastInsertId();
            } elseif ($action === 'category_update') {
                $stmt = $pdo->prepare('UPDATE kategori SET nama = ? WHERE id_kategori = ?');
                $stmt->execute([$name, $categoryId]);
                if ($stmt->rowCount() !== 1) {
                    $exists = $pdo->prepare('SELECT 1 FROM kategori WHERE id_kategori = ?');
                    $exists->execute([$categoryId]);
                    if (!$exists->fetchColumn()) {
                        respond(404, ['ok' => false, 'message' => 'Kategori tidak ditemukan.']);
                    }
                }
            } else {
                $stmt = $pdo->prepare('DELETE FROM kategori WHERE id_kategori = ?');
                $stmt->execute([$categoryId]);
                if ($stmt->rowCount() !== 1) {
                    respond(404, ['ok' => false, 'message' => 'Kategori tidak ditemukan.']);
                }
            }
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                respond(409, ['ok' => false, 'message' => 'Nama kategori sudah digunakan atau kategori masih dipakai paket.']);
            }
            throw $exception;
        }
        respond(200, ['ok' => true, 'message' => 'Kategori berhasil disimpan.']);
    }

    if ($action === 'settings_save') {
        $values = $_POST['values'] ?? null;
        if (!is_array($values) || count($values) !== count($settingRules)) {
            respond(422, ['ok' => false, 'message' => 'Data pengaturan tidak lengkap.']);
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO pengaturan (kunci, nilai) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)'
        );
        foreach ($settingRules as $key => [$min, $max]) {
            $value = $values[$key] ?? null;
            if (!is_scalar($value) || !preg_match('/^\d+$/', (string)$value)
                || (int)$value < $min || (int)$value > $max) {
                $pdo->rollBack();
                respond(422, ['ok' => false, 'message' => 'Nilai pengaturan ' . $key . ' di luar rentang yang diizinkan.']);
            }
            $stmt->execute([$key, (string)(int)$value]);
        }
        $pdo->commit();
        respond(200, ['ok' => true, 'message' => 'Pengaturan berhasil disimpan.']);
    }

    respond(404, ['ok' => false, 'message' => 'Aksi tidak ditemukan.']);
} catch (DomainException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(409, ['ok' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Admin action failed (' . $action . '): ' . $exception->getMessage());
    respond(500, ['ok' => false, 'message' => 'Perubahan gagal disimpan. Coba lagi.']);
}
