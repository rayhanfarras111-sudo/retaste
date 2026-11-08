<?php
/**
 * ReTaste - Dashboard Penjual (UMKM)
 * Letakkan di: htdocs/retaste/dashboard_penjual.php  |  aset di: assets/
 *
 * Mengikuti alur penjual: daftar toko -> verifikasi admin -> posting paket sore ->
 * pesanan masuk -> verifikasi kode pengambilan -> laporan penjualan.
 */
session_start();
date_default_timezone_set('Asia/Jakarta');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'penjual') {
    header('Location: login.php?role=penjual');
    exit;
}

$nama    = $_SESSION['nama'] ?? ($_SESSION['username'] ?? 'Bu Sari');
$inisial = mb_strtoupper(mb_substr($nama, 0, 1));
$tahun   = date('Y');

$now = time();
$pengaturan = ['pajak_persen' => 11];

$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(32));
$backendReady = false;
$toko = [
    'nama' => '', 'kategori_usaha' => 'lainnya', 'alamat' => '', 'lat' => 0, 'lng' => 0,
    'jam_buka' => '', 'jam_tutup' => '', 'no_telp' => '', 'foto' => '',
    'status' => 'menunggu', 'catatan_admin' => '', 'rating' => 0, 'jumlah_ulasan' => 0,
];
$langganan = ['premium' => false, 'nama' => '', 'berakhir_pada' => null];
$paket = [];
$pesanan = [];
$ulasan = [];
$kategori = [];
{
    require __DIR__ . '/koneksi.php';
    $settings = $pdo->query('SELECT kunci, nilai FROM pengaturan')->fetchAll();
    foreach ($settings as $setting) {
        if ($setting['kunci'] === 'diskon_minimal_persen') $pengaturan['diskon_minimal_persen'] = (int)$setting['nilai'];
        if ($setting['kunci'] === 'komisi_standar_persen') $pengaturan['komisi_standar_persen'] = (int)$setting['nilai'];
        if ($setting['kunci'] === 'komisi_pro_persen') $pengaturan['komisi_pro_persen'] = (int)$setting['nilai'];
        if ($setting['kunci'] === 'maks_paket_aktif_gratis') $pengaturan['maks_paket_aktif_gratis'] = (int)$setting['nilai'];
        if ($setting['kunci'] === 'maks_foto_kb' || $setting['kunci'] === 'maks_ukuran_foto_kb') $pengaturan['maks_foto_kb'] = (int)$setting['nilai'];
    }

    $shopStmt = $pdo->prepare(
        'SELECT id_toko, nama_toko, kategori_usaha, alamat, jam_buka, jam_tutup,
                no_telp, foto, status, catatan_admin, rating_rata, jumlah_ulasan
         FROM toko WHERE id_user = ? LIMIT 1'
    );
    $shopStmt->execute([(int)$_SESSION['user_id']]);
    $shop = $shopStmt->fetch();
    if ($shop) {
        $backendReady = $shop['status'] === 'aktif';
        $toko = [
            'nama' => $shop['nama_toko'], 'kategori_usaha' => $shop['kategori_usaha'],
            'alamat' => $shop['alamat'],
            'jam_buka' => substr($shop['jam_buka'], 0, 5), 'jam_tutup' => substr($shop['jam_tutup'], 0, 5),
            'no_telp' => $shop['no_telp'], 'foto' => $shop['foto'], 'status' => $shop['status'],
            'catatan_admin' => $shop['catatan_admin'] ?? '', 'rating' => (float)$shop['rating_rata'],
            'jumlah_ulasan' => (int)$shop['jumlah_ulasan'],
        ];
        $premiumStmt = $pdo->prepare(
            "SELECT EXISTS (
               SELECT 1 FROM langganan l JOIN paket_langganan pl ON pl.id_paket_langganan = l.id_paket_langganan
               WHERE l.id_user = ? AND pl.untuk = 'umkm' AND l.status = 'aktif' AND l.berakhir_pada > NOW()
             )"
        );
        $premiumStmt->execute([(int)$_SESSION['user_id']]);
        $premium = (bool)$premiumStmt->fetchColumn();
        $langganan = ['premium' => $premium, 'nama' => $premium ? 'ReTaste Pro' : '', 'berakhir_pada' => null];

        $packageStmt = $pdo->prepare(
            'SELECT id_paket, tipe_listing, nama_paket, deskripsi, id_kategori, harga_normal,
                    harga_jual, stok_awal, stok_sisa, jumlah_porsi, tanggal_ambil, jam_mulai,
                    jam_selesai, jam_produksi, foto, alergen, catatan_alergen, status
             FROM paket WHERE id_toko = ? ORDER BY created_at DESC'
        );
        $packageStmt->execute([(int)$shop['id_toko']]);
        $paket = [];
        foreach ($packageStmt->fetchAll() as $row) {
            $date = $row['tanggal_ambil'];
            $paket[] = [
                'id' => (int)$row['id_paket'], 'tipe' => $row['tipe_listing'],
                'nama' => $row['nama_paket'], 'deskripsi' => $row['deskripsi'] ?? '',
                'kategori' => (int)$row['id_kategori'], 'normal' => (int)$row['harga_normal'],
                'jual' => (int)$row['harga_jual'], 'stok_awal' => (int)$row['stok_awal'],
                'stok' => (int)$row['stok_sisa'], 'porsi' => (int)$row['jumlah_porsi'],
                'mulai' => strtotime($date . ' ' . $row['jam_mulai']),
                'selesai' => strtotime($date . ' ' . $row['jam_selesai']),
                'produksi' => substr($row['jam_produksi'], 0, 5), 'foto' => $row['foto'],
                'alergen' => $row['alergen'] === '' ? [] : explode(',', $row['alergen']),
                'catatan_alergen' => $row['catatan_alergen'] ?? '',
                'status' => $row['status'], 'dilihat' => 0,
            ];
        }
        $orderStmt = $pdo->prepare(
            "SELECT o.id_pesanan AS id, o.kode_pesanan AS kode, o.id_paket AS paket_id,
                    p.nama_paket AS paket_nama, u.nama AS pembeli, o.jumlah,
                    o.harga_satuan AS satuan, o.potongan_mahasiswa AS pot_mhs,
                    o.potongan_voucher AS pot_voucher, o.komisi_persen AS komisi,
                    o.ppn_nominal AS pajak,
                    o.status, o.kode_pengambilan AS kode_ambil,
                    UNIX_TIMESTAMP(o.created_at) AS dibuat, UNIX_TIMESTAMP(o.waktu_bayar) AS bayar,
                    UNIX_TIMESTAMP(o.batas_bayar) AS batas_bayar,
                    UNIX_TIMESTAMP(o.waktu_selesai) AS selesai_ts
             FROM pesanan o
             JOIN paket p ON p.id_paket = o.id_paket
             JOIN users u ON u.id_user = o.id_pembeli
             WHERE p.id_toko = ?
             ORDER BY o.created_at DESC"
        );
        $orderStmt->execute([(int)$shop['id_toko']]);
        $pesanan = array_map(static function (array $order): array {
            foreach (['id', 'paket_id', 'jumlah', 'satuan', 'pot_mhs', 'pot_voucher', 'pajak'] as $field) {
                $order[$field] = (int)$order[$field];
            }
            $order['komisi'] = (float)$order['komisi'];
            foreach (['dibuat', 'bayar', 'batas_bayar', 'selesai_ts'] as $field) {
                $order[$field] = $order[$field] === null ? null : (int)$order[$field];
            }
            return $order;
        }, $orderStmt->fetchAll());
        $reviewStmt = $pdo->prepare(
            'SELECT u.nama AS pembeli, o.id_paket AS paket_id, r.rating, r.komentar,
                    UNIX_TIMESTAMP(r.created_at) AS ts
             FROM ulasan r
             JOIN pesanan o ON o.id_pesanan = r.id_pesanan
             JOIN paket p ON p.id_paket = o.id_paket
             JOIN users u ON u.id_user = r.id_pembeli
             WHERE p.id_toko = ?
             ORDER BY r.created_at DESC'
        );
        $reviewStmt->execute([(int)$shop['id_toko']]);
        $ulasan = $reviewStmt->fetchAll();
        $kategori = $pdo->query('SELECT id_kategori AS id, nama FROM kategori ORDER BY id_kategori')->fetchAll();
    } else {
        $toko['catatan_admin'] = 'Akun ini belum memiliki profil toko.';
    }
}

$RT = [
    'serverNow' => $now, 'pengaturan' => $pengaturan, 'penjual' => ['nama' => $nama], 'toko' => $toko, 'langganan' => $langganan,
    'kategori' => $kategori, 'paket' => $paket, 'pesanan' => $pesanan, 'ulasan' => $ulasan,
    'backendReady' => $backendReady, 'csrf' => $_SESSION['csrf'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard Penjual &ndash; ReTaste</title>
<link rel="icon" type="image/png" href="assets/img/favicon.png">
<link rel="preload" href="assets/fonts/baloo-2-latin-700-normal.woff2" as="font" type="font/woff2" crossorigin>
<style>
/* =========================================================
   ReTaste - dashboard.css (Dashboard Pelanggan)
   Struktur layout mengikuti dashboard GEND-OFF, warna mengikuti palet ReTaste:
   Amber Flame #F7A503 | Sunny Wheat #FFDA8C | Vanilla Cream #FFEBBC
   Olive Meadow #B3BC53 | Forest Moss #768C3A
   ========================================================= */
@font-face{font-family:"Baloo 2";font-weight:600;font-display:swap;src:url("assets/fonts/baloo-2-latin-600-normal.woff2") format("woff2")}
@font-face{font-family:"Baloo 2";font-weight:700;font-display:swap;src:url("assets/fonts/baloo-2-latin-700-normal.woff2") format("woff2")}
@font-face{font-family:"Baloo 2";font-weight:800;font-display:swap;src:url("assets/fonts/baloo-2-latin-800-normal.woff2") format("woff2")}
@font-face{font-family:"Nunito";font-weight:400;font-display:swap;src:url("assets/fonts/nunito-latin-400-normal.woff2") format("woff2")}
@font-face{font-family:"Nunito";font-weight:600;font-display:swap;src:url("assets/fonts/nunito-latin-600-normal.woff2") format("woff2")}
@font-face{font-family:"Nunito";font-weight:700;font-display:swap;src:url("assets/fonts/nunito-latin-700-normal.woff2") format("woff2")}

:root{
  /* palet utama */
  --amber:#F7A503;--wheat:#FFDA8C;--cream:#FFEBBC;--olive:#B3BC53;--moss:#768C3A;
  /* turunan gelap dari Forest Moss (untuk sidebar, teks, kontras) */
  --dark:#2E3A12;--dark2:#4A5C22;--dark3:#1D2509;
  --tint:#FFF1CC;--tint2:#FFE3A0;
  --bg:#FFF8E6;--surface:#fff;--border:#F0E4C0;--border-soft:#F7EFD6;
  --text:#2B3510;--muted:#636D3C;--faint:#8A9360;
  --red:#C8432B;--red-bg:#FCE9E4;--amber-d:#8A5A00;--amber-bg:#FFF0C7;--green:#3F7A25;--green-bg:#E8F3D6;--blue:#2E7C9A;--blue-bg:#E3F1F6;
  --radius:16px;--shadow:0 1px 2px rgba(46,58,18,.06),0 1px 1px rgba(46,58,18,.04);--shadow-md:0 10px 30px -10px rgba(46,58,18,.22);
  --sw:256px;--head:"Baloo 2","Trebuchet MS",system-ui,sans-serif;--body:"Nunito",system-ui,-apple-system,"Segoe UI",sans-serif;
  --ease:cubic-bezier(.2,.7,.2,1)
}
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
body{font-family:var(--body);color:var(--text);background:var(--bg);-webkit-font-smoothing:antialiased;line-height:1.55;font-size:16px}
a{color:inherit;text-decoration:none}
button{font-family:inherit;cursor:pointer;border:0;background:none;color:inherit}
input,select,textarea{font:inherit;color:inherit}
img{max-width:100%;display:block}
::-webkit-scrollbar{width:9px;height:9px}::-webkit-scrollbar-thumb{background:#E6D9AE;border-radius:8px}
:focus-visible{outline:3px solid var(--amber);outline-offset:2px;border-radius:8px}
h1,h2,h3,h4{font-family:var(--head);font-weight:700;color:var(--dark);line-height:1.15}
body{background:radial-gradient(900px 520px at 100% -6%,rgba(247,165,3,.14),transparent 60%),radial-gradient(760px 480px at -8% 16%,rgba(179,188,83,.18),transparent 55%),var(--bg);background-attachment:fixed}

/* ===== LAYOUT ===== */
.app{display:flex;min-height:100vh}
.sidebar{width:var(--sw);flex-shrink:0;position:sticky;top:0;height:100vh;display:flex;flex-direction:column;z-index:80;color:#fff;overflow:hidden;
  background:linear-gradient(200deg,rgba(247,165,3,.2),transparent 38%),linear-gradient(165deg,var(--dark3) 0%,var(--dark) 52%,var(--dark2) 100%);
  box-shadow:12px 0 40px -20px rgba(29,37,9,.6);transition:transform .3s var(--ease)}
.sidebar::after{content:"";position:absolute;left:-60px;bottom:-80px;width:240px;height:240px;border-radius:50%;pointer-events:none;background:radial-gradient(circle,rgba(247,165,3,.34),transparent 68%);animation:breathe 6s ease-in-out infinite}
@keyframes breathe{50%{transform:scale(1.18);opacity:.7}}
.sidebar>*{position:relative;z-index:1}
.brand{display:flex;align-items:center;gap:12px;padding:20px 18px 18px;border-bottom:1px solid rgba(255,255,255,.1)}
.brand-mark{width:58px;height:58px;padding:0;border-radius:0;flex-shrink:0;display:grid;place-items:center;background:none;box-shadow:none;transition:transform .35s var(--ease)}
.brand:hover .brand-mark{transform:rotate(-6deg) scale(1.06)}
.brand-mark img{width:100%;height:100%;object-fit:contain;background:none;filter:drop-shadow(0 4px 10px rgba(0,0,0,.35))}
.brand b{display:block;font-family:var(--head);font-size:1.55rem;font-weight:800;color:var(--amber);line-height:1}
.brand b span{color:var(--olive)}
.brand small{display:block;font-size:.62rem;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:rgba(255,255,255,.62);margin-top:5px}
.nav{flex:1;overflow-y:auto;padding:10px 12px 14px}
.nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.18)}
.nav>*{animation:slideIn .55s var(--ease) backwards;animation-delay:calc(var(--i,0)*45ms + .1s)}
@keyframes slideIn{from{opacity:0;transform:translateX(-16px)}}
.nav-label{font-size:.66rem;text-transform:uppercase;letter-spacing:.9px;color:rgba(255,255,255,.42);font-weight:800;padding:16px 10px 6px}
.nav-item{position:relative;display:flex;align-items:center;gap:11px;width:100%;padding:10px 11px;margin:2px 0;border-radius:10px;font-size:.92rem;font-weight:700;color:rgba(255,255,255,.8);text-align:left;transition:background .15s,color .15s,transform .15s}
.nav-item:hover{background:rgba(255,255,255,.09);color:#fff;transform:translateX(3px)}
.nav-item.active{background:linear-gradient(90deg,rgba(247,165,3,.3),rgba(247,165,3,.07));color:#fff;box-shadow:inset 0 0 0 1px rgba(247,165,3,.28)}
.nav-item.active::before{content:"";position:absolute;left:-12px;top:8px;bottom:8px;width:3px;border-radius:0 4px 4px 0;background:var(--amber)}
.nav-item .ic{width:19px;height:19px;flex-shrink:0;display:flex}
.nav-item .ic svg{width:100%;height:100%}
.nav-item.active .ic{color:var(--amber)}
.nav-item .cnt{margin-left:auto;min-width:22px;height:22px;padding:0 7px;border-radius:99px;background:var(--amber);color:#2B1D00;font-size:.72rem;font-weight:800;display:grid;place-items:center}
.nav-item .cnt:empty{display:none}
.side-foot{padding:12px 14px 16px;border-top:1px solid rgba(255,255,255,.1)}
.logout{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;font-size:.88rem;font-weight:700;color:rgba(255,255,255,.88);border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);transition:.15s}
.logout:hover{color:#FFD9D2;border-color:rgba(200,67,43,.55);background:rgba(200,67,43,.2)}
.logout svg{width:16px;height:16px}
.side-ver{font-size:.68rem;color:rgba(255,255,255,.42);margin:10px 4px 0;line-height:1.6}
.scrim{position:fixed;inset:0;background:rgba(29,37,9,.4);z-index:70;opacity:0;pointer-events:none;transition:.25s}

.main{flex:1;min-width:0;display:flex;flex-direction:column}
.topbar{position:sticky;top:0;z-index:50;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 30px;background:rgba(255,255,255,.82);backdrop-filter:blur(14px) saturate(160%);-webkit-backdrop-filter:blur(14px) saturate(160%);border-bottom:1px solid var(--border)}
.tb-l{display:flex;align-items:center;gap:12px}
.burger{display:none;width:42px;height:42px;border-radius:10px;border:1px solid var(--border);align-items:center;justify-content:center;background:#fff}
.burger svg{width:18px;height:18px}
.tb-title{font-family:var(--head);font-size:1.3rem;font-weight:700;color:var(--dark);line-height:1.1}
.tb-sub{font-size:.8rem;color:var(--muted)}
.tb-r{display:flex;align-items:center;gap:12px}
.clk{display:flex;align-items:center;gap:9px;padding:5px 13px 5px 6px;border-radius:11px;color:#fff;background:linear-gradient(150deg,var(--dark2),var(--dark3));box-shadow:0 10px 20px -12px rgba(29,37,9,.75)}
.clk-ic{width:26px;height:26px;border-radius:8px;display:grid;place-items:center;flex-shrink:0;background:rgba(255,255,255,.12);color:var(--amber)}
.clk-ic svg{width:14px;height:14px}
.clk b{display:block;font-family:var(--head);font-size:.95rem;font-weight:700;letter-spacing:.5px;line-height:1.1;color:#fff}
.clk small{display:block;font-size:.64rem;font-weight:700;color:rgba(255,255,255,.72);line-height:1.25}
.chip-user{display:flex;align-items:center;gap:10px;padding:5px 14px 5px 5px;border-radius:99px;background:#fff;border:1px solid var(--border);box-shadow:var(--shadow)}
.av{position:relative;width:34px;height:34px;border-radius:50%;display:grid;place-items:center;font-family:var(--head);font-weight:800;font-size:.95rem;color:#fff;background:linear-gradient(155deg,var(--moss),var(--dark))}
.av::after{content:"";position:absolute;right:-1px;bottom:-1px;width:10px;height:10px;border-radius:50%;background:var(--amber);border:2px solid #fff}
.chip-user b{display:block;font-size:.84rem;color:var(--dark);line-height:1.2}
.chip-user small{font-size:.7rem;color:var(--faint);font-weight:600}
.content{padding:26px 30px 60px;max-width:1360px;width:100%;margin:0 auto}
.page{display:none}.page.active{display:block}
.rise{animation:rise .6s var(--ease) backwards;animation-delay:calc(var(--i,0)*70ms)}
@keyframes rise{from{opacity:0;transform:translateY(16px)}}
.sec-h{margin-bottom:20px}
.sec-h h1{font-size:1.7rem;margin-bottom:4px}
.sec-h p{font-size:.92rem;color:var(--muted);max-width:640px}

/* ===== KOMPONEN ===== */
.card{background:linear-gradient(165deg,#fff 0%,#FFFDF6 100%);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:22px;transition:box-shadow .2s,border-color .2s,transform .2s}
.card.hov:hover{transform:translateY(-4px);box-shadow:0 24px 44px -20px rgba(46,58,18,.32);border-color:var(--tint2)}
.card-h{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.card-t{font-family:var(--head);font-size:1.12rem;font-weight:700;color:var(--dark)}
.card-s{font-size:.78rem;color:var(--faint);font-weight:600;margin-top:2px}
.g4{display:grid;grid-template-columns:repeat(4,1fr);gap:18px}
.g2{display:grid;grid-template-columns:1.5fr 1fr;gap:18px}
.mb{margin-bottom:18px}
.tag{display:inline-block;font-size:.72rem;font-weight:800;padding:3px 10px;border-radius:99px;background:var(--tint);color:var(--dark)}
.tag.moss{background:var(--moss);color:#fff}
.tag.amber{background:var(--amber);color:#2B1D00}
.link{font-weight:800;font-size:.85rem;color:var(--moss);display:inline-flex;align-items:center;gap:4px}
.link{white-space:nowrap}.link svg{width:16px;height:16px}
.link:hover{color:var(--dark);text-decoration:underline}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 18px;border-radius:10px;font-size:.88rem;font-weight:800;border:1px solid transparent;transition:.15s;white-space:nowrap}
.btn svg{width:15px;height:15px}
.btn:active{transform:scale(.98)}
.btn[disabled]{opacity:.5;cursor:not-allowed;transform:none!important}
.btn-p{position:relative;overflow:hidden;background:linear-gradient(135deg,var(--dark),var(--dark2));color:#fff;box-shadow:0 4px 12px rgba(46,58,18,.22)}
.btn-p:hover{transform:translateY(-1px)}
.btn-p::after{content:"";position:absolute;top:0;left:-80%;width:50%;height:100%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.3),transparent);transform:skewX(-20deg);transition:left .6s}
.btn-p:hover::after{left:130%}
.btn-l{background:var(--amber);color:#2B1D00;box-shadow:0 10px 22px rgba(0,0,0,.22)}
.btn-l:hover{background:#FFB41F;transform:translateY(-2px)}
.btn-o{background:#fff;border-color:var(--border);color:var(--text)}
.btn-o:hover{border-color:var(--moss);color:var(--dark)}
.btn-g{background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.35);color:#fff}
.btn-g:hover{background:rgba(255,255,255,.22)}
.btn-d{background:#fff;border-color:#F0C3B9;color:var(--red)}
.btn-d:hover{background:var(--red-bg)}
.btn-w{width:100%;padding:12px}
.btn-sm{padding:7px 13px;font-size:.8rem}

.badge{display:inline-flex;align-items:center;gap:5px;font-size:.72rem;font-weight:800;padding:4px 11px;border-radius:99px;white-space:nowrap}
.b-wait{background:var(--amber-bg);color:var(--amber-d)}
.b-ready{background:var(--blue-bg);color:var(--blue)}
.b-done{background:var(--green-bg);color:var(--green)}
.b-off{background:var(--red-bg);color:var(--red)}
.b-gray{background:#F1ECDB;color:#6F6A4E}

/* ---------- Hero ---------- */
.hero-card{position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:28px;flex-wrap:wrap;min-height:250px;padding:32px 36px;margin-bottom:20px;border-radius:24px;color:#fff;
  background:radial-gradient(480px 240px at 100% 0%,rgba(247,165,3,.22),transparent 70%),linear-gradient(135deg,var(--dark2) 0%,var(--dark) 60%,var(--dark3) 100%);
  border:1px solid rgba(255,255,255,.08);box-shadow:0 26px 50px -26px rgba(29,37,9,.7)}
.hero-txt{position:relative;z-index:2;max-width:540px}
.hero-card h1{font-weight:800;font-size:clamp(1.7rem,3vw,2.4rem);letter-spacing:-.2px;line-height:1.15;color:#fff;margin-bottom:8px}
.hero-card h1 em{font-style:normal;color:var(--amber)}
.hero-card p{color:rgba(255,255,255,.85);font-size:.96rem;max-width:460px;margin-bottom:16px}
.hero-media{position:absolute;top:0;right:0;width:54%;height:100%;object-fit:cover;object-position:center;z-index:0;pointer-events:none;
  -webkit-mask-image:linear-gradient(90deg,transparent 0%,rgba(0,0,0,.55) 30%,#000 62%);mask-image:linear-gradient(90deg,transparent 0%,rgba(0,0,0,.55) 30%,#000 62%)}
.hero-card::after{content:"";position:absolute;inset:0;z-index:1;pointer-events:none;background:linear-gradient(90deg,transparent 40%,rgba(29,37,9,.4) 100%)}
.hmeta{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
.hm{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:99px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);font-size:.78rem;font-weight:700;color:rgba(255,255,255,.9)}
.hm b{font-family:var(--head);font-weight:700;color:var(--amber);font-size:.92rem}
.hm svg{width:14px;height:14px;color:var(--amber)}
.hero-btns{display:flex;gap:10px;flex-wrap:wrap}

/* ---------- Stat ---------- */
.stat{position:relative;overflow:hidden;cursor:pointer;user-select:none;background:radial-gradient(170px 120px at 100% 0%,var(--tint),transparent 70%),#fff;text-align:left;display:block;width:100%}
.stat-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.stat-l{font-size:.84rem;font-weight:800;color:var(--muted)}
.stat-ic{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;transition:transform .4s var(--ease)}
.stat:hover .stat-ic{transform:rotate(-8deg) scale(1.1)}
.stat-ic svg{width:19px;height:19px}
.ic-g{background:var(--green-bg);color:var(--green)}.ic-b{background:var(--blue-bg);color:var(--blue)}.ic-a{background:var(--amber-bg);color:var(--amber-d)}.ic-r{background:var(--red-bg);color:var(--red)}
.stat-v{font-family:var(--head);font-size:2.2rem;font-weight:700;color:var(--dark);line-height:1.1}
.stat-v small{font-family:var(--body);font-size:.82rem;color:var(--faint);font-weight:700}
.stat-n{font-size:.78rem;color:var(--muted);margin-top:6px;font-weight:600}
.stat.hl{background:radial-gradient(190px 130px at 100% 0%,rgba(247,165,3,.4),transparent 65%),linear-gradient(150deg,var(--dark2),var(--dark3));border-color:transparent;box-shadow:0 20px 38px -20px rgba(29,37,9,.8)}
.stat.hl .stat-l{color:rgba(255,255,255,.78)}.stat.hl .stat-v{color:#fff}.stat.hl .stat-v small,.stat.hl .stat-n{color:rgba(255,255,255,.75)}
.stat.hl .stat-ic{background:rgba(255,255,255,.14);color:var(--amber)}

/* ---------- Gambar ilustrasi (slot gambar dengan fallback) ---------- */
.ph{position:relative;overflow:hidden;background:linear-gradient(135deg,var(--tint2),var(--cream));display:grid;place-items:center;color:var(--moss)}
.ph svg{width:38px;height:38px;opacity:.55}
.ph>img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.ph.k-roti{background:linear-gradient(135deg,#FFDA8C,#F7C766)}
.ph.k-nasi{background:linear-gradient(135deg,#E1E79B,#B3BC53)}
.ph.k-min{background:linear-gradient(135deg,#FFEBBC,#F7D58A)}
.ph.k-cam{background:linear-gradient(135deg,#D8DE93,#A9B54A)}

/* ---------- Kartu paket ---------- */
.pk-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.pk{display:flex;flex-direction:column;padding:0;overflow:hidden}
.pk-img{position:relative;height:170px}
.pk-img .ph{height:100%}
.pk-off{position:absolute;left:12px;top:12px;z-index:2;background:var(--amber);color:#2B1D00;font-family:var(--head);font-weight:800;font-size:.95rem;padding:2px 12px;border-radius:99px;box-shadow:0 6px 14px -6px rgba(0,0,0,.4)}
.fav{position:absolute;right:10px;top:10px;z-index:2;width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.92);display:grid;place-items:center;color:var(--moss);box-shadow:0 6px 14px -6px rgba(0,0,0,.35);transition:transform .2s}
.fav:hover{transform:scale(1.12)}
.fav svg{width:18px;height:18px}
.fav.on{color:var(--red)}.fav.on svg{fill:currentColor}
.pk-b{padding:16px 18px 18px;display:flex;flex-direction:column;gap:8px;flex:1}
.pk-toko{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:.78rem;font-weight:700;color:var(--muted)}
.pk-toko .st{color:var(--amber-d)}
.pk h3{font-size:1.15rem}
.pk-price{display:flex;align-items:baseline;gap:8px}
.pk-price b{font-family:var(--head);font-size:1.5rem;color:var(--dark)}
.pk-price s{color:var(--faint);font-size:.88rem;font-weight:700}
.pk-meta{display:grid;gap:4px;font-size:.8rem;font-weight:600;color:var(--muted)}
.pk-meta div{display:flex;align-items:center;gap:7px}
.pk-meta svg{width:15px;height:15px;color:var(--moss);flex-shrink:0}
.pk .btn{margin-top:auto}
.low{color:var(--red)!important;font-weight:800}

/* ---------- Filter ---------- */
.filters{display:grid;grid-template-columns:repeat(4,1fr) auto;gap:12px;align-items:end;margin-bottom:18px}
.field{display:flex;flex-direction:column;gap:6px}.field.full{grid-column:1/-1}
.field label{font-size:.8rem;font-weight:800;color:var(--dark)}
.field input,.field select,.field textarea{border:1.5px solid var(--border);border-radius:10px;padding:10px 13px;font-size:.9rem;background:#fff;outline:none;transition:border-color .15s,box-shadow .15s;width:100%}
.field textarea{resize:vertical;min-height:92px}
.field input:hover,.field select:hover,.field textarea:hover{border-color:var(--wheat)}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--moss);box-shadow:0 0 0 3px rgba(118,140,58,.18)}
.field input[readonly]{background:var(--tint);font-weight:700;cursor:not-allowed}
.field select{appearance:none;background-image:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="%23636D3C" stroke-width="2"><path d="M5.5 7.5l4.5 4.5 4.5-4.5"/></svg>');background-repeat:no-repeat;background-position:right 12px center;background-size:14px;padding-right:34px}
.fhint{font-size:.76rem;color:var(--faint);font-weight:600}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 18px}
.loc-bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 16px;border-radius:14px;background:var(--tint);border:1px solid var(--tint2);margin-bottom:18px;font-size:.88rem;font-weight:700;color:var(--dark)}
.loc-bar svg{width:18px;height:18px;color:var(--moss)}
.loc-bar .btn{margin-left:auto}

/* ---------- Tab & daftar pesanan ---------- */
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
.tab{padding:9px 16px;border-radius:99px;border:1.5px solid var(--border);background:#fff;font-size:.86rem;font-weight:800;color:var(--muted);transition:.15s}
.tab:hover{border-color:var(--moss);color:var(--dark)}
.tab.on{background:linear-gradient(135deg,var(--dark),var(--dark2));border-color:var(--dark);color:#fff;box-shadow:0 6px 14px -6px rgba(46,58,18,.55)}
.tab small{opacity:.8;margin-left:4px;font-weight:700}
.ord-list{display:grid;gap:14px}
.ord{display:grid;grid-template-columns:84px 1fr auto;gap:16px;align-items:center;padding:16px 18px}
.ord .ph{width:84px;height:84px;border-radius:14px}
.ord-main h3{font-size:1.1rem;margin:2px 0}
.ord-kode{font-size:.74rem;font-weight:800;color:var(--faint);letter-spacing:.4px}
.ord-info{display:flex;flex-wrap:wrap;gap:4px 14px;font-size:.82rem;color:var(--muted);font-weight:600;margin-top:4px}
.ord-info span{display:inline-flex;align-items:center;gap:5px}
.ord-info svg{width:14px;height:14px;color:var(--moss)}
.ord-side{display:flex;flex-direction:column;align-items:flex-end;gap:8px}
.ord-side .tot{font-family:var(--head);font-size:1.25rem;font-weight:700;color:var(--dark)}
.ord-act{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.cd{font-family:var(--head);font-weight:700;color:var(--red)}
.code-pill{display:inline-flex;align-items:center;gap:8px;margin-top:8px;padding:6px 12px;border-radius:10px;background:var(--dark);color:#fff;font-family:var(--head);font-weight:700;letter-spacing:3px;font-size:1.05rem}
.code-pill small{font-family:var(--body);letter-spacing:.4px;font-size:.68rem;font-weight:800;color:var(--wheat)}
.empty{display:grid;justify-items:center;text-align:center;gap:10px;padding:38px 20px;color:var(--muted)}
.empty .ph{width:170px;height:130px;border-radius:20px}
.empty h3{font-size:1.2rem}
.empty p{font-size:.9rem;max-width:380px}

/* ---------- Kartu info samping ---------- */
.impact{position:relative;overflow:hidden;border-radius:14px;margin-bottom:14px;height:120px}
.impact .ph{height:100%}
.impact-t{position:absolute;inset:0;z-index:2;display:flex;flex-direction:column;justify-content:flex-end;padding:12px 14px;color:#fff;background:linear-gradient(0deg,rgba(29,37,9,.78),transparent 70%)}
.impact-t b{font-family:var(--head);font-size:1.05rem;line-height:1.2}
.kv{display:grid;gap:9px}
.kv div{display:flex;justify-content:space-between;gap:10px;padding:10px 13px;border-radius:10px;background:var(--bg);border:1px solid var(--border-soft);font-size:.86rem}
.kv span{color:var(--muted);font-weight:700}.kv b{color:var(--dark);text-align:right}
.insight{display:flex;gap:12px;padding:14px;border-radius:12px;background:linear-gradient(135deg,var(--tint),#fff);border:1px solid var(--tint2)}
.insight+.insight{margin-top:10px}
.insight .ii{width:34px;height:34px;border-radius:10px;background:#fff;color:var(--amber-d);display:grid;place-items:center;flex-shrink:0}
.insight .ii svg{width:17px;height:17px}
.insight b{display:block;color:var(--dark);font-size:.9rem;margin-bottom:2px}
.insight p{font-size:.82rem;color:var(--muted);line-height:1.5}

/* ---------- Grafik batang ---------- */
#chart svg{width:100%;height:auto;display:block;overflow:visible}
.bar-r{fill:url(#bg1);transform-origin:bottom;animation:growY .9s var(--ease) backwards;transform-box:fill-box}
@keyframes growY{from{transform:scaleY(0)}}
.gridl{stroke:var(--border);stroke-dasharray:3 4}
.axl{font-size:11px;fill:var(--faint);font-weight:700;font-family:var(--body)}
.vl{font-size:11px;fill:var(--dark);font-weight:800;font-family:var(--body)}

/* ---------- Bintang ---------- */
.stars{display:inline-flex;gap:2px}
.stars button{width:34px;height:34px;color:#D8CFA6;transition:transform .15s,color .15s}
.stars button svg{width:100%;height:100%}
.stars button.on{color:var(--amber)}.stars button.on svg{fill:currentColor}
.stars button:hover{transform:scale(1.15)}
.stars.ro button{width:18px;height:18px;pointer-events:none}

/* ---------- Modal ---------- */
.modal{position:fixed;inset:0;z-index:200;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(29,37,9,.55);backdrop-filter:blur(3px)}
.modal.show{display:flex;animation:fade .2s ease}
@keyframes fade{from{opacity:0}}
.mbox{position:relative;width:min(560px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:22px;box-shadow:0 40px 80px -30px rgba(0,0,0,.6);animation:pop .3s var(--ease)}
.mbox.wide{width:min(760px,100%)}
@keyframes pop{from{opacity:0;transform:translateY(16px) scale(.98)}}
.mx{position:absolute;right:12px;top:12px;z-index:5;width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.92);display:grid;place-items:center;box-shadow:0 4px 12px -4px rgba(0,0,0,.35)}
.mx svg{width:16px;height:16px}
.m-img{height:210px}
.m-img .ph{height:100%}
.m-body{padding:22px 24px 24px}
.m-body h2{font-size:1.6rem;margin-bottom:4px}
.m-body>p{font-size:.92rem;color:var(--muted);margin-bottom:12px}
.chips{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 14px}
.chip-al{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:99px;font-size:.76rem;font-weight:800;background:var(--red-bg);color:var(--red)}
.chip-al.aman{background:var(--green-bg);color:var(--green)}
.chip-i{padding:4px 11px;border-radius:99px;background:var(--bg);border:1px solid var(--border);font-size:.78rem;font-weight:700;color:var(--muted)}
.sum{display:grid;gap:7px;padding:14px;border-radius:14px;background:var(--bg);border:1px solid var(--border-soft);margin:14px 0}
.sum div{display:flex;justify-content:space-between;font-size:.88rem;font-weight:700;color:var(--muted)}
.sum div b{color:var(--dark)}
.sum .tt{border-top:1px dashed var(--wheat);padding-top:8px;margin-top:2px;font-size:1rem}
.sum .tt b{font-family:var(--head);font-size:1.4rem}
.sum .dc b{color:var(--green)}
.stepper{display:inline-flex;align-items:center;gap:4px;border:1.5px solid var(--border);border-radius:12px;padding:3px}
.stepper button{width:34px;height:34px;border-radius:9px;font-size:1.2rem;font-weight:800;color:var(--dark)}
.stepper button:hover:not([disabled]){background:var(--tint)}
.stepper button[disabled]{opacity:.35;cursor:not-allowed}
.stepper b{min-width:30px;text-align:center;font-family:var(--head);font-size:1.2rem}
.rowx{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.note{display:flex;gap:10px;padding:12px 14px;border-radius:12px;background:var(--amber-bg);border:1px solid #F3DC99;font-size:.82rem;color:var(--amber-d);font-weight:700;margin-top:12px}
.note svg{width:17px;height:17px;flex-shrink:0;margin-top:2px}
.note.ok{background:var(--green-bg);border-color:#CDE3A8;color:var(--green)}
.qr-box{display:grid;justify-items:center;gap:10px;padding:22px 16px;border-radius:18px;background:linear-gradient(160deg,var(--tint),#fff);border:1px dashed var(--olive);margin:12px 0}
.qr-box .qr{background:#fff;padding:12px;border-radius:14px;box-shadow:var(--shadow-md);line-height:0}
.qr-box .qr svg,.qr-box .qr img{width:170px;height:170px}
.qr-box .code{font-family:var(--head);font-size:2.2rem;font-weight:800;letter-spacing:8px;color:var(--dark);padding-left:8px}
.qr-box small{font-weight:700;color:var(--muted);font-size:.8rem;text-align:center}
.paylist{display:grid;gap:10px;margin:12px 0}
.pay{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1.5px solid var(--border);border-radius:12px;font-weight:800;font-size:.92rem;cursor:pointer;transition:.15s}
.pay:hover{border-color:var(--moss)}
.pay input{accent-color:var(--moss);width:18px;height:18px}
.pay small{margin-left:auto;font-weight:700;color:var(--faint)}

@keyframes tin{from{opacity:0;transform:translateX(30px)}}

/* ---------- Riwayat & profil ---------- */
.rev{margin-top:8px;padding:10px 12px;border-radius:10px;background:var(--bg);border:1px solid var(--border-soft);font-size:.84rem;color:var(--muted);font-weight:600}
.prof-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.stat-vrf{display:flex;align-items:center;gap:8px;font-size:.84rem;font-weight:800}

/* ---------- Responsif ---------- */
@media(max-width:1180px){.g4{grid-template-columns:1fr 1fr}.g2{grid-template-columns:1fr}.pk-grid{grid-template-columns:1fr 1fr}.filters{grid-template-columns:1fr 1fr}.prof-grid{grid-template-columns:1fr}}
@media(max-width:900px){
  .sidebar{position:fixed;left:0;top:0;transform:translateX(-100%)}.sidebar.open{transform:none}
  .scrim.show{opacity:1;pointer-events:auto}
  .burger{display:flex}.chip-user div{display:none}.chip-user{padding:5px}
  .clk small,.clk-ic{display:none}.clk{padding:8px 12px}
  .topbar{padding:12px 16px}.content{padding:18px 16px 50px}
  .hero-media{width:100%;opacity:.3}.hero-card{padding:24px 20px}
  .form-grid{grid-template-columns:1fr}
  .ord{grid-template-columns:70px 1fr}.ord .ph{width:70px;height:70px}.ord-side{grid-column:1/-1;flex-direction:row;align-items:center;justify-content:space-between;flex-wrap:wrap}
}
@media(max-width:640px){.g4,.pk-grid,.filters{grid-template-columns:1fr}.sec-h h1{font-size:1.4rem}.hero-btns .btn{flex:1}}
@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}

/* ReTaste - penjual.css (tambahan untuk Dashboard Penjual; dimuat setelah dashboard.css) */
.banner{display:flex;gap:14px;align-items:flex-start;padding:16px 20px;border-radius:16px;margin-bottom:18px;border:1px solid transparent}
.banner svg{width:22px;height:22px;flex-shrink:0;margin-top:2px}
.banner b{display:block;font-family:var(--head);font-size:1.1rem;color:var(--dark);margin-bottom:2px}
.banner p{font-size:.88rem;color:var(--muted);margin:0}
.banner .btn{margin-top:10px}
.bn-wait{background:var(--amber-bg);border-color:#F3DC99;color:var(--amber-d)}
.bn-off{background:var(--red-bg);border-color:#F0C3B9;color:var(--red)}
.bn-ok{background:var(--green-bg);border-color:#CDE3A8;color:var(--green)}

.ver{display:grid;grid-template-columns:1fr auto;gap:20px;align-items:center;margin-bottom:18px;background:radial-gradient(260px 160px at 100% 0%,rgba(247,165,3,.35),transparent 70%),linear-gradient(135deg,var(--dark2),var(--dark3));color:#fff;border-radius:22px;padding:24px 28px;border:0}
.ver h2,.ver .card-t{color:#fff}
.ver p{color:rgba(255,255,255,.82);font-size:.9rem;margin-top:4px;max-width:46ch}
.ver-form{display:flex;gap:10px;flex-wrap:wrap}
.ver-in{width:200px;padding:12px 14px;border-radius:12px;border:2px solid rgba(255,255,255,.3);background:rgba(255,255,255,.12);color:#fff;font-family:var(--head);font-weight:800;font-size:1.6rem;letter-spacing:7px;text-align:center;text-transform:uppercase;outline:none}
.ver-in::placeholder{color:rgba(255,255,255,.4);letter-spacing:7px}
.ver-in:focus{border-color:var(--amber);background:rgba(255,255,255,.18)}
.ver-res{grid-column:1/-1;padding:12px 16px;border-radius:12px;font-weight:800;font-size:.9rem;display:none}
.ver-res.show{display:block}
.ver-res.ok{background:rgba(232,243,214,.95);color:var(--green)}
.ver-res.bad{background:rgba(252,233,228,.97);color:var(--red)}

.ck-row{display:flex;flex-wrap:wrap;gap:8px}
.ck-chip{position:relative}
.ck-chip input{position:absolute;opacity:0;inset:0;cursor:pointer}
.ck-chip span{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:99px;border:1.5px solid var(--border);font-weight:800;font-size:.84rem;color:var(--muted);transition:.15s;background:#fff}
.ck-chip input:checked+span{background:var(--dark);border-color:var(--dark);color:#fff}
.ck-chip input:focus-visible+span{outline:3px solid var(--amber);outline-offset:2px}
.ck-line{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:12px;background:var(--tint);border:1px solid var(--tint2);font-size:.86rem;font-weight:700;color:var(--dark);cursor:pointer}
.ck-line input{accent-color:var(--moss);width:18px;height:18px;margin-top:2px;flex-shrink:0}
.field.err input,.field.err select,.field.err textarea{border-color:var(--red);box-shadow:0 0 0 3px rgba(200,67,43,.12)}
.field .em{font-size:.76rem;color:var(--red);font-weight:800;min-height:0}
.seg{display:inline-flex;padding:3px;border-radius:99px;background:var(--bg);border:1px solid var(--border)}
.seg label{position:relative}
.seg input{position:absolute;opacity:0;inset:0;cursor:pointer}
.seg span{display:block;padding:7px 16px;border-radius:99px;font-size:.84rem;font-weight:800;color:var(--muted);transition:.2s}
.seg input:checked+span{background:linear-gradient(135deg,var(--dark),var(--dark2));color:#fff}
.seg input:focus-visible+span{outline:3px solid var(--amber)}
.upl{display:flex;gap:14px;align-items:center}
.upl .ph{width:96px;height:72px;border-radius:12px;flex-shrink:0}
.sec-t{font-family:var(--head);font-weight:700;font-size:1.05rem;color:var(--dark);margin:6px 0 -4px;grid-column:1/-1}

.pkm{display:grid;grid-template-columns:110px 1fr auto;gap:16px;align-items:center;padding:14px 18px}
.pkm .ph{width:110px;height:84px;border-radius:14px}
.pkm h3{font-size:1.12rem;margin:2px 0}
.pkm-meta{display:flex;flex-wrap:wrap;gap:4px 14px;font-size:.82rem;font-weight:600;color:var(--muted);margin-top:4px}
.pkm-meta span{display:inline-flex;align-items:center;gap:5px}
.pkm-meta svg{width:14px;height:14px;color:var(--moss)}
.pkm-side{display:flex;flex-direction:column;align-items:flex-end;gap:8px}
.pkm-price{font-family:var(--head);font-weight:700;font-size:1.2rem;color:var(--dark)}
.pkm-price s{font-family:var(--body);font-size:.8rem;color:var(--faint);margin-left:6px}
.prog{height:7px;border-radius:99px;background:var(--border-soft);overflow:hidden;margin-top:8px;max-width:260px}
.prog i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,var(--moss),var(--olive))}

.tbl-w{overflow-x:auto}
.tbl{width:100%;border-collapse:collapse;font-size:.88rem;min-width:560px}
.tbl th{text-align:left;padding:11px 14px;background:var(--dark2);color:#fff;font-size:.74rem;text-transform:uppercase;letter-spacing:.6px}
.tbl th:first-child{border-radius:10px 0 0 10px}.tbl th:last-child{border-radius:0 10px 10px 0}
.tbl th.r,.tbl td.r{text-align:right}
.tbl td{padding:11px 14px;border-bottom:1px solid var(--border-soft);color:var(--muted);font-weight:600}
.tbl td:first-child{color:var(--dark);font-weight:800}
.tbl tfoot td{background:var(--tint);color:var(--dark);font-weight:800;border-bottom:0}
.lock{position:relative;overflow:hidden}
.lock-ov{display:grid;justify-items:center;text-align:center;gap:8px;padding:26px 16px;border-radius:14px;background:repeating-linear-gradient(135deg,var(--tint),var(--tint) 10px,#FFF8E6 10px,#FFF8E6 20px);border:1px dashed var(--amber)}
.lock-ov svg{width:30px;height:30px;color:var(--amber-d)}
.perks{display:grid;gap:10px;margin:14px 0 18px}
.perks li{list-style:none;display:flex;gap:10px;align-items:flex-start;font-weight:700;font-size:.92rem}
.perks svg{width:20px;height:20px;padding:3px;border-radius:50%;background:var(--green-bg);color:var(--green);flex-shrink:0;margin-top:2px}
.pro-card{background:radial-gradient(260px 160px at 100% 0%,rgba(247,165,3,.3),transparent 70%),linear-gradient(150deg,var(--dark2),var(--dark3));color:#fff;border:0}
.pro-card .card-t,.pro-card h2{color:#fff}
.pro-card .card-s{color:rgba(255,255,255,.7)}
.pro-card .perks li{color:#fff}
.pro-card .perks svg{background:rgba(247,165,3,.25);color:var(--amber)}
.badge-pro{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;background:var(--amber);color:#2B1D00;font-weight:800;font-size:.72rem}
.rev-it{padding:12px 14px;border-radius:12px;background:var(--bg);border:1px solid var(--border-soft);margin-bottom:10px;font-size:.86rem;color:var(--muted);font-weight:600}
.rev-it b{color:var(--dark)}
.rev-it .stars{display:inline-flex;vertical-align:-3px;margin-left:6px}
.g3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.stat-v.sm{font-size:1.8rem}

@media(max-width:1180px){.g3{grid-template-columns:1fr 1fr}.ver{grid-template-columns:1fr}}
@media(max-width:900px){.pkm{grid-template-columns:84px 1fr}.pkm .ph{width:84px;height:70px}.pkm-side{grid-column:1/-1;flex-direction:row;align-items:center;justify-content:space-between;flex-wrap:wrap}}
@media(max-width:640px){.g3{grid-template-columns:1fr}.ver-in{width:100%}.ver-form .btn{width:100%}}

/* ===== UX tambahan: toast, popup alert, ilustrasi kosong, aksesibilitas ===== */
.toasts{position:fixed;right:22px;bottom:22px;z-index:400;display:grid;gap:10px;width:min(380px,calc(100vw - 32px))}
.toast{position:relative;overflow:hidden;display:flex;align-items:flex-start;gap:12px;padding:14px 14px 16px 16px;border-radius:16px;color:#fff;background:var(--dark);border-left:5px solid var(--amber);box-shadow:0 18px 40px -12px rgba(29,37,9,.55);animation:tin .35s var(--ease)}
.toast .t-ic{flex-shrink:0;display:grid;place-items:center;width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.14);color:var(--amber)}
.toast .t-ic svg{width:19px;height:19px}
.toast .t-tx{flex:1;min-width:0;display:grid;gap:2px}
.toast .t-tx b{font-family:var(--head);font-size:1rem;font-weight:700;line-height:1.2}
.toast .t-tx span{font-size:.86rem;font-weight:600;line-height:1.35;opacity:.92;overflow-wrap:anywhere}
.toast .t-x{flex-shrink:0;width:28px;height:28px;border:0;border-radius:8px;display:grid;place-items:center;background:transparent;color:#fff;opacity:.7;cursor:pointer}
.toast .t-x:hover{opacity:1;background:rgba(255,255,255,.14)}
.toast .t-x svg{width:16px;height:16px}
.toast .t-bar{position:absolute;left:0;right:0;bottom:0;height:3px;background:currentColor;opacity:.55;transform-origin:left;animation:tbar var(--dur,4s) linear forwards}
.toast.pause .t-bar{animation-play-state:paused}
.toast.ok{border-left-color:#8FBF4A}.toast.ok .t-ic{color:#B6E06E}
.toast.err{border-left-color:#FF8A73;background:#4a1d14}.toast.err .t-ic{color:#FFB4A3}
.toast.warn{border-left-color:var(--amber)}.toast.warn .t-ic{color:var(--amber)}
.toast.info{border-left-color:#7CC4F0}.toast.info .t-ic{color:#A9DAF7}
.toast.out{opacity:0;transform:translateX(30px);transition:.35s}
@keyframes tbar{from{transform:scaleX(1)}to{transform:scaleX(0)}}
.swal-wrap{position:fixed;inset:0;z-index:500;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(29,37,9,.6);backdrop-filter:blur(3px);animation:fade .2s ease}
.swal{width:min(420px,100%);padding:30px 28px 26px;border-radius:26px;text-align:center;background:#fff;box-shadow:0 40px 90px -30px rgba(0,0,0,.65);animation:pop .3s var(--ease);outline:none}
.swal-ic{width:84px;height:84px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;border:4px solid currentColor}
.swal-ic svg{width:42px;height:42px}
.swal-ok .swal-ic{color:#6f9a2a;background:#EEF5DC}.swal-err .swal-ic{color:#D2543C;background:#FDECE8}
.swal-warn .swal-ic{color:#E59A0B;background:#FFF3D6}.swal-info .swal-ic{color:#3C8FC4;background:#E6F3FB}.swal-ask .swal-ic{color:#768C3A;background:#EEF5DC}
.swal-ok .swal-ic svg path{stroke-dasharray:30;stroke-dashoffset:30;animation:tick .5s .2s ease forwards}
@keyframes tick{to{stroke-dashoffset:0}}
.swal h2{font-family:var(--head);font-size:1.45rem;line-height:1.2;margin:0 0 8px;color:var(--dark)}
.swal p{margin:0 0 20px;font-size:.93rem;line-height:1.55;color:var(--muted)}
.swal-act{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.swal-act .btn{min-width:120px;justify-content:center}
.swal-act .swal-danger{background:#C8432F;color:#fff;border-color:#C8432F}
.empty .empty-art{width:200px;max-width:70%;height:auto;margin-bottom:4px}
.skip-link{position:fixed;left:12px;top:-60px;z-index:600;padding:10px 16px;border-radius:12px;background:var(--dark);color:#fff;font-weight:800;text-decoration:none;transition:top .2s}
.skip-link:focus{top:12px;outline:3px solid var(--amber)}
.to-top{position:fixed;left:auto;right:22px;bottom:96px;z-index:90;width:46px;height:46px;border-radius:50%;display:grid;place-items:center;background:var(--dark);color:#fff;box-shadow:0 10px 24px -8px rgba(29,37,9,.6);opacity:0;visibility:hidden;transform:translateY(10px);transition:.25s}
.to-top.show{opacity:1;visibility:visible;transform:none}
.to-top svg{width:22px;height:22px}
#content:focus,#tbT:focus{outline:none}
button:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,[tabindex]:focus-visible{outline:3px solid var(--amber);outline-offset:2px}
@media (prefers-reduced-motion:reduce){*{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}
</style>
</head>
<body>
<a class="skip-link" href="#content">Lewati ke konten utama</a>
<div class="scrim" id="scrim"></div>
<div class="app" id="top">

  <!-- ===== SIDEBAR (logo di pojok kiri) ===== -->
  <aside class="sidebar" id="sidebar">
    <a href="index.php" class="brand" aria-label="ReTaste - ke halaman utama">
      <span class="brand-mark"><img src="assets/img/favicon.png" alt="Logo ReTaste"></span>
      <span><b>Re<span>Taste</span></b><small>Good Food. New Chances.</small></span>
    </a>
    <nav class="nav" id="nav" aria-label="Menu penjual"></nav>
    <div class="side-foot">
      <a href="logout.php" class="logout"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg> Keluar dari akun</a>
      <div class="side-ver">ReTaste &middot; Penjual<br>&copy; <?= $tahun ?> ReTaste</div>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <div class="tb-l">
        <button class="burger" id="burger" aria-label="Buka menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
        <div><div class="tb-title" id="tbT">Dashboard</div><div class="tb-sub" id="tbS"></div></div>
      </div>
      <div class="tb-r">
        <div class="clk" aria-label="Waktu sekarang"><span class="clk-ic" data-i="clock"></span><div><b id="clkT">--:--:--</b><small id="clkD">-- --- ----</small></div></div>
        <div class="chip-user"><div class="av"><?= htmlspecialchars($inisial) ?></div><div><b><?= htmlspecialchars($nama) ?></b><small id="chipRole">Penjual</small></div></div>
      </div>
    </header>

    <main class="content" id="content" tabindex="-1">
      <section class="page active" id="page-dashboard"></section>
      <section class="page" id="page-pesanan"></section>
      <section class="page" id="page-paket"></section>
      <section class="page" id="page-laporan"></section>
      <section class="page" id="page-toko"></section>
      <section class="page" id="page-langganan"></section>
    </main>
  </div>
</div>

<div class="modal" id="modal" role="dialog" aria-modal="true"><div class="mbox" id="mbox"></div></div>
<div class="toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<a href="#top" class="to-top" id="toTop" aria-label="Kembali ke atas"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg></a>

<script>window.RT = <?= json_encode($RT, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script>
/* ReTaste - dashboard_penjual.js
   Data awal dari PHP (window.RT). */
(function () {
  'use strict';
  var RT = window.RT, TZ = 'Asia/Jakarta', P = RT.pengaturan;

  /* ---------- util ---------- */
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var skew = RT.serverNow * 1000 - Date.now();
  function now() { return Math.floor((Date.now() + skew) / 1000); }
  function rp(n) { return 'Rp' + Math.round(n).toLocaleString('id-ID'); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function jam(ts) { return new Date(ts * 1000).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: TZ }).replace('.', ':'); }
  function tgl(ts) { return new Date(ts * 1000).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: TZ }); }
  function tglPendek(ts) { return new Date(ts * 1000).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', timeZone: TZ }); }
  function dayKey(ts) { return new Date(ts * 1000).toLocaleDateString('en-CA', { timeZone: TZ }); }
  function toTs(date, time) { return Math.floor(new Date(date + 'T' + time + ':00+07:00').getTime() / 1000); }
  function mmss(sec) { sec = Math.max(0, sec); var m = Math.floor(sec / 60), s = sec % 60; return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s; }

  /* ---------- ikon ---------- */
  var IC = {
    home: '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
    bag: '<path d="M5 8h14l-1 12H6L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
    box: '<path d="m12 3 8.5 4.5v9L12 21l-8.5-4.5v-9L12 3z"/><path d="m3.5 7.5 8.5 4.5 8.5-4.5M12 12v9"/>',
    chart: '<path d="M4 20V4"/><path d="M4 20h16"/><path d="m7.5 14 3.5-4 3 2.5 4.5-6"/>',
    store: '<path d="M4 9.5 5.5 4h13L20 9.5"/><path d="M4 9.5a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0 2.7 2.7 0 0 0 5.3 0"/><path d="M5.5 12.5V20h13v-7.5"/>',
    crown: '<path d="M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5L3 8z"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
    check: '<path d="m4.5 12.5 4.5 4.5 10.5-10.5"/>',
    x: '<path d="M6 6l12 12M18 6 6 18"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    edit: '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="m13.5 6.5 4 4"/>',
    copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
    lock: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
    star: '<path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9 6.8 19.6l1-5.8-4.3-4.1 5.9-.9L12 3.5z"/>',
    wallet: '<path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H18v3"/><path d="M4 7.5V18a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1H6.5A2.5 2.5 0 0 1 4 7.5z"/><circle cx="16" cy="13.5" r="1.1"/>',
    leaf: '<path d="M5 19c0-8 5-13 14-14 0 9-5 14-13 14"/><path d="M5 19c2-4 5-7 9-9"/>',
    gift: '<rect x="3" y="8" width="18" height="4"/><path d="M5 12v8h14v-8M12 8v12"/><path d="M12 8c-3 0-5-1-5-3s3-2 5 3c2-5 5-5 5-3s-2 3-5 3z"/>',
    tag: '<path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
    alert: '<path d="M12 4 2.5 20h19L12 4z"/><path d="M12 10v4M12 17.2v.1"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.1"/>',
    shield: '<path d="M12 3.5 5 6v5.5c0 4.2 2.9 7.4 7 9 4.1-1.6 7-4.8 7-9V6l-7-2.5z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
    qr: '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><path d="M14 14h2v2h-2zM18 14h2v2h-2zM14 18h2v2h-2zM18 18h2v2h-2z"/>',
    upload: '<path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 16v4h16v-4"/>',
    arrow: '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>'
  };
  function svg(n) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (IC[n] || '') + '</svg>'; }
  function fillIcons(root) { $$('[data-i]', root).forEach(function (el) { if (!el.firstChild) el.innerHTML = svg(el.getAttribute('data-i')); }); }

  /* ---------- state ---------- */
  var S = {
    toko: RT.toko, premium: RT.langganan.premium, berakhir: RT.langganan.berakhir_pada,
    paket: RT.paket.slice(), pesanan: RT.pesanan.slice(), ulasan: RT.ulasan, kat: RT.kategori,
    page: 'dashboard', tabPesanan: 'siap', tabPaket: 'aktif', periode: 7, tanggalMulai: '', tanggalSelesai: ''
  };
  var scannerStream = null, scannerFrame = 0;
  var KAT_CLASS = { 1: 'k-roti', 2: 'k-nasi', 3: 'k-min', 4: 'k-roti', 5: 'k-cam' };
  var KAT_ICON = { 1: 'gift', 2: 'box', 3: 'tag', 4: 'gift', 5: 'box' };
  var STATUS = {
    menunggu_pembayaran: ['Menunggu pembayaran', 'b-wait'], siap_diambil: ['Siap diambil', 'b-ready'], selesai: ['Selesai', 'b-done'],
    dibatalkan: ['Dibatalkan', 'b-off'], kedaluwarsa: ['Kedaluwarsa', 'b-gray'], tidak_diambil: ['Tidak diambil', 'b-off']
  };
  var PSTATUS = { tersedia: ['Tersedia', 'b-done'], habis: ['Habis', 'b-wait'], ditutup: ['Ditutup', 'b-gray'], ditangguhkan: ['Ditangguhkan', 'b-off'] };
  var TSTATUS = { menunggu: ['Menunggu verifikasi', 'b-wait'], aktif: ['Aktif', 'b-done'], ditolak: ['Ditolak', 'b-off'], ditangguhkan: ['Ditangguhkan', 'b-off'] };
  var ALERGEN = [['susu', 'Susu'], ['telur', 'Telur'], ['kacang', 'Kacang'], ['gluten', 'Gluten'], ['kedelai', 'Kedelai'], ['seafood', 'Seafood'], ['tidak_ada', 'Tidak ada alergen']];
  var KAT_USAHA = [['toko_roti', 'Toko roti'], ['warung', 'Warung'], ['katering', 'Katering'], ['kafe', 'Kafe'], ['restoran', 'Restoran'], ['lainnya', 'Lainnya']];

  /* ---------- turunan ---------- */
  function komisiPct() { return S.premium ? P.komisi_pro_persen : P.komisi_standar_persen; }
  function gross(o) { return o.satuan * o.jumlah; }
  function komisiNom(o) { return Math.round(gross(o) * o.komisi / 100); }
  function bersih(o) { return gross(o) - komisiNom(o); }
  function bayarPembeli(o) { return gross(o) + (o.pajak || 0) - o.pot_mhs - o.pot_voucher; }
  function findPaket(id) { return S.paket.filter(function (p) { return p.id === id; })[0]; }
  function findOrd(id) { return S.pesanan.filter(function (o) { return o.id === id; })[0]; }
  function namaPaket(o) { var p = findPaket(o.paket_id); return p ? p.nama : (o.paket_nama || 'Paket'); }
  function pStatus(p) {
    if (p.status === 'ditangguhkan' || p.status === 'ditutup') return p.status;
    if (now() >= p.selesai) return 'ditutup';
    return p.stok <= 0 ? 'habis' : 'tersedia';
  }
  function aktifPaket() { return S.paket.filter(function (p) { var s = pStatus(p); return s === 'tersedia' || s === 'habis'; }); }
  function terjual(p) { return p.stok_awal - p.stok; }
  function boleh() { return S.toko.status === 'aktif'; }
  function ordsPaketAktif(pid) { return S.pesanan.filter(function (o) { return o.paket_id === pid && (o.status === 'siap_diambil' || o.status === 'menunggu_pembayaran'); }); }
  function selesaiOrders() { return S.pesanan.filter(function (o) { return o.status === 'selesai' && o.selesai_ts; }); }
  function sumBersih(list) { return list.reduce(function (a, o) { return a + bersih(o); }, 0); }
  function sumDays(n) { var lim = dayKey(now() - (n - 1) * 86400); return selesaiOrders().filter(function (o) { return dayKey(o.selesai_ts) >= lim; }); }
  function csvCell(value) {
    var text = String(value == null ? '' : value);
    if (/^[\t\r ]*[=+\-@]/.test(text)) text = "'" + text;
    return '"' + text.replace(/"/g, '""') + '"';
  }
  function unduhLaporan() {
    var list = pesananPeriode();
    if (S.tanggalMulai && S.tanggalSelesai && S.tanggalMulai > S.tanggalSelesai) return toast('Periksa rentang tanggal laporan.', true);
    if (!list.length) return toast('Tidak ada penjualan pada rentang tanggal tersebut.', true);
    var rows = [['Tanggal selesai', 'Kode pesanan', 'Pembeli', 'Paket', 'Jumlah', 'Harga satuan', 'Penjualan kotor', 'Potongan mahasiswa', 'Potongan voucher', 'Komisi', 'Pendapatan bersih']];
    list.slice().sort(function (a, b) { return a.selesai_ts - b.selesai_ts; }).forEach(function (o) {
      rows.push([dayKey(o.selesai_ts), o.kode, o.pembeli, namaPaket(o), o.jumlah, o.satuan, gross(o), o.pot_mhs, o.pot_voucher, komisiNom(o), bersih(o)]);
    });
    var csv = '\uFEFF' + rows.map(function (row) { return row.map(csvCell).join(','); }).join('\r\n');
    var url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    var link = document.createElement('a'), todayKey = dayKey(now());
    link.href = url;
    link.download = 'laporan-penjualan-' + (S.tanggalMulai || 'awal') + '-sampai-' + (S.tanggalSelesai || todayKey) + '.csv';
    document.body.appendChild(link); link.click(); link.remove();
    window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }
  function pesananPeriode() {
    if (!S.tanggalMulai && !S.tanggalSelesai) return sumDays(S.periode || 7);
    if (S.tanggalMulai && S.tanggalSelesai && S.tanggalMulai > S.tanggalSelesai) return [];
    return selesaiOrders().filter(function (o) {
      var d = dayKey(o.selesai_ts);
      return (!S.tanggalMulai || d >= S.tanggalMulai) && (!S.tanggalSelesai || d <= S.tanggalSelesai);
    });
  }
  function ratingPaket(p) {
    var reviews = S.ulasan.filter(function (u) { return +u.paket_id === +p.id; });
    return { jumlah: reviews.length, rata: reviews.length ? reviews.reduce(function (sum, u) { return sum + +u.rating; }, 0) / reviews.length : 0 };
  }
  function today() { var k = dayKey(now()); return selesaiOrders().filter(function (o) { return dayKey(o.selesai_ts) === k; }); }
  function bulanIni() { var k = dayKey(now()).slice(0, 7); return selesaiOrders().filter(function (o) { return dayKey(o.selesai_ts).slice(0, 7) === k; }); }

  /* ---------- gambar (slot dengan fallback) ---------- */
  function ph(p, cls) {
    var src = p.fotoUrl || (p.foto ? (/^uploads\//.test(p.foto) ? p.foto : 'assets/img/paket/' + p.foto) : '');
    return '<div class="ph ' + (KAT_CLASS[p.kategori] || '') + ' ' + (cls || '') + '">' + svg(KAT_ICON[p.kategori] || 'gift') + (src ? '<img src="' + esc(src) + '" alt="" loading="lazy" onerror="this.remove()">' : '') + '</div>';
  }
  function slot(src, cls, icon) { return '<div class="ph ' + (cls || '') + '">' + svg(icon || 'store') + '<img src="' + esc(src) + '" alt="" loading="lazy" onerror="this.remove()"></div>'; }

  /* ---------- UX: toast, popup (alert), ilustrasi kosong, aksesibilitas ---------- */
  var UX_ICON = {
    ok: '<path d="M20 6L9 17l-5-5"/>',
    err: '<circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/>',
    warn: '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
    ask: '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 015.8 1c0 2-3 3-3 3M12 17h.01"/>',
    x: '<path d="M18 6L6 18M6 6l12 12"/>',
    up: '<path d="M12 19V5M5 12l7-7 7 7"/>'
  };
  var UX_TITLE = { ok: 'Berhasil', err: 'Terjadi kendala', warn: 'Perhatian', info: 'Info' };
  function uxSvg(n) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + UX_ICON[n] + '</svg>'; }

  /* toast(pesan, tipe, judul) - tipe: 'ok' (bawaan) | 'err' | 'warn' | 'info'; true = 'err' (kompatibel dengan kode lama) */
  function toast(msg, type, title) {
    type = type === true ? 'err' : (type || 'ok');
    var box = document.getElementById('toasts'); if (!box) return;
    while (box.children.length >= 4) box.removeChild(box.firstChild);
    var t = document.createElement('div'); t.className = 'toast ' + type;
    t.setAttribute('role', (type === 'err' || type === 'warn') ? 'alert' : 'status');
    t.innerHTML = '<span class="t-ic">' + uxSvg(type) + '</span><div class="t-tx"><b></b><span></span></div>' +
      '<button type="button" class="t-x" aria-label="Tutup notifikasi">' + uxSvg('x') + '</button><i class="t-bar"></i>';
    t.querySelector('b').textContent = title || UX_TITLE[type];
    t.querySelector('.t-tx span').textContent = msg;
    var ms = (type === 'err' || type === 'warn') ? 6000 : 4000, timer, left = ms, started = 0;
    t.style.setProperty('--dur', ms + 'ms');
    function kill() { clearTimeout(timer); t.classList.add('out'); setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 350); }
    function run() { started = Date.now(); clearTimeout(timer); timer = setTimeout(kill, left); t.classList.remove('pause'); }
    function pause() { clearTimeout(timer); left -= Date.now() - started; if (left < 400) left = 400; t.classList.add('pause'); }
    t.addEventListener('mouseenter', pause); t.addEventListener('mouseleave', run);
    t.addEventListener('focusin', pause); t.addEventListener('focusout', run);
    t.querySelector('.t-x').addEventListener('click', kill);
    box.appendChild(t); run();
  }

  /* popup({type, title, text, ok, cancel, danger, onOk, onCancel}) - dialog ala SweetAlert (tanpa library) */
  var uxPrevFocus = null;
  function popupClose(skipRestore) {
    var w = document.getElementById('swal'); if (w) w.parentNode.removeChild(w);
    if (!skipRestore && uxPrevFocus && uxPrevFocus.focus && document.contains(uxPrevFocus)) { try { uxPrevFocus.focus(); } catch (e) {} }
  }
  function popup(o) {
    popupClose(true);
    uxPrevFocus = document.activeElement;
    var type = o.type || 'info', w = document.createElement('div');
    w.className = 'swal-wrap'; w.id = 'swal';
    w.innerHTML = '<div class="swal swal-' + type + '" role="alertdialog" aria-modal="true" aria-labelledby="swalT" aria-describedby="swalD" tabindex="-1">' +
      '<div class="swal-ic">' + uxSvg(type) + '</div><h2 id="swalT"></h2><p id="swalD"></p><div class="swal-act"></div></div>';
    w.querySelector('h2').textContent = o.title || '';
    w.querySelector('p').textContent = o.text || '';
    var act = w.querySelector('.swal-act'), okB = null;
    function done(cb) { return function () { popupClose(); if (cb) cb(); }; }
    if (o.cancel) {
      var c = document.createElement('button'); c.type = 'button'; c.className = 'btn btn-o'; c.textContent = o.cancel;
      c.setAttribute('aria-label', o.cancel); c.onclick = done(o.onCancel); act.appendChild(c);
    }
    okB = document.createElement('button'); okB.type = 'button'; okB.className = 'btn btn-p' + (o.danger ? ' swal-danger' : '');
    okB.textContent = o.ok || 'OK'; okB.setAttribute('aria-label', o.ok || 'OK'); okB.onclick = done(o.onOk); act.appendChild(okB);
    w.addEventListener('mousedown', function (e) { if (e.target === w && !o.cancel) done(o.onOk)(); });
    w.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.stopPropagation(); done(o.cancel ? o.onCancel : o.onOk)(); }
      if (e.key === 'Tab') {
        var f = w.querySelectorAll('button'); if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });
    document.body.appendChild(w);
    okB.focus();
  }

  /* ilustrasi keadaan kosong (SVG inline, tidak butuh file gambar) */
  function uxEmpty() {
    return '<svg class="empty-art" viewBox="0 0 200 150" role="img" aria-label="Ilustrasi: belum ada data">' +
      '<circle cx="100" cy="76" r="62" fill="#FBF3DC"/><ellipse cx="100" cy="134" rx="60" ry="8" fill="#EDE7D2"/>' +
      '<path d="M78 54v-6a22 22 0 0144 0v6" fill="none" stroke="#768C3A" stroke-width="4" stroke-linecap="round"/>' +
      '<path d="M54 54h92l-8 72a8 8 0 01-8 7H70a8 8 0 01-8-7z" fill="#fff" stroke="#768C3A" stroke-width="4" stroke-linejoin="round"/>' +
      '<path d="M100 116V96" stroke="#768C3A" stroke-width="3.5" stroke-linecap="round"/>' +
      '<path d="M100 100c-14 0-19-9-19-14 12-1 19 4 19 14z" fill="#768C3A"/><path d="M100 96c0-10 6-16 19-16 0 6-5 16-19 16z" fill="#F5A623"/>' +
      '<path d="M156 40v10M151 45h10M44 62v8M40 66h8" stroke="#F5A623" stroke-width="3" stroke-linecap="round"/>' +
      '<circle cx="42" cy="34" r="4" fill="#F5A623" opacity=".55"/><circle cx="162" cy="92" r="3" fill="#768C3A" opacity=".5"/></svg>';
  }

  /* ---------- aksesibilitas: label, aria-label, tabindex, fokus ---------- */
  var uxId = 0;
  var UX_NAME = { close: 'Tutup', qm: 'Kurangi jumlah', qp: 'Tambah jumlah', fav: 'Tandai favorit', reset: 'Atur ulang filter' };
  function uxHuman(s) { s = (s || '').replace(/[-_]+/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2'); return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
  function a11yFix(root) {
    root = root || document;
    var i, el, list;
    list = root.querySelectorAll('[data-act],[data-go],[data-open],[onclick]');
    for (i = 0; i < list.length; i++) {
      el = list[i];
      if (/^(BUTTON|A|INPUT|SELECT|TEXTAREA|SUMMARY|LABEL)$/.test(el.tagName)) continue;
      if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex', '0');
      if (!el.getAttribute('role')) el.setAttribute('role', 'button');
    }
    list = root.querySelectorAll('button,a[href],[role="button"]');
    for (i = 0; i < list.length; i++) {
      el = list[i];
      if (el.hasAttribute('aria-label') || el.hasAttribute('aria-labelledby')) continue;
      var txt = (el.textContent || '').replace(/\s+/g, ' ').trim();
      el.setAttribute('aria-label', txt || el.getAttribute('title') || UX_NAME[el.getAttribute('data-act')] || uxHuman(el.getAttribute('data-act') || el.getAttribute('data-go')) || 'Tombol');
      if (el.tagName === 'BUTTON' && !el.hasAttribute('type') && !el.closest('form')) el.setAttribute('type', 'button');
    }
    list = root.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]),select,textarea');
    for (i = 0; i < list.length; i++) {
      el = list[i];
      if (el.hasAttribute('aria-label') || el.hasAttribute('aria-labelledby') || el.closest('label')) continue;
      if (el.id && document.querySelector('label[for="' + el.id.replace(/"/g, '\\"') + '"]')) continue;
      var lb = null, p = el.parentElement;
      if (el.previousElementSibling && el.previousElementSibling.tagName === 'LABEL') lb = el.previousElementSibling;
      if (!lb && p) { var c = p.querySelector(':scope > label'); if (c) lb = c; }
      if (!lb && p && p.parentElement) { var c2 = p.parentElement.querySelector(':scope > label'); if (c2) lb = c2; }
      if (lb && !lb.getAttribute('for') && !lb.dataset.uxFor && !lb.querySelector('input,select,textarea')) {
        if (!el.id) el.id = 'ux' + (++uxId);
        lb.setAttribute('for', el.id); lb.dataset.uxFor = '1';
      } else if (!(lb && lb.getAttribute('for') === el.id)) {
        el.setAttribute('aria-label', el.getAttribute('placeholder') || el.getAttribute('title') || uxHuman(el.getAttribute('name') || el.id) || 'Isian');
      }
    }
    list = root.querySelectorAll('img:not([alt])');
    for (i = 0; i < list.length; i++) list[i].setAttribute('alt', '');
    list = root.querySelectorAll('svg:not([aria-hidden]):not([role]):not([aria-label])');
    for (i = 0; i < list.length; i++) list[i].setAttribute('aria-hidden', 'true');
  }

  /* fokus modal: pindahkan fokus ke dalam dialog, kunci Tab, kembalikan fokus saat ditutup */
  var uxModalFrom = null;
  function uxModalOpened() {
    var b = document.getElementById('mbox'); if (!b) return;
    uxModalFrom = document.activeElement;
    var m = document.getElementById('modal');
    m.setAttribute('role', 'dialog'); m.setAttribute('aria-modal', 'true');
    var h = b.querySelector('h1,h2,h3'); if (h) { if (!h.id) h.id = 'mTitle'; m.setAttribute('aria-labelledby', h.id); }
    a11yFix(b);
    setTimeout(function () {
      var f = b.querySelector('input:not([type="hidden"]),select,textarea,.btn-p,button:not(.mx)') || b.querySelector('button');
      if (f) f.focus({ preventScroll: true });
    }, 30);
  }
  function uxModalClosed() {
    if (uxModalFrom && uxModalFrom.focus && document.contains(uxModalFrom)) { try { uxModalFrom.focus({ preventScroll: true }); } catch (e) {} }
    uxModalFrom = null;
  }
  document.addEventListener('keydown', function (e) {
    var el = e.target;
    if ((e.key === 'Enter' || e.key === ' ') && el && el.getAttribute && el.getAttribute('role') === 'button' && !/^(BUTTON|A|INPUT)$/.test(el.tagName)) { e.preventDefault(); el.click(); return; }
    if (e.key === 'Tab') {
      var m = document.getElementById('modal');
      if (m && m.classList.contains('show') && !document.getElementById('swal')) {
        var f = m.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]');
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (!m.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
        else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    }
  });

  /* pindah halaman: selalu kembali ke atas, umumkan judul halaman, fokus ke judul */
  var uxBooted = false;
  function uxScrollTop() {
    try { window.scrollTo({ top: 0, left: 0, behavior: 'auto' }); } catch (e) { window.scrollTo(0, 0); }
    document.documentElement.scrollTop = 0; document.body.scrollTop = 0;
    var c = document.getElementById('content'); if (c) c.scrollTop = 0;
  }
  function uxAfterNav(title) {
    uxScrollTop();
    if (title) document.title = title + ' – ReTaste';
    var t = document.getElementById('tbT');
    if (t && uxBooted) { t.setAttribute('tabindex', '-1'); t.focus({ preventScroll: true }); }
    uxBooted = true;
    var cur = document.querySelectorAll('.nav-item');
    for (var i = 0; i < cur.length; i++) { if (cur[i].classList.contains('active')) cur[i].setAttribute('aria-current', 'page'); else cur[i].removeAttribute('aria-current'); }
  }
  (function uxInit() {
    var tt = document.getElementById('toTop');
    if (tt) {
      window.addEventListener('scroll', function () { tt.classList.toggle('show', (window.pageYOffset || document.documentElement.scrollTop) > 400); }, { passive: true });
      tt.addEventListener('click', function (e) { e.preventDefault(); try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (x) { window.scrollTo(0, 0); } var t = document.getElementById('tbT'); if (t) { t.setAttribute('tabindex', '-1'); t.focus({ preventScroll: true }); } });
    }
    var sk = document.querySelector('.skip-link');
    if (sk) sk.addEventListener('click', function (e) { e.preventDefault(); var c = document.getElementById('content'); if (c) { c.focus({ preventScroll: false }); c.scrollIntoView(); } });
    var pend = false;
    var mo = new MutationObserver(function (ms) {
      for (var i = 0; i < ms.length; i++) { if (!(ms[i].target.closest && ms[i].target.closest('.clk,.toasts'))) { if (!pend) { pend = true; requestAnimationFrame(function () { pend = false; a11yFix(document); }); } return; } }
    });
    mo.observe(document.body, { childList: true, subtree: true });
    var lo = document.querySelector('a.logout');
    if (lo) lo.addEventListener('click', function (e) {
      e.preventDefault();
      popup({ type: 'ask', title: 'Keluar dari akun?', text: 'Kamu perlu login lagi untuk membuka dashboard ini.', ok: 'Ya, keluar', cancel: 'Batal', onOk: function () { location.href = lo.getAttribute('href'); } });
    });
    a11yFix(document);
  })();
  function openModal(html, wide) {
    var b = $('#mbox'); b.className = 'mbox' + (wide ? ' wide' : '');
    b.innerHTML = '<button class="mx" data-act="close" aria-label="Tutup">' + svg('x') + '</button>' + html;
    b.onclick = function (e) { if (e.target.closest('[data-act="close"]')) closeModal(); };
    $('#modal').classList.add('show'); fillIcons(b); b.scrollTop = 0; uxModalOpened();
  }
  function closeModal() {
    var was = $('#modal').classList.contains('show');
    stopScanner();
    $('#modal').classList.remove('show');
    if (was) uxModalClosed();
  }

  /* ---------- navigasi ---------- */
  var NAV = [
    { label: 'Utama', items: [['dashboard', 'Dashboard', 'home']] },
    { label: 'Operasional', items: [['pesanan', 'Pesanan & Verifikasi', 'bag'], ['paket', 'Paket Saya', 'box']] },
    { label: 'Laporan', items: [['laporan', 'Laporan Penjualan', 'chart']] },
    { label: 'Toko', items: [['toko', 'Profil Toko', 'store'], ['langganan', 'ReTaste Pro', 'crown']] }
  ];
  var TITLES = {
    dashboard: ['Dashboard', 'Ringkasan penjualan, pesanan, dan paket hari ini'],
    pesanan: ['Pesanan & Verifikasi', 'Verifikasi kode pengambilan pembeli dan kelola pesanan masuk'],
    paket: ['Paket Saya', 'Posting paket sore dan atur stok'],
    laporan: ['Laporan Penjualan', 'Pendapatan, komisi, dan performa paket'],
    toko: ['Profil Toko', 'Data toko dan status verifikasi admin'],
    langganan: ['ReTaste Pro', 'Langganan premium untuk UMKM']
  };
  function buildNav() {
    var i = 0, h = '';
    NAV.forEach(function (g) {
      h += '<div class="nav-label" style="--i:' + (i++) + '">' + g.label + '</div>';
      g.items.forEach(function (it) {
        h += '<button class="nav-item" data-go="' + it[0] + '" style="--i:' + (i++) + '"><span class="ic">' + svg(it[2]) + '</span>' + it[1] + (it[0] === 'pesanan' ? '<span class="cnt" id="cntSiap"></span>' : '') + '</button>';
      });
    });
    $('#nav').innerHTML = h;
  }
  function go(p) {
    S.page = p;
    $$('.page').forEach(function (e) { e.classList.toggle('active', e.id === 'page-' + p); });
    $$('.nav-item').forEach(function (e) { e.classList.toggle('active', e.getAttribute('data-go') === p); });
    $('#tbT').textContent = TITLES[p][0]; $('#tbS').textContent = TITLES[p][1];
    closeSide(); render(); uxAfterNav(TITLES[p][0]);
    try { history.replaceState(null, '', location.search + '#' + p); } catch (e) {}
  }
  function openSide() { $('#sidebar').classList.add('open'); $('#scrim').classList.add('show'); }
  function closeSide() { $('#sidebar').classList.remove('open'); $('#scrim').classList.remove('show'); }

  /* ---------- komponen ---------- */
  function tabs(list, cur, key) {
    return '<div class="tabs" role="tablist">' + list.map(function (t) {
      return '<button class="tab ' + (String(t[0]) === String(cur) ? 'on' : '') + '" role="tab" data-act="tab" data-key="' + key + '" data-v="' + t[0] + '">' + t[1] + (t[2] != null ? '<small>' + t[2] + '</small>' : '') + '</button>';
    }).join('') + '</div>';
  }
  function empty(t, p, btn) { return '<div class="card empty">' + uxEmpty() + '<h3>' + t + '</h3><p>' + p + '</p>' + (btn || '') + '</div>'; }
  function statusBanner() {
    var t = S.toko, c = esc(t.catatan_admin || '');
    if (t.status === 'menunggu') return '<div class="banner bn-wait rise">' + svg('clock') + '<div><b>Tokomu sedang diperiksa admin</b><p>Kamu belum bisa memasang paket sampai toko disetujui. Kamu akan dapat pemberitahuan setelah verifikasi selesai.' + (S.premium ? ' Verifikasi prioritas dari ReTaste Pro aktif.' : '') + '</p></div></div>';
    if (t.status === 'ditolak') return '<div class="banner bn-off rise">' + svg('alert') + '<div><b>Verifikasi toko ditolak</b><p>' + c + '</p><button class="btn btn-p btn-sm" data-go="toko">Perbaiki profil toko</button></div></div>';
    if (t.status === 'ditangguhkan') return '<div class="banner bn-off rise">' + svg('alert') + '<div><b>Toko ditangguhkan</b><p>' + c + ' Paket aktif ditutup dan pesanan yang berjalan dibatalkan dengan refund penuh.</p></div></div>';
    return '';
  }
  function chart(vals, labels) {
    var max = Math.max.apply(null, vals.concat([10000])), W = 560, H = 210, pl = 8, pb = 28, pt = 22, n = vals.length, bw = 42, gap = (W - pl * 2 - bw * n) / (n - 1);
    var g = '<defs><linearGradient id="bg1" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#F7A503"/><stop offset="1" stop-color="#B3BC53"/></linearGradient></defs>';
    [0, .5, 1].forEach(function (f) { var y = pt + (H - pt - pb) * (1 - f); g += '<line class="gridl" x1="0" x2="' + W + '" y1="' + y + '" y2="' + y + '"/>'; });
    vals.forEach(function (v, k) {
      var h = (H - pt - pb) * (v / max), x = pl + k * (bw + gap), y = H - pb - h;
      if (v > 0) g += '<rect class="bar-r" style="animation-delay:' + (k * 60) + 'ms" x="' + x + '" y="' + y + '" width="' + bw + '" height="' + h + '" rx="9"/><text class="vl" x="' + (x + bw / 2) + '" y="' + (y - 6) + '" text-anchor="middle">' + Math.round(v / 1000) + 'rb</text>';
      else g += '<rect x="' + x + '" y="' + (H - pb - 4) + '" width="' + bw + '" height="4" rx="2" fill="#F0E4C0"/>';
      g += '<text class="axl" x="' + (x + bw / 2) + '" y="' + (H - 8) + '" text-anchor="middle">' + labels[k] + '</text>';
    });
    return '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Grafik pendapatan bersih">' + g + '</svg>';
  }
  function chart7() {
    var vals = [], labels = [], i, n = now(), list = selesaiOrders();
    for (i = 6; i >= 0; i--) {
      var k = dayKey(n - i * 86400);
      vals.push(sumBersih(list.filter(function (o) { return dayKey(o.selesai_ts) === k; }))); labels.push(tglPendek(n - i * 86400));
    }
    return chart(vals, labels);
  }
  function starsRo(n) { var h = ''; for (var i = 1; i <= 5; i++) h += '<button class="' + (i <= n ? 'on' : '') + '" tabindex="-1">' + svg('star') + '</button>'; return h; }
  function sapaan() {
    var h = parseInt(new Date(now() * 1000).toLocaleString('en-GB', { hour: 'numeric', hour12: false, timeZone: TZ }), 10);
    return h < 11 ? 'Selamat pagi' : h < 15 ? 'Selamat siang' : h < 18 ? 'Selamat sore' : 'Selamat malam';
  }

  /* baris pesanan */
  function ordRow(o) {
    var st = STATUS[o.status] || [o.status, 'b-gray'], info, act = '', p = findPaket(o.paket_id);
    info = '<span>' + svg('box') + esc(namaPaket(o)) + ' &times; ' + o.jumlah + '</span>';
    if (p) info += '<span>' + svg('clock') + 'Ambil ' + jam(p.mulai) + '&ndash;' + jam(p.selesai) + '</span>';
    if (o.status === 'menunggu_pembayaran') info += '<span>' + svg('clock') + 'Batas bayar <b class="cd" data-cd="' + o.batas_bayar + '">' + mmss(o.batas_bayar - now()) + '</b></span>';
    if (o.status === 'siap_diambil') { info += '<span>' + svg('qr') + 'Kode: &bull;&bull;&bull;&bull;&bull;&bull;</span>'; act = '<button class="btn btn-d btn-sm" data-act="batal" data-id="' + o.id + '">Batalkan</button>'; }
    if (o.status === 'selesai') info += '<span>' + svg('check') + 'Diambil ' + jam(o.selesai_ts) + '</span>';
    if (o.status === 'dibatalkan') info += '<span>' + svg('x') + esc(o.alasan || '') + '</span>';
    return '<article class="card ord rise"><div class="ph k-cam" style="font-family:var(--head);font-weight:800;font-size:1.7rem;color:var(--dark2)">' + esc(o.pembeli.charAt(0)) + '</div>' +
      '<div class="ord-main"><div class="ord-kode">' + esc(o.kode) + '</div><h3>' + esc(o.pembeli) + '</h3><div class="ord-info">' + info + '</div></div>' +
      '<div class="ord-side"><span class="badge ' + st[1] + '">' + st[0] + '</span><span class="tot" title="Pendapatan bersih setelah komisi ' + o.komisi + '%">' + rp(bersih(o)) + '</span><div class="ord-act">' + act + '</div></div></article>';
  }
  /* baris paket */
  function pkRow(p) {
    var s = pStatus(p), st = PSTATUS[s], sold = terjual(p), pct = Math.round(sold / p.stok_awal * 100), act = '', rating = ratingPaket(p);
    if (s === 'tersedia' || s === 'habis') {
      act += sold > 0 ? '<button class="btn btn-o btn-sm" data-act="stok" data-id="' + p.id + '">' + svg('plus') + 'Tambah stok</button>' : '<button class="btn btn-o btn-sm" data-act="edit" data-id="' + p.id + '">' + svg('edit') + 'Edit</button>';
      act += '<button class="btn btn-d btn-sm" data-act="tutup" data-id="' + p.id + '">Tutup</button>';
    } else if (s === 'ditutup') act = '<button class="btn btn-p btn-sm" data-act="salin" data-id="' + p.id + '">' + svg('copy') + 'Posting lagi</button>';
    return '<article class="card pkm rise">' + ph(p) +
      '<div><span class="tag ' + (p.tipe === 'kejutan' ? 'amber' : 'moss') + '">' + (p.tipe === 'kejutan' ? 'Paket Kejutan' : 'Menu Spesifik') + '</span><h3>' + esc(p.nama) + '</h3>' +
      '<div class="pkm-meta"><span>' + svg('star') + (rating.jumlah ? '&#9733; ' + rating.rata.toFixed(1).replace('.', ',') + ' dari 5 (' + rating.jumlah + ' ulasan)' : 'Belum ada ulasan pembeli') + '</span></div>' +
      '<div class="pkm-meta"><span>' + svg('clock') + tgl(p.mulai) + ' &middot; ' + jam(p.mulai) + '&ndash;' + jam(p.selesai) + '</span><span>' + svg('box') + 'Dipesan ' + sold + ' dari ' + p.stok_awal + ' (sisa ' + p.stok + ')</span></div>' +
      '<div class="prog" aria-hidden="true"><i style="width:' + pct + '%"></i></div></div>' +
      '<div class="pkm-side"><span class="badge ' + st[1] + '">' + st[0] + '</span><span class="pkm-price">' + rp(p.jual) + '<s>' + rp(p.normal) + '</s></span><div class="ord-act">' + act + '</div></div></article>';
  }

  /* ---------- RENDER: dashboard ---------- */
  function renderDashboard() {
    var t = S.toko, td = today(), sia = S.pesanan.filter(function (o) { return o.status === 'siap_diambil'; }), mnt = S.pesanan.filter(function (o) { return o.status === 'menunggu_pembayaran'; });
    var terjualHariIni = td.reduce(function (a, o) { return a + o.jumlah; }, 0) + sia.concat(mnt).reduce(function (a, o) { return a + o.jumlah; }, 0);
    var totalSelamat = selesaiOrders().reduce(function (a, o) { return a + o.jumlah; }, 0);
    var h = statusBanner();
    h += '<div class="hero-card rise">' + '<img class="hero-media" src="assets/img/retaste-store.jpg" alt="" onerror="this.remove()">' +
      '<div class="hero-txt"><h1>' + sapaan() + ', <em>' + esc(RT.penjual.nama) + '</em></h1>' +
      '<p>' + esc(t.nama) + (S.premium ? ' sedang tampil sebagai Toko Premium.' : ' siap menyelamatkan makanan sore ini.') + ' Posting paket sebelum jam tutup, lalu verifikasi kode saat pembeli datang.</p>' +
      '<div class="hmeta"><span class="hm">' + svg('store') + TSTATUS[t.status][0] + '</span><span class="hm">&#9733; <b>' + t.rating.toFixed(1).replace('.', ',') + '</b> (' + t.jumlah_ulasan + ' ulasan)</span><span class="hm">Komisi <b>' + komisiPct() + '%</b></span>' + (S.premium ? '<span class="hm">' + svg('crown') + 'Pro</span>' : '') + '</div>' +
      '<div class="hero-btns"><button class="btn btn-l" data-act="buat">' + svg('plus') + 'Buat paket</button><button class="btn btn-g" data-go="pesanan">' + svg('qr') + 'Verifikasi kode</button></div></div></div>';

    h += '<div class="g4 mb">' +
      '<button class="card stat hl hov rise" style="--i:1" data-go="laporan"><div class="stat-top"><span class="stat-l">Pendapatan Hari Ini</span><span class="stat-ic" data-i="wallet"></span></div><div class="stat-v sm">' + rp(sumBersih(td)) + '</div><div class="stat-n">Bersih setelah komisi</div></button>' +
      '<button class="card stat hov rise" style="--i:2" data-go="laporan"><div class="stat-top"><span class="stat-l">7 Hari Terakhir</span><span class="stat-ic ic-a" data-i="chart"></span></div><div class="stat-v sm">' + rp(sumBersih(sumDays(7))) + '</div><div class="stat-n">' + sumDays(7).length + ' pesanan selesai</div></button>' +
      '<button class="card stat hov rise" style="--i:3" data-go="laporan"><div class="stat-top"><span class="stat-l">Bulan Ini</span><span class="stat-ic ic-g" data-i="tag"></span></div><div class="stat-v sm">' + rp(sumBersih(bulanIni())) + '</div><div class="stat-n">Pendapatan bersih</div></button>' +
      '<button class="card stat hov rise" style="--i:4" data-go="toko"><div class="stat-top"><span class="stat-l">Rating Toko</span><span class="stat-ic ic-b" data-i="star"></span></div><div class="stat-v">' + t.rating.toFixed(1).replace('.', ',') + ' <small>/ 5</small></div><div class="stat-n">' + t.jumlah_ulasan + ' ulasan pembeli</div></button></div>';

    h += '<div class="g4 mb">' +
      '<button class="card stat hov rise" data-go="pesanan"><div class="stat-top"><span class="stat-l">Siap Diambil</span><span class="stat-ic ic-b" data-i="qr"></span></div><div class="stat-v">' + sia.length + ' <small>pesanan</small></div><div class="stat-n">Menunggu pembeli datang</div></button>' +
      '<button class="card stat hov rise" data-go="pesanan"><div class="stat-top"><span class="stat-l">Menunggu Bayar</span><span class="stat-ic ic-a" data-i="clock"></span></div><div class="stat-v">' + mnt.length + ' <small>pesanan</small></div><div class="stat-n">Stok ditahan sementara</div></button>' +
      '<button class="card stat hov rise" data-go="paket"><div class="stat-top"><span class="stat-l">Paket Terjual Hari Ini</span><span class="stat-ic ic-g" data-i="bag"></span></div><div class="stat-v">' + terjualHariIni + ' <small>paket</small></div><div class="stat-n">Termasuk yang belum diambil</div></button>' +
      '<button class="card stat hov rise" data-go="laporan"><div class="stat-top"><span class="stat-l">Total Terselamatkan</span><span class="stat-ic ic-g" data-i="leaf"></span></div><div class="stat-v">' + totalSelamat + ' <small>porsi</small></div><div class="stat-n">Dari pesanan yang selesai diambil</div></button></div>';

    var queue = S.pesanan.filter(function (o) { return o.status === 'siap_diambil' || o.status === 'menunggu_pembayaran'; }).sort(function (a, b) { return b.dibuat - a.dibuat; });
    h += '<div class="g2 mb"><div class="card rise"><div class="card-h"><div><div class="card-t">Pesanan masuk hari ini</div><div class="card-s">Minta pembeli menunjukkan kode, lalu verifikasi</div></div><button class="link" data-go="pesanan">Verifikasi ' + svg('arrow') + '</button></div>' +
      (queue.length ? '<div class="ord-list">' + queue.slice(0, 3).map(ordRow).join('') + '</div>' : empty('Belum ada pesanan aktif', 'Pesanan baru akan muncul di sini setelah pembeli memesan paketmu.')) + '</div>' +
      '<div class="card rise" style="--i:1"><div class="card-h"><div><div class="card-t">Pendapatan bersih</div><div class="card-s">7 hari terakhir</div></div></div><div id="chart">' + chart7() + '</div></div></div>';

    var act = aktifPaket();
    h += '<div class="g2 mb"><div class="card rise"><div class="card-h"><div><div class="card-t">Paket aktif</div><div class="card-s">' + (S.premium ? 'Tanpa batas paket aktif (Pro)' : act.length + ' dari ' + P.maks_paket_aktif_gratis + ' paket aktif (akun gratis)') + '</div></div><button class="link" data-go="paket">Kelola ' + svg('arrow') + '</button></div>' +
      (act.length ? '<div class="ord-list">' + act.slice(0, 3).map(pkRow).join('') + '</div>' : empty('Belum ada paket aktif', 'Posting paket sisa dagangan sebelum toko tutup.', '<button class="btn btn-p" data-act="buat">' + svg('plus') + 'Buat paket</button>')) + '</div>' +
      '<div class="card rise" style="--i:1"><div class="card-h"><div><div class="card-t">Ulasan terbaru</div><div class="card-s">Dari pembeli yang sudah mengambil</div></div></div>' +
      S.ulasan.map(function (u) { return '<div class="rev-it"><b>' + esc(u.pembeli) + '</b><span class="stars ro">' + starsRo(u.rating) + '</span><br>' + esc(u.komentar) + '</div>'; }).join('') + '</div></div>';
    $('#page-dashboard').innerHTML = h;
  }

  /* ---------- RENDER: pesanan & verifikasi ---------- */
  function renderPesanan() {
    var by = function (st) { return S.pesanan.filter(function (o) { return o.status === st && (st !== 'selesai' || dayKey(o.selesai_ts) === dayKey(now())); }).sort(function (a, b) { return b.dibuat - a.dibuat; }); };
    var sia = by('siap_diambil'), mnt = by('menunggu_pembayaran'), sel = by('selesai'), bat = S.pesanan.filter(function (o) { return ['dibatalkan', 'kedaluwarsa', 'tidak_diambil'].indexOf(o.status) > -1 && dayKey(o.dibuat) === dayKey(now()); });
    var list = { siap: sia, menunggu: mnt, selesai: sel, batal: bat }[S.tabPesanan];
    var h = '<div class="card ver rise"><div><div class="card-t">Verifikasi kode pengambilan</div><p>Minta pembeli menunjukkan kode 6 karakter atau QR dari halaman pesanannya, lalu ketik di sini atau pindai QR dengan kamera. Kode hanya sah sekali dan pada jam ambil.</p></div>' +
      '<form class="ver-form" id="fVer" autocomplete="off"><input class="ver-in" id="verIn" maxlength="6" placeholder="KODE" aria-label="Kode pengambilan" ' + (boleh() ? '' : 'disabled') + '><button class="btn btn-l" type="submit" ' + (boleh() ? '' : 'disabled') + '>' + svg('check') + 'Verifikasi</button><button class="btn btn-o" type="button" data-act="scan-camera" ' + (boleh() ? '' : 'disabled') + '>' + svg('qr') + 'Pindai QR</button></form>' +
      '<div class="ver-res" id="verRes" role="status"></div></div>' +
      tabs([['siap', 'Siap diambil', sia.length], ['menunggu', 'Menunggu pembayaran', mnt.length], ['selesai', 'Selesai hari ini', sel.length], ['batal', 'Dibatalkan', bat.length]], S.tabPesanan, 'tabPesanan') +
      (list.length ? '<div class="ord-list">' + list.map(ordRow).join('') + '</div>' : empty('Tidak ada pesanan di sini', 'Pesanan dengan status ini akan tampil di halaman ini.'));
    $('#page-pesanan').innerHTML = h;
  }

  /* ---------- RENDER: paket ---------- */
  function renderPaket() {
    var act = aktifPaket(), lain = S.paket.filter(function (p) { var s = pStatus(p); return s === 'ditutup' || s === 'ditangguhkan'; });
    var list = S.tabPaket === 'aktif' ? act : lain;
    var limitTxt = S.premium ? 'Akun Pro: tanpa batas paket aktif' : 'Akun gratis: ' + act.length + ' / ' + P.maks_paket_aktif_gratis + ' paket aktif';
    var h = '<div class="rowx mb rise"><div class="sec-h" style="margin:0"><h1>Paket saya</h1><p>' + limitTxt + '. Harga jual minimal ' + P.diskon_minimal_persen + '% lebih murah dari harga normal.</p></div><button class="btn btn-p" data-act="buat">' + svg('plus') + 'Buat paket</button></div>' +
      (boleh() ? '' : statusBanner()) +
      tabs([['aktif', 'Aktif', act.length], ['lain', 'Ditutup', lain.length]], S.tabPaket, 'tabPaket') +
      (list.length ? '<div class="ord-list">' + list.map(pkRow).join('') + '</div>' : empty('Belum ada paket di sini', S.tabPaket === 'aktif' ? 'Posting paket sisa dagangan sebelum toko tutup.' : 'Paket yang jam ambilnya berakhir akan masuk ke sini.'));
    $('#page-paket').innerHTML = h;
  }

  /* ---------- RENDER: laporan ---------- */
  function renderLaporan() {
    var n = S.periode, list = pesananPeriode(), rows = {}, k;
    list.forEach(function (o) { k = dayKey(o.selesai_ts); (rows[k] = rows[k] || []).push(o); });
    var keys = Object.keys(rows).sort().reverse();
    var kotor = list.reduce(function (a, o) { return a + gross(o); }, 0), kom = list.reduce(function (a, o) { return a + komisiNom(o); }, 0), porsi = list.reduce(function (a, o) { return a + o.jumlah; }, 0);
    var h = '<div class="sec-h rise"><h1>Laporan penjualan</h1><p>Pendapatanmu = harga jual &minus; komisi platform. Diskon mahasiswa dan voucher ditanggung platform, jadi tidak mengurangi pendapatanmu.</p></div>' +
      '<div class="card mb rise"><div class="form-grid"><div class="field"><label for="reportFrom">Dari tanggal</label><input id="reportFrom" type="date" value="' + esc(S.tanggalMulai) + '"></div><div class="field"><label for="reportTo">Sampai tanggal</label><input id="reportTo" type="date" value="' + esc(S.tanggalSelesai) + '"></div></div><div class="rowx" style="margin-top:14px"><button type="button" class="btn btn-o btn-sm" data-act="clear-report-date">Pakai periode cepat</button><button type="button" class="btn btn-p btn-sm" data-act="download-report">' + svg('upload') + 'Unduh laporan CSV</button></div>' + (S.tanggalMulai && S.tanggalSelesai && S.tanggalMulai > S.tanggalSelesai ? '<p class="fhint" style="color:var(--red);margin-top:10px">Tanggal awal harus sebelum atau sama dengan tanggal akhir.</p>' : '') + '</div>' +
      (!S.tanggalMulai && !S.tanggalSelesai ? tabs([[1, 'Hari ini'], [7, '7 hari'], [14, '14 hari']], n, 'periode') : '') +
      '<div class="g4 mb"><div class="card stat rise"><div class="stat-top"><span class="stat-l">Penjualan Kotor</span><span class="stat-ic ic-a" data-i="wallet"></span></div><div class="stat-v sm">' + rp(kotor) + '</div></div>' +
      '<div class="card stat rise"><div class="stat-top"><span class="stat-l">Komisi Platform</span><span class="stat-ic ic-r" data-i="tag"></span></div><div class="stat-v sm">' + rp(kom) + '</div><div class="stat-n">' + (kotor ? Math.round(kom / kotor * 100) : komisiPct()) + '% dari penjualan</div></div>' +
      '<div class="card stat hl rise"><div class="stat-top"><span class="stat-l">Pendapatan Bersih</span><span class="stat-ic" data-i="chart"></span></div><div class="stat-v sm">' + rp(kotor - kom) + '</div></div>' +
      '<div class="card stat rise"><div class="stat-top"><span class="stat-l">Paket Terjual</span><span class="stat-ic ic-g" data-i="bag"></span></div><div class="stat-v">' + porsi + ' <small>paket</small></div><div class="stat-n">' + list.length + ' pesanan</div></div></div>' +
      '<div class="card mb rise"><div class="card-h"><div class="card-t">Rincian per hari</div></div>' +
      (keys.length ? '<div class="tbl-w"><table class="tbl"><thead><tr><th>Tanggal</th><th class="r">Pesanan</th><th class="r">Paket</th><th class="r">Kotor</th><th class="r">Komisi</th><th class="r">Bersih</th></tr></thead><tbody>' +
        keys.map(function (d) { var r = rows[d], g = r.reduce(function (a, o) { return a + gross(o); }, 0), c = r.reduce(function (a, o) { return a + komisiNom(o); }, 0);
          return '<tr><td>' + tgl(r[0].selesai_ts) + '</td><td class="r">' + r.length + '</td><td class="r">' + r.reduce(function (a, o) { return a + o.jumlah; }, 0) + '</td><td class="r">' + rp(g) + '</td><td class="r">' + rp(c) + '</td><td class="r"><b>' + rp(g - c) + '</b></td></tr>'; }).join('') +
        '</tbody><tfoot><tr><td>Total</td><td class="r">' + list.length + '</td><td class="r">' + porsi + '</td><td class="r">' + rp(kotor) + '</td><td class="r">' + rp(kom) + '</td><td class="r">' + rp(kotor - kom) + '</td></tr></tfoot></table></div>' : '<p class="fhint">Belum ada penjualan selesai pada periode ini.</p>') + '</div>';

    h += '<div class="card rise"><div class="card-h"><div><div class="card-t">Analitik paket ' + (S.premium ? '' : '<span class="badge-pro">' + svg('crown').replace('<svg', '<svg style="width:12px;height:12px"') + 'Pro</span>') + '</div><div class="card-s">Paket terlaris dan rasio dilihat menjadi dipesan</div></div></div>';
    if (S.premium) {
      var rows2 = S.paket.slice().sort(function (a, b) { return terjual(b) - terjual(a); });
      h += '<div class="tbl-w"><table class="tbl"><thead><tr><th>Paket</th><th class="r">Dilihat</th><th class="r">Dipesan</th><th class="r">Rasio</th><th class="r">Stok awal</th></tr></thead><tbody>' +
        rows2.map(function (p) { return '<tr><td>' + esc(p.nama) + '</td><td class="r">' + p.dilihat + '</td><td class="r">' + terjual(p) + '</td><td class="r">' + (p.dilihat ? Math.round(terjual(p) / p.dilihat * 100) : 0) + '%</td><td class="r">' + p.stok_awal + '</td></tr>'; }).join('') + '</tbody></table></div>';
    } else h += '<div class="lock-ov">' + svg('lock') + '<b>Analitik lanjutan khusus ReTaste Pro</b><span class="fhint">Lihat paket terlaris dan seberapa sering paketmu dibuka sebelum dipesan.</span><button class="btn btn-p btn-sm" data-go="langganan">Lihat ReTaste Pro</button></div>';
    h += '</div>';
    $('#page-laporan').innerHTML = h;
  }

  /* ---------- RENDER: profil toko ---------- */
  function renderToko() {
    var t = S.toko, st = TSTATUS[t.status];
    var h = '<div class="sec-h rise"><h1>Profil toko</h1><p>Data ini diperiksa admin sebelum tokomu bisa memasang paket.</p></div>' +
      '<div class="card mb rise"><div class="rowx"><div><div class="card-s">Status verifikasi</div><div style="margin-top:6px"><span class="badge ' + st[1] + '" style="font-size:.85rem;padding:6px 14px">' + st[0] + '</span></div></div>' +
      (t.status === 'ditolak' ? '<button class="btn btn-p" data-act="ajukan">Ajukan ulang verifikasi</button>' : '') + '</div>' +
      (t.catatan_admin ? '<div class="note" style="margin-top:14px">' + svg('info') + '<div><b>Catatan admin:</b> ' + esc(t.catatan_admin) + '</div></div>' : '') + '</div>' +
      '<form class="card rise" id="fToko" novalidate><div class="card-h"><div class="card-t">Data toko</div></div><div class="form-grid">' +
      '<div class="field"><label for="tNama">Nama toko</label><input id="tNama" value="' + esc(t.nama) + '"><span class="em" data-em="tNama"></span></div>' +
      '<div class="field"><label for="tKat">Kategori usaha</label><select id="tKat">' + KAT_USAHA.map(function (k) { return '<option value="' + k[0] + '"' + (k[0] === t.kategori_usaha ? ' selected' : '') + '>' + k[1] + '</option>'; }).join('') + '</select></div>' +
      '<div class="field full"><label for="tAlamat">Alamat lengkap</label><textarea id="tAlamat" style="min-height:70px">' + esc(t.alamat) + '</textarea><span class="em" data-em="tAlamat"></span></div>' +
      '<div class="field"><label for="tBuka">Jam buka</label><input id="tBuka" type="time" value="' + t.jam_buka + '"></div>' +
      '<div class="field"><label for="tTutup">Jam tutup</label><input id="tTutup" type="time" value="' + t.jam_tutup + '"><span class="em" data-em="tTutup"></span></div>' +
      '<div class="field"><label for="tTelp">No. telepon toko</label><input id="tTelp" value="' + esc(t.no_telp) + '" inputmode="tel"><span class="em" data-em="tTelp"></span></div>' +
      '<div class="field full"><label for="tFoto">Foto toko (JPG/PNG, maks ' + P.maks_foto_kb / 1024 + ' MB)</label><div class="upl">' + slot(t.fotoUrl || (t.foto ? (/^uploads\//.test(t.foto) ? t.foto : 'assets/img/toko/' + t.foto) : ''), 'k-cam', 'store').replace('class="ph k-cam"', 'class="ph k-cam" id="tPrev"') + '<input id="tFoto" type="file" accept="image/jpeg,image/png"></div><span class="em" data-em="tFoto"></span></div>' +
      '</div><div style="margin-top:18px"><button class="btn btn-p" type="submit">Simpan perubahan</button></div></form>';
    $('#page-toko').innerHTML = h;
  }

  /* ---------- RENDER: langganan ---------- */
  function renderLangganan() {
    var perks = ['Boost beranda: paketmu tampil di bagian Sorotan Premium', 'Badge "Toko Premium" di kartu paket dan profil toko', 'Kuota paket aktif tanpa batas (akun gratis maks ' + P.maks_paket_aktif_gratis + ')', 'Analitik lanjutan: paket terlaris dan rasio dilihat ke dipesan', 'Komisi lebih rendah: ' + P.komisi_pro_persen + '% (akun gratis ' + P.komisi_standar_persen + '%)', 'Verifikasi toko prioritas'];
    var h = '<div class="sec-h rise"><h1>ReTaste Pro</h1><p>Paket premium untuk UMKM yang ingin lebih mudah ditemukan dan menjual lebih banyak.</p></div><div class="g2">' +
      '<div class="card pro-card rise"><div class="card-h"><div><div class="card-t">ReTaste Pro</div><div class="card-s">' + (S.premium ? 'Langganan aktif sampai ' + tgl(S.berakhir) : 'Kamu sedang memakai akun gratis') + '</div></div>' + (S.premium ? '<span class="badge-pro">' + svg('crown').replace('<svg', '<svg style="width:12px;height:12px"') + 'Aktif</span>' : '') + '</div>' +
      '<ul class="perks">' + perks.map(function (p) { return '<li>' + svg('check') + '<span>' + p + '</span></li>'; }).join('') + '</ul>' +
      (S.premium ? '<button class="btn btn-l" data-act="pro">Perpanjang 30 hari (simulasi)</button>' : '<button class="btn btn-l" data-act="pro">Berlangganan ReTaste Pro (simulasi)</button>') +
      '<p class="fhint" style="margin-top:12px;color:rgba(255,255,255,.65)">Setelah masa langganan berakhir, akunmu otomatis kembali ke gratis. Harga langganan ditetapkan oleh ReTaste.</p></div>' +
      '<div class="card rise" style="--i:1"><div class="card-h"><div class="card-t">Akun kamu sekarang</div></div><div class="kv">' +
      '<div><span>Jenis akun</span><b>' + (S.premium ? 'ReTaste Pro' : 'Gratis') + '</b></div><div><span>Komisi per penjualan</span><b>' + komisiPct() + '%</b></div>' +
      '<div><span>Paket aktif</span><b>' + aktifPaket().length + (S.premium ? ' (tanpa batas)' : ' / ' + P.maks_paket_aktif_gratis) + '</b></div><div><span>Analitik lanjutan</span><b>' + (S.premium ? 'Aktif' : 'Terkunci') + '</b></div></div>' +
      '<div class="note" style="margin-top:14px">' + svg('info') + 'Komisi dihitung dari harga jual saat pesanan dibuat. Perubahan akun tidak mengubah pesanan yang sudah berjalan.</div></div></div>';
    $('#page-langganan').innerHTML = h;
  }

  function render() {
    ({ dashboard: renderDashboard, pesanan: renderPesanan, paket: renderPaket, laporan: renderLaporan, toko: renderToko, langganan: renderLangganan })[S.page]();
    fillIcons($('#page-' + S.page));
    var c = S.pesanan.filter(function (o) { return o.status === 'siap_diambil'; }).length; $('#cntSiap').textContent = c || '';
    $('#chipRole').textContent = S.premium ? 'Penjual · Pro' : 'Penjual';
  }

  /* ---------- AKSI: verifikasi kode ---------- */
  function verifikasi(kode) {
    function show(ok, msg) {
      var box = $('#verRes');
      box.className = 'ver-res show ' + (ok ? 'ok' : 'bad');
      box.textContent = msg;
    }
    kode = (kode || '').trim().toUpperCase();
    if (!boleh()) return show(false, 'Toko belum aktif, jadi pesanan belum bisa diverifikasi.');
    if (kode.length !== 6) return show(false, 'Kode pengambilan terdiri dari 6 karakter.');
    var button = $('#fVer button[type="submit"]');
    if (button) button.disabled = true;
    var body = new URLSearchParams({ action: 'verify', kode: kode, csrf: RT.csrf });
    fetch('api_pesanan.php', {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body.toString(), credentials: 'same-origin'
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok || !data.ok) throw new Error(data.message || 'Kode pengambilan gagal diverifikasi.');
        return data;
      });
    }).then(function (data) {
      var order = data.order, local = findOrd(order.id);
      if (local) {
        local.status = order.status;
        local.selesai_ts = order.selesai_ts;
      } else {
        S.pesanan.unshift(order);
      }
      render();
      show(true, data.message);
      toast('Pesanan berhasil diverifikasi.', 'ok', 'Pesanan selesai');
      var currentInput = $('#verIn');
      if (currentInput) { currentInput.value = ''; currentInput.focus(); }
    }).catch(function (error) {
      show(false, error.message || 'Kode pengambilan gagal diverifikasi. Coba lagi.');
    }).then(function () {
      if (button) button.disabled = !boleh();
    });
  }
  function stopScanner() {
    if (scannerFrame) cancelAnimationFrame(scannerFrame);
    scannerFrame = 0;
    if (scannerStream) scannerStream.getTracks().forEach(function (track) { track.stop(); });
    scannerStream = null;
  }
  function scanCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.BarcodeDetector) {
      return toast('Pemindai QR tidak didukung browser ini. Masukkan kode 6 karakter secara manual.', true);
    }
    openModal('<div class="m-body"><h2>Pindai kode QR</h2><p>Arahkan kamera ke QR pada halaman pesanan pembeli. Jika kamera tidak tersedia, masukkan kode secara manual.</p><video id="qrVideo" autoplay playsinline style="width:100%;max-height:55vh;border-radius:14px;background:#1D2509"></video><p id="qrStatus" class="fhint" role="status">Meminta izin kamera…</p><button class="btn btn-o btn-w" type="button" data-act="close">Tutup pemindai</button></div>');
    var video = $('#qrVideo'), status = $('#qrStatus'), detector;
    try { detector = new BarcodeDetector({ formats: ['qr_code'] }); }
    catch (e) { status.textContent = 'Browser tidak mendukung pemindaian QR. Masukkan kode 6 karakter secara manual.'; return; }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (stream) {
      if (!$('#modal').classList.contains('show')) { stream.getTracks().forEach(function (track) { track.stop(); }); return; }
      scannerStream = stream;
      video.srcObject = stream;
      return video.play();
    }).then(function () {
      if (!scannerStream) return;
      status.textContent = 'Kamera aktif. Arahkan QR ke dalam bingkai.';
      function detect() {
        if (!scannerStream || !$('#modal').classList.contains('show')) return stopScanner();
        if (video.readyState >= 2) detector.detect(video).then(function (codes) {
          if (!codes.length) { scannerFrame = requestAnimationFrame(detect); return; }
          var value = (codes[0].rawValue || '').trim().toUpperCase();
          stopScanner();
          closeModal();
          var input = $('#verIn');
          if (/^[A-Z0-9]{6}$/.test(value)) {
            input.value = value;
            verifikasi(value);
          } else {
            toast('QR terbaca, tetapi isinya bukan kode pengambilan 6 karakter.', true);
            if (input) input.focus();
          }
        }).catch(function () {
          status.textContent = 'QR belum terbaca. Pastikan gambar jelas dan pencahayaan cukup.';
          scannerFrame = requestAnimationFrame(detect);
        });
        else scannerFrame = requestAnimationFrame(detect);
      }
      scannerFrame = requestAnimationFrame(detect);
    }).catch(function () {
      stopScanner();
      status.textContent = 'Tidak dapat mengakses kamera. Izinkan kamera atau masukkan kode secara manual.';
    });
  }
  function openBatal(id) {
    var o = findOrd(id); if (!o) return;
    openModal('<div class="m-body"><h2>Batalkan pesanan</h2><p>' + esc(o.pembeli) + ' &middot; ' + o.jumlah + ' &times; ' + esc(namaPaket(o)) + '. Pembeli akan menerima refund penuh (' + rp(bayarPembeli(o)) + ', simulasi) dan stok kembali ke paket.</p>' +
      '<div class="field"><label for="bAlasan">Alasan pembatalan (wajib)</label><select id="bAlasan"><option value="">Pilih alasan</option><option value="stok_habis">Stok habis atau data stok keliru</option><option value="paket_rusak">Paket atau makanan rusak</option><option value="kualitas_tidak_layak">Makanan tidak layak dijual</option><option value="toko_tutup">Toko tutup atau tidak dapat melayani</option><option value="kesalahan_harga">Kesalahan harga atau informasi paket</option><option value="lainnya">Alasan lainnya</option></select><textarea id="bAlasanLain" placeholder="Jelaskan alasan lainnya (wajib jika memilih lainnya)" style="margin-top:8px;display:none"></textarea><span class="em" data-em="bAlasan"></span></div>' +
      '<button class="btn btn-d btn-w" id="btnBatal" style="margin-top:16px">Batalkan &amp; refund</button></div>');
    $('#btnBatal').onclick = function () {
      var reason = $('#bAlasan').value, a = $('#bAlasan option:checked').textContent;
      if (!reason) { $('#bAlasan').parentNode.classList.add('err'); $('[data-em="bAlasan"]').textContent = 'Pilih alasan pembatalan.'; return; }
      if (reason === 'lainnya') { var detail = $('#bAlasanLain').value.trim(); if (!detail) { $('#bAlasanLain').focus(); $('[data-em="bAlasan"]').textContent = 'Jelaskan alasan pembatalan lainnya.'; return; } a += ': ' + detail; }
      var p = findPaket(o.paket_id); if (p) p.stok += o.jumlah;
      o.status = 'dibatalkan'; o.alasan = a; o.refund = bayarPembeli(o); closeModal(); toast('Pesanan dibatalkan dan refund diproses (simulasi).'); render();
    };
  }
  function expire() {
    var ch = false, n = now();
    S.pesanan.forEach(function (o) {
      if (o.status === 'menunggu_pembayaran' && o.batas_bayar <= n) { o.status = 'kedaluwarsa'; var p = findPaket(o.paket_id); if (p) p.stok += o.jumlah; ch = true; }
    });
    if (ch) render();
  }

  /* ---------- AKSI: form paket ---------- */
  function hhmm(ts) { return jam(ts); }
  function openForm(p, mode) {
    if (mode === 'edit') return toast('Pengubahan paket belum tersedia melalui database. Buat paket baru atau hubungi admin.', true);
    if (!RT.backendReady) return toast('Toko harus aktif dan terhubung ke akun penjual sebelum paket dapat diposting.', true);
    if (!boleh()) return toast('Toko belum aktif. Paket baru bisa diposting setelah disetujui admin.', true);
    if (mode !== 'edit' && !S.premium && aktifPaket().length >= P.maks_paket_aktif_gratis) return toast('Akun gratis maksimal ' + P.maks_paket_aktif_gratis + ' paket aktif. Tutup salah satu atau upgrade ke ReTaste Pro.', true);
    var d = p || {}, baru = mode !== 'edit', n = now(), start = Math.ceil(n / 300) * 300, ymd = dayKey(n);
    var v = {
      tipe: d.tipe || 'kejutan', nama: d.nama || '', kat: d.kategori || 1, desk: d.deskripsi || '', normal: d.normal || '', jual: d.jual || '', porsi: d.porsi || 1,
      stok: baru ? (d.stok_awal || '') : d.stok_awal, tanggal: baru ? ymd : dayKey(d.mulai), mulai: baru ? hhmm(start) : hhmm(d.mulai), selesai: baru ? hhmm(start + 2 * 3600) : hhmm(d.selesai),
      prod: d.produksi || '', al: d.alergen || [], catAl: d.catatan_alergen || ''
    };
    var title = mode === 'edit' ? 'Edit paket' : mode === 'salin' ? 'Posting ulang paket' : 'Buat paket baru';
    openModal('<div class="m-body"><h2>' + title + '</h2><p>Isi dengan jujur. Paket wajib layak konsumsi dan dibuat pada hari yang sama.</p>' +
      '<form id="fPaket" novalidate><div class="form-grid">' +
      '<div class="field full"><label>Tipe paket</label><div class="seg" role="radiogroup"><label><input type="radio" name="tipe" value="kejutan"' + (v.tipe === 'kejutan' ? ' checked' : '') + '><span>Paket Kejutan</span></label><label><input type="radio" name="tipe" value="menu"' + (v.tipe === 'menu' ? ' checked' : '') + '><span>Menu Spesifik</span></label></div><span class="fhint" id="tipeHint"></span></div>' +
      '<div class="field"><label for="fNama">Nama paket</label><input id="fNama" value="' + esc(v.nama) + '" maxlength="100"><span class="em" data-em="fNama"></span></div>' +
      '<div class="field"><label for="fKat">Kategori isi</label><select id="fKat">' + S.kat.map(function (k) { return '<option value="' + k.id + '"' + (k.id === v.kat ? ' selected' : '') + '>' + esc(k.nama) + '</option>'; }).join('') + '</select></div>' +
      '<div class="field full"><label for="fDesk" id="lDesk">Deskripsi</label><textarea id="fDesk" style="min-height:70px">' + esc(v.desk) + '</textarea></div>' +
      '<div class="sec-t">Harga &amp; stok</div>' +
      '<div class="field"><label for="fNormal">Harga normal (Rp)</label><input id="fNormal" type="number" min="1" step="500" value="' + v.normal + '" inputmode="numeric"><span class="em" data-em="fNormal"></span></div>' +
      '<div class="field"><label for="fJual">Harga jual (Rp)</label><input id="fJual" type="number" min="1" step="500" value="' + v.jual + '" inputmode="numeric"><span class="fhint" id="jualHint"></span><span class="fhint" id="buyerPriceHint"></span><span class="em" data-em="fJual"></span></div>' +
      '<div class="field"><label for="fStok">Jumlah paket tersedia</label><input id="fStok" type="number" min="1" value="' + v.stok + '" inputmode="numeric"><span class="em" data-em="fStok"></span></div>' +
      '<div class="field"><label for="fPorsi">Porsi per paket</label><input id="fPorsi" type="number" min="1" value="' + v.porsi + '" inputmode="numeric"></div>' +
      '<div class="sec-t">Jadwal ambil</div>' +
      '<div class="field"><label for="fTgl">Tanggal ambil</label><input id="fTgl" type="date" value="' + v.tanggal + '"><span class="em" data-em="fTgl"></span></div>' +
      '<div class="field"><label for="fProd">Jam produksi</label><input id="fProd" type="time" value="' + v.prod + '"><span class="fhint">Ditampilkan ke pembeli</span><span class="em" data-em="fProd"></span></div>' +
      '<div class="field"><label for="fMulai">Jam ambil mulai</label><input id="fMulai" type="time" value="' + v.mulai + '"></div>' +
      '<div class="field"><label for="fSelesai">Jam ambil selesai</label><input id="fSelesai" type="time" value="' + v.selesai + '"><span class="em" data-em="fSelesai"></span></div>' +
      '<div class="sec-t">Foto &amp; alergen</div>' +
      '<div class="field full"><label for="fFoto" id="lFoto">Foto paket (JPG/PNG, maks ' + P.maks_foto_kb / 1024 + ' MB)</label><div class="upl">' + (p ? ph(p) : '<div class="ph k-roti">' + svg('upload') + '</div>').replace('class="ph', 'id="fPrev" class="ph') + '<input id="fFoto" type="file" accept="image/jpeg,image/png"></div><span class="em" data-em="fFoto"></span></div>' +
      '<div class="field full"><label>Alergen (wajib, pilih minimal satu)</label><div class="ck-row" id="alRow">' + ALERGEN.map(function (a) { return '<label class="ck-chip"><input type="checkbox" value="' + a[0] + '"' + (v.al.indexOf(a[0]) > -1 ? ' checked' : '') + '><span>' + a[1] + '</span></label>'; }).join('') + '</div><span class="em" data-em="alRow"></span></div>' +
      '<div class="field full"><label for="fCatAl">Catatan alergen (opsional)</label><input id="fCatAl" value="' + esc(v.catAl) + '" maxlength="255"></div>' +
      '<div class="field full"><label class="ck-line"><input type="checkbox" id="fLayak"><span>Saya menyatakan makanan ini layak konsumsi dan dibuat pada hari yang sama.</span></label><span class="em" data-em="fLayak"></span></div>' +
      '</div><button class="btn btn-p btn-w" type="submit" style="margin-top:20px">' + (mode === 'edit' ? 'Simpan perubahan' : 'Posting paket') + '</button></form></div>', true);

    var f = $('#fPaket'), fotoFile = null;
    function tipeUi() {
      var t = $('input[name="tipe"]:checked', f).value;
      $('#tipeHint').textContent = t === 'kejutan' ? 'Isi tidak pasti. Harga normal = nilai normal minimal isi paket.' : 'Satu menu yang jelas. Foto harus menu aslinya.';
      $('#lDesk').textContent = t === 'kejutan' ? 'Contoh isi (opsional)' : 'Keterangan menu';
    }
    function hint() {
      var n = +$('#fNormal').value, j = +$('#fJual').value, mx = Math.floor(n * (100 - P.diskon_minimal_persen) / 100);
      $('#jualHint').textContent = n ? 'Maksimal ' + rp(mx) + (j && j <= mx ? ' (hemat ' + Math.round((1 - j / n) * 100) + '%)' : '') : '';
      $('#buyerPriceHint').textContent = j > 0
        ? 'Harga pembeli: ' + rp(j + Math.round(j * P.pajak_persen / 100)) + ' (termasuk PPN ' + P.pajak_persen + '%). PPN dicatat untuk admin.'
        : 'Harga pembeli akan ditambah PPN ' + P.pajak_persen + '%.';
    }
    tipeUi(); hint();
    f.addEventListener('change', function (e) {
      if (e.target.name === 'tipe') tipeUi();
      if (e.target.closest('#alRow')) { var ch = e.target; if (ch.value === 'tidak_ada' && ch.checked) $$('#alRow input').forEach(function (x) { if (x !== ch) x.checked = false; }); else if (ch.checked) $('#alRow input[value="tidak_ada"]').checked = false; }
      if (e.target.id === 'fFoto') {
        var fl = e.target.files[0]; if (!fl) return;
        if (!/^image\/(jpeg|png)$/.test(fl.type) || fl.size > P.maks_foto_kb * 1024) { e.target.value = ''; fotoFile = null; return toast('Foto harus JPG/PNG dan maksimal ' + P.maks_foto_kb / 1024 + ' MB.', true); }
        fotoFile = fl; var u = URL.createObjectURL(fl), pv = $('#fPrev'); pv.innerHTML = '<img src="' + u + '" alt="">';
      }
    });
    f.addEventListener('input', function (e) { if (e.target.id === 'fNormal' || e.target.id === 'fJual') hint(); });
    f.addEventListener('submit', function (e) {
      e.preventDefault(); $$('.field.err', f).forEach(function (x) { x.classList.remove('err'); }); $$('[data-em]', f).forEach(function (x) { x.textContent = ''; });
      var bad = 0;
      function er(id, msg) { bad++; var s = $('[data-em="' + id + '"]', f); if (s) { s.textContent = msg; s.closest('.field').classList.add('err'); } }
      var tipe = $('input[name="tipe"]:checked', f).value, nama = $('#fNama').value.trim(), normal = +$('#fNormal').value, jual = +$('#fJual').value, stok = +$('#fStok').value;
      var tgl_ = $('#fTgl').value, mulai = $('#fMulai').value, selesai = $('#fSelesai').value, prod = $('#fProd').value;
      var al = $$('#alRow input:checked', f).map(function (x) { return x.value; });
      if (!nama) er('fNama', 'Nama paket wajib diisi.');
      if (!(normal > 0)) er('fNormal', 'Isi harga normal.');
      if (!(jual > 0)) er('fJual', 'Isi harga jual.');
      else if (normal > 0 && jual >= normal) er('fJual', 'Harga jual harus lebih murah dari harga normal.');
      else if (normal > 0 && jual > Math.floor(normal * (100 - P.diskon_minimal_persen) / 100)) er('fJual', 'Diskon minimal ' + P.diskon_minimal_persen + '%. Harga jual maksimal ' + rp(Math.floor(normal * (100 - P.diskon_minimal_persen) / 100)) + '.');
      if (!(stok >= 1)) er('fStok', 'Minimal 1 paket.');
      if (!tgl_) er('fTgl', 'Pilih tanggal ambil.'); else if (tgl_ < dayKey(now())) er('fTgl', 'Tanggal tidak boleh sudah lewat.');
      if (!prod) er('fProd', 'Isi jam produksi.');
      if (!mulai || !selesai || selesai <= mulai) er('fSelesai', 'Jam selesai harus setelah jam mulai.');
      else if (tgl_ && toTs(tgl_, selesai) <= now()) er('fSelesai', 'Jam ambil sudah lewat.');
      if (baru && !fotoFile) er('fFoto', 'Foto paket wajib diunggah.');
      if (!al.length) er('alRow', 'Pilih minimal satu alergen, atau &ldquo;Tidak ada alergen&rdquo;.');
      if (!$('#fLayak').checked) er('fLayak', 'Pernyataan layak konsumsi wajib dicentang.');
      if (bad) { toast('Periksa kembali isian yang ditandai merah.', true); var first = $('.field.err', f); if (first) first.scrollIntoView({ block: 'center' }); return; }
      var data = { tipe: tipe, nama: nama, deskripsi: $('#fDesk').value.trim(), kategori: +$('#fKat').value, normal: normal, jual: jual, stok_awal: stok, stok: stok, porsi: +$('#fPorsi').value || 1,
        mulai: toTs(tgl_, mulai), selesai: toTs(tgl_, selesai), produksi: prod, alergen: al, catatan_alergen: $('#fCatAl').value.trim(), status: 'tersedia', dilihat: 0 };
      if (mode === 'edit') return;
      var payload = new FormData();
      payload.append('csrf', RT.csrf);
      payload.append('tipe', data.tipe);
      payload.append('nama', data.nama);
      payload.append('deskripsi', data.deskripsi);
      payload.append('kategori', String(data.kategori));
      payload.append('normal', String(data.normal));
      payload.append('jual', String(data.jual));
      payload.append('stok', String(data.stok));
      payload.append('porsi', String(data.porsi));
      payload.append('tanggal', tgl_); payload.append('mulai', mulai); payload.append('selesai', selesai); payload.append('produksi', prod);
      payload.append('catatan_alergen', data.catatan_alergen);
      data.alergen.forEach(function (allergen) { payload.append('alergen[]', allergen); });
      payload.append('foto', fotoFile);
      var submitButton = $('button[type="submit"]', f);
      submitButton.disabled = true;
      submitButton.textContent = 'Menyimpan...';
      fetch('api_paket.php?action=create', { method: 'POST', body: payload, credentials: 'same-origin' })
        .then(function (response) {
          return response.json().then(function (body) {
            if (!response.ok || !body.ok) throw new Error(body.message || 'Paket gagal disimpan.');
            return body;
          });
        })
        .then(function (body) {
          var saved = body.package;
          saved.stok_awal = saved.stok;
          saved.dilihat = 0;
          S.paket.unshift(saved);
          closeModal(); S.tabPaket = 'aktif'; go('paket');
          toast(body.message);
        })
        .catch(function (error) {
          submitButton.disabled = false;
          submitButton.textContent = 'Posting paket';
          toast(error.message || 'Paket gagal disimpan. Periksa koneksi lalu coba lagi.', true);
        });
    });
  }
  function openStok(id) {
    var p = findPaket(id); if (!p) return;
    openModal('<div class="m-body"><h2>Tambah stok</h2><p>' + esc(p.nama) + ' sudah dipesan ' + terjual(p) + ' kali, jadi harga dan isinya tidak bisa diubah lagi. Kamu masih bisa menambah jumlah paket.</p>' +
      '<div class="field"><label for="sTambah">Tambah berapa paket?</label><input id="sTambah" type="number" min="1" value="1" inputmode="numeric"></div>' +
      '<button class="btn btn-p btn-w" id="btnStok" style="margin-top:16px">Tambah stok</button></div>');
    $('#btnStok').onclick = function () {
      var n = +$('#sTambah').value; if (!(n >= 1)) return toast('Isi jumlah minimal 1.', true);
      p.stok += n; p.stok_awal += n; closeModal(); toast('Stok bertambah ' + n + ' paket.'); render();
    };
  }
  function openTutup(id) {
    var p = findPaket(id); if (!p) return; var os = ordsPaketAktif(id);
    openModal('<div class="m-body"><h2>Tutup paket?</h2><p>' + esc(p.nama) + ' tidak akan tampil lagi untuk pembeli.</p>' +
      (os.length ? '<div class="note">' + svg('alert') + '<div>Ada <b>' + os.length + ' pesanan aktif</b> untuk paket ini. Jika ditutup sekarang, pesanan tersebut dibatalkan dan pembeli menerima refund penuh (simulasi).</div></div>' : '') +
      '<div style="display:flex;gap:10px;margin-top:16px"><button class="btn btn-o btn-w" data-act="close">Batal</button><button class="btn btn-d btn-w" id="btnTutup">Ya, tutup paket</button></div></div>');
    $('#btnTutup').onclick = function () {
      os.forEach(function (o) { o.status = 'dibatalkan'; o.alasan = 'Paket ditutup penjual'; o.refund = bayarPembeli(o); });
      p.status = 'ditutup'; closeModal(); toast('Paket ditutup.' + (os.length ? ' ' + os.length + ' pesanan dibatalkan dengan refund.' : '')); render();
    };
  }

  /* ---------- AKSI: profil toko ---------- */
  function simpanToko(e) {
    e.preventDefault(); var t = S.toko, bad = 0;
    $$('#fToko .field.err').forEach(function (x) { x.classList.remove('err'); }); $$('#fToko [data-em]').forEach(function (x) { x.textContent = ''; });
    function er(id, m) { bad++; var s = $('[data-em="' + id + '"]'); s.textContent = m; s.closest('.field').classList.add('err'); }
    var nama = $('#tNama').value.trim(), alamat = $('#tAlamat').value.trim(), buka = $('#tBuka').value, tutup = $('#tTutup').value, telp = $('#tTelp').value.trim();
    if (!nama) er('tNama', 'Nama toko wajib diisi.');
    if (!alamat) er('tAlamat', 'Alamat wajib diisi.');
    if (!buka || !tutup || tutup <= buka) er('tTutup', 'Jam tutup harus setelah jam buka.');
    if (!/^[0-9+\-\s]{8,20}$/.test(telp)) er('tTelp', 'Nomor telepon tidak valid.');
    var fl = $('#tFoto').files[0];
    if (!t.foto && !fl) er('tFoto', 'Foto toko wajib diunggah untuk pengajuan pertama.');
    if (fl && (!/^image\/(jpeg|png)$/.test(fl.type) || fl.size > P.maks_foto_kb * 1024)) { bad++; toast('Foto harus JPG/PNG dan maksimal ' + P.maks_foto_kb / 1024 + ' MB.', true); }
    if (bad) return toast('Periksa kembali isian yang ditandai merah.', true);
    var payload = new FormData();
    payload.append('csrf', RT.csrf); payload.append('action', 'save');
    payload.append('nama', nama); payload.append('kategori', $('#tKat').value); payload.append('alamat', alamat);
    payload.append('buka', buka); payload.append('tutup', tutup); payload.append('telp', telp);
    if (fl) payload.append('foto', fl);
    var submitButton = $('button[type="submit"]', e.target);
    submitButton.disabled = true; submitButton.textContent = 'Menyimpan...';
    fetch('api_toko.php', { method: 'POST', body: payload, credentials: 'same-origin' })
      .then(function (response) {
        return response.json().then(function (body) {
          if (!response.ok || !body.ok) throw new Error(body.message || 'Profil toko gagal disimpan.');
          return body;
        });
      })
      .then(function (body) {
        S.toko = body.shop;
        toast(body.message); render();
      })
      .catch(function (error) {
        submitButton.disabled = false; submitButton.textContent = 'Simpan perubahan';
        toast(error.message || 'Profil toko gagal disimpan. Periksa koneksi lalu coba lagi.', true);
      });
  }

  /* ---------- event ---------- */
  document.addEventListener('click', function (e) {
    var g = e.target.closest('[data-go]'); if (g) { go(g.getAttribute('data-go')); return; }
    var a = e.target.closest('[data-act]'); if (!a) return;
    var act = a.getAttribute('data-act'), id = +a.getAttribute('data-id');
    if (act === 'close') closeModal();
    else if (act === 'buat') openForm(null, 'baru');
    else if (act === 'edit') openForm(findPaket(id), 'edit');
    else if (act === 'salin') openForm(findPaket(id), 'salin');
    else if (act === 'stok') openStok(id);
    else if (act === 'tutup') openTutup(id);
    else if (act === 'batal') openBatal(id);
    else if (act === 'scan-camera') scanCamera();
    else if (act === 'download-report') unduhLaporan();
    else if (act === 'clear-report-date') { S.tanggalMulai = ''; S.tanggalSelesai = ''; render(); }
    else if (act === 'tab') { var k = a.getAttribute('data-key'); S[k] = k === 'periode' ? +a.getAttribute('data-v') : a.getAttribute('data-v'); render(); }
    else if (act === 'ajukan') {
      var button = a; button.disabled = true;
      var payload = new FormData(); payload.append('csrf', RT.csrf); payload.append('action', 'resubmit');
      fetch('api_toko.php', { method: 'POST', body: payload, credentials: 'same-origin' })
        .then(function (response) {
          return response.json().then(function (body) {
            if (!response.ok || !body.ok) throw new Error(body.message || 'Pengajuan ulang gagal dikirim.');
            return body;
          });
        })
        .then(function (body) { S.toko.status = 'menunggu'; S.toko.catatan_admin = ''; toast(body.message); render(); })
        .catch(function (error) { button.disabled = false; toast(error.message || 'Pengajuan ulang gagal dikirim.', true); });
    }
    else if (act === 'pro') { S.premium = true; S.berakhir = Math.max(S.berakhir || 0, now()) + 30 * 86400; S.pesanan.forEach(function (o) { if (o.status === 'menunggu_pembayaran') o.komisi = P.komisi_pro_persen; }); toast('ReTaste Pro aktif (simulasi).'); render(); }
  });
  document.addEventListener('submit', function (e) {
    if (e.target.id === 'fVer') { e.preventDefault(); verifikasi($('#verIn').value); }
    else if (e.target.id === 'fToko') simpanToko(e);
  });
  document.addEventListener('change', function (e) {
    if (e.target.id === 'bAlasan') {
      var other = $('#bAlasanLain');
      other.style.display = e.target.value === 'lainnya' ? '' : 'none';
      if (e.target.value !== 'lainnya') other.value = '';
    } else if (e.target.id === 'reportFrom') { S.tanggalMulai = e.target.value; render(); }
    else if (e.target.id === 'reportTo') { S.tanggalSelesai = e.target.value; render(); }
  });
  document.addEventListener('input', function (e) { if (e.target.id === 'verIn') e.target.value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, ''); });
  document.addEventListener('change', function (e) {
    if (e.target.id === 'tFoto') { var fl = e.target.files[0]; if (fl && /^image\/(jpeg|png)$/.test(fl.type)) $('#tPrev').innerHTML = '<img src="' + URL.createObjectURL(fl) + '" alt="">'; }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !document.getElementById('swal')) { closeModal(); closeSide(); } });
  $('#modal').addEventListener('mousedown', function (e) { if (e.target.id === 'modal') closeModal(); });
  $('#burger').addEventListener('click', openSide);
  $('#scrim').addEventListener('click', closeSide);

  function tick() {
    var d = new Date(now() * 1000);
    $('#clkT').textContent = d.toLocaleTimeString('id-ID', { hour12: false, timeZone: TZ }).replace(/\./g, ':');
    $('#clkD').textContent = d.toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric', timeZone: TZ });
    $$('[data-cd]').forEach(function (el) { el.textContent = mmss(+el.getAttribute('data-cd') - now()); });
    expire();
  }

  buildNav(); fillIcons(document);
  var start = (location.hash || '').replace('#', '');
  go(TITLES[start] ? start : 'dashboard');
  tick(); setInterval(tick, 1000);
})();
</script>
</body>
</html>