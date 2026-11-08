<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

require __DIR__ . '/koneksi.php';

$idAdmin = (int)$_SESSION['user_id'];
$adminStmt = $pdo->prepare(
    "SELECT id_user, nama FROM users
     WHERE id_user = ? AND role = 'admin' AND status_akun = 'aktif'
     LIMIT 1"
);
$adminStmt->execute([$idAdmin]);
$admin = $adminStmt->fetch();
if (!$admin) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$nama = $admin['nama'];
$inisial = mb_strtoupper(mb_substr($nama, 0, 1));
$tahun = date('Y');
$now = time();
$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(32));

$users = $pdo->query(
    "SELECT id_user AS id, nama, email, role,
            (status_akun = 'aktif') AS aktif, UNIX_TIMESTAMP(created_at) AS dibuat,
            '' AS alasan, NULL AS tangguh_pada
     FROM users ORDER BY created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as &$user) {
    $user['id'] = (int)$user['id'];
    $user['aktif'] = (int)$user['aktif'];
    $user['dibuat'] = (int)$user['dibuat'];
}
unset($user);

$toko = $pdo->query(
    "SELECT t.id_toko AS id, t.id_user AS owner, t.nama_toko AS nama,
            t.kategori_usaha AS kat, t.alamat, t.lat, t.lng,
            TIME_FORMAT(t.jam_buka, '%H:%i') AS buka,
            TIME_FORMAT(t.jam_tutup, '%H:%i') AS tutup,
            t.no_telp AS telp, t.foto, t.status, COALESCE(t.catatan_admin, '') AS catatan,
            t.rating_rata AS rating, t.jumlah_ulasan AS ulasan,
            EXISTS (
                SELECT 1 FROM langganan l
                JOIN paket_langganan pl ON pl.id_paket_langganan = l.id_paket_langganan
                WHERE l.id_user = t.id_user AND pl.untuk = 'umkm'
                  AND l.status = 'aktif' AND l.berakhir_pada > NOW()
            ) AS premium,
            UNIX_TIMESTAMP(t.created_at) AS dibuat
     FROM toko t ORDER BY t.created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($toko as &$shop) {
    $shop['id'] = (int)$shop['id'];
    $shop['owner'] = (int)$shop['owner'];
    $shop['lat'] = (float)$shop['lat'];
    $shop['lng'] = (float)$shop['lng'];
    $shop['rating'] = (float)$shop['rating'];
    $shop['ulasan'] = (int)$shop['ulasan'];
    $shop['premium'] = (bool)$shop['premium'];
    $shop['dibuat'] = (int)$shop['dibuat'];
}
unset($shop);

$paket = $pdo->query(
    'SELECT id_paket AS id, id_toko AS toko, nama_paket AS nama,
            id_kategori AS kat, status, stok_sisa AS stok
     FROM paket ORDER BY created_at DESC'
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($paket as &$package) {
    $package['id'] = (int)$package['id'];
    $package['toko'] = (int)$package['toko'];
    $package['kat'] = (int)$package['kat'];
    $package['stok'] = (int)$package['stok'];
}
unset($package);

$pesanan = $pdo->query(
    'SELECT o.id_pesanan AS id, o.kode_pesanan AS kode, t.id_toko AS toko,
            p.id_paket AS paket, o.id_pembeli AS pembeli, o.jumlah,
            o.subtotal, o.ppn_nominal AS pajak, o.total_bayar AS total, o.status,
            UNIX_TIMESTAMP(o.created_at) AS ts, COALESCE(o.alasan_batal, \'\') AS alasan
     FROM pesanan o
     JOIN paket p ON p.id_paket = o.id_paket
     JOIN toko t ON t.id_toko = p.id_toko
     ORDER BY o.created_at DESC'
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($pesanan as &$order) {
    $order['id'] = (int)$order['id'];
    $order['toko'] = (int)$order['toko'];
    $order['paket'] = (int)$order['paket'];
    $order['pembeli'] = (int)$order['pembeli'];
    $order['jumlah'] = (int)$order['jumlah'];
    $order['subtotal'] = (int)$order['subtotal'];
    $order['pajak'] = (int)$order['pajak'];
    $order['total'] = (int)$order['total'];
    $order['ts'] = (int)$order['ts'];
}
unset($order);

$laporan = $pdo->query(
    'SELECT l.id_laporan AS id, o.kode_pesanan AS kode, l.id_pelapor AS pelapor,
            t.id_toko AS toko, p.id_paket AS paket, l.jenis, l.alasan, l.foto,
            l.status, l.tindakan_admin AS tindakan, COALESCE(l.catatan_admin, \'\') AS catatan,
            UNIX_TIMESTAMP(l.created_at) AS dibuat, UNIX_TIMESTAMP(l.ditangani_pada) AS ditangani
     FROM laporan l
     JOIN pesanan o ON o.id_pesanan = l.id_pesanan
     JOIN paket p ON p.id_paket = o.id_paket
     JOIN toko t ON t.id_toko = p.id_toko
     ORDER BY l.created_at DESC'
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($laporan as &$report) {
    $report['id'] = (int)$report['id'];
    $report['pelapor'] = (int)$report['pelapor'];
    $report['toko'] = (int)$report['toko'];
    $report['paket'] = (int)$report['paket'];
    $report['dibuat'] = (int)$report['dibuat'];
    $report['ditangani'] = $report['ditangani'] === null ? null : (int)$report['ditangani'];
}
unset($report);

// Skema SQL saat ini belum menyediakan tabel laporan akun.
$reportAkun = [];

$kategori = $pdo->query(
    'SELECT id_kategori AS id, nama FROM kategori ORDER BY nama'
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($kategori as &$category) {
    $category['id'] = (int)$category['id'];
}
unset($category);

$settingsRows = $pdo->query('SELECT kunci, nilai FROM pengaturan')->fetchAll(PDO::FETCH_KEY_PAIR);
$pengaturanKeys = [
    'radius_km' => 'radius_km',
    'diskon_minimal_persen' => 'diskon_minimal_persen',
    'batas_bayar_menit' => 'batas_bayar_menit',
    'maks_per_pembeli' => 'maks_per_pembeli',
    'tutup_pesan_menit' => 'tutup_pesan_menit_sebelum',
    'maks_foto_kb' => 'maks_ukuran_foto_kb',
    'komisi_standar_persen' => 'komisi_standar_persen',
    'maks_paket_aktif_gratis' => 'maks_paket_aktif_gratis',
    'diskon_mahasiswa_persen' => 'diskon_mahasiswa_persen',
];
$pengaturan = [];
foreach ($pengaturanKeys as $key => $databaseKey) {
    $pengaturan[$key] = (int)($settingsRows[$databaseKey] ?? 0);
}
$premiumCommission = $pdo->query(
    "SELECT komisi_persen FROM paket_langganan
     WHERE untuk = 'umkm' AND komisi_persen IS NOT NULL
     ORDER BY id_paket_langganan LIMIT 1"
)->fetchColumn();
$pengaturan['komisi_pro_persen'] = $premiumCommission === false ? 0 : (float)$premiumCommission;
$pengaturan['pajak_persen'] = 11;

$RT = [
    'serverNow' => $now, 'admin' => ['id' => $idAdmin, 'nama' => $nama], 'users' => $users, 'toko' => $toko, 'paket' => $paket,
    'pesanan' => $pesanan, 'laporan' => $laporan, 'reportAkun' => $reportAkun, 'kategori' => $kategori, 'pengaturan' => $pengaturan,
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard Admin &ndash; ReTaste</title>
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

/* ===== TAMBAHAN KHUSUS ADMIN ===== */
.vk{display:grid;grid-template-columns:210px 1fr auto;gap:22px;padding:18px 20px;align-items:start}
.vk .ph{width:210px;height:155px;border-radius:16px}
.vk h3{font-size:1.3rem;margin:4px 0 2px}
.vk-info{display:grid;grid-template-columns:1fr 1fr;gap:6px 22px;font-size:.86rem;color:var(--muted);font-weight:600;margin-top:10px}
.vk-info span{display:flex;gap:6px;align-items:flex-start}
.vk-info svg{width:15px;height:15px;color:var(--moss);flex-shrink:0;margin-top:3px}
.vk-info b{color:var(--dark)}
.vk-act{display:flex;flex-direction:column;gap:8px;min-width:150px}
.fbar{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end}
.fbar .field{min-width:190px;flex:1;max-width:320px}
.acts{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}
.who{display:flex;align-items:center;gap:10px}
.who .av{width:34px;height:34px;font-size:.9rem;flex-shrink:0}
.who .av::after{display:none}
.who b{display:block;color:var(--dark);font-size:.9rem;line-height:1.2}
.who small{color:var(--faint);font-weight:600;font-size:.76rem}
.tbl td .badge{font-size:.7rem}
.tbl tr:hover td{background:#FFFBEF}
.opts{display:grid;gap:10px;margin:6px 0}
.opt{position:relative;display:flex;gap:12px;align-items:flex-start;padding:12px 14px;border:1.5px solid var(--border);border-radius:12px;cursor:pointer;transition:.15s}
.opt:hover{border-color:var(--olive)}
.opt input{position:absolute;opacity:0}
.opt .rd{width:20px;height:20px;border-radius:50%;border:2px solid var(--olive);flex-shrink:0;margin-top:2px;display:grid;place-items:center}
.opt .rd::after{content:"";width:10px;height:10px;border-radius:50%;background:var(--moss);transform:scale(0);transition:.15s}
.opt input:checked~.rd::after{transform:scale(1)}
.opt:has(input:checked){border-color:var(--moss);background:var(--tint)}
.opt input:focus-visible~.rd{outline:3px solid var(--amber);outline-offset:2px}
.opt b{display:block;color:var(--dark);font-size:.92rem}
.opt small{color:var(--muted);font-weight:600;font-size:.8rem;line-height:1.4}
.bl{display:grid;gap:12px}
.bl-r{display:grid;grid-template-columns:130px 1fr 36px;gap:10px;align-items:center;font-size:.84rem;font-weight:700;color:var(--muted)}
.bl-r b{color:var(--dark);text-align:right}
.bar-t{height:10px;border-radius:99px;background:var(--border-soft);overflow:hidden}
.bar-t i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,var(--moss),var(--olive))}
.todo{display:flex;align-items:center;gap:12px;padding:11px 13px;border-radius:12px;background:var(--bg);border:1px solid var(--border-soft);margin-bottom:10px}
.todo:last-child{margin-bottom:0}
.todo .ii{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;flex-shrink:0}
.todo .ii svg{width:18px;height:18px}
.todo div b{display:block;color:var(--dark);font-size:.9rem;line-height:1.25}
.todo div small{color:var(--muted);font-weight:600;font-size:.78rem}
.todo .btn{margin-left:auto}
.lap{display:grid;grid-template-columns:1fr auto;gap:14px;align-items:center;padding:16px 20px}
.lap h3{font-size:1.08rem;margin:6px 0 2px}
.lap p{font-size:.86rem;color:var(--muted);margin:2px 0 0;display:-webkit-box;line-clamp:2;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.lap-meta{display:flex;flex-wrap:wrap;gap:4px 14px;font-size:.78rem;font-weight:700;color:var(--faint);margin-top:6px}
.kat-row{display:flex;align-items:center;gap:10px;padding:10px 13px;border-radius:10px;background:var(--bg);border:1px solid var(--border-soft);margin-bottom:8px}
.kat-row b{color:var(--dark);flex:1}
.kat-row small{color:var(--faint);font-weight:700}
.set-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 20px}
.set-grid .field label{display:flex;justify-content:space-between;gap:8px}
.set-grid .field label em{font-style:normal;color:var(--faint);font-weight:700}
.dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px}
.foto-lap{height:140px;border-radius:14px;margin:8px 0 4px}
.foto-lap.ph{width:100%}
@media(max-width:1180px){.vk{grid-template-columns:170px 1fr}.vk .ph{width:170px;height:130px}.vk-act{grid-column:1/-1;flex-direction:row;flex-wrap:wrap}.vk-act .btn{flex:1}.set-grid{grid-template-columns:1fr}}
@media(max-width:700px){.vk{grid-template-columns:1fr}.vk .ph{width:100%;height:160px}.vk-info{grid-template-columns:1fr}.lap{grid-template-columns:1fr}.bl-r{grid-template-columns:100px 1fr 30px}.fbar .field{max-width:none}}

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
    <nav class="nav" id="nav" aria-label="Menu admin"></nav>
    <div class="side-foot">
      <a href="logout.php" class="logout"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg> Keluar dari akun</a>
      <div class="side-ver">ReTaste &middot; Admin Platform<br>&copy; <?= $tahun ?> ReTaste</div>
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
        <div class="chip-user"><div class="av"><?= htmlspecialchars($inisial) ?></div><div><b><?= htmlspecialchars($nama) ?></b><small>Admin Platform</small></div></div>
      </div>
    </header>

    <main class="content" id="content" tabindex="-1">
      <section class="page active" id="page-dashboard"></section>
      <section class="page" id="page-verifikasi"></section>
      <section class="page" id="page-toko"></section>
      <section class="page" id="page-pengguna"></section>
      <section class="page" id="page-laporan"></section>
      <section class="page" id="page-transaksi"></section>
      <section class="page" id="page-akun"></section>
      <section class="page" id="page-pengaturan"></section>
    </main>
  </div>
</div>

<div class="modal" id="modal" role="dialog" aria-modal="true"><div class="mbox" id="mbox"></div></div>
<div class="toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<a href="#top" class="to-top" id="toTop" aria-label="Kembali ke atas"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg></a>

<script>window.RT = <?= json_encode($RT, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script>
/* ===== SKRIP DASHBOARD ADMIN =====
   Data awal dari PHP (window.RT). Aksi setujui/tolak, tangguhkan, tangani laporan, dan simpan
   pengaturan masih SIMULASI di browser. Ganti dengan fetch() ke endpoint PHP saat backend siap. */
(function () {
  'use strict';
  var RT = window.RT, TZ = 'Asia/Jakarta';

  /* ---------- util ---------- */
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var skew = RT.serverNow * 1000 - Date.now();
  function now() { return Math.floor((Date.now() + skew) / 1000); }
  function rp(n) { return 'Rp' + Math.round(n).toLocaleString('id-ID'); }
  function num(n) { return Math.round(n).toLocaleString('id-ID'); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function tgl(ts) { return new Date(ts * 1000).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: TZ }); }
  function tglJam(ts) { return tgl(ts) + ', ' + new Date(ts * 1000).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: TZ }).replace('.', ':'); }
  function tglPendek(ts) { return new Date(ts * 1000).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', timeZone: TZ }); }
  function dayKey(ts) { return new Date(ts * 1000).toLocaleDateString('en-CA', { timeZone: TZ }); }
  function ago(ts) { var s = now() - ts; if (s < 3600) return Math.max(1, Math.round(s / 60)) + ' menit lalu'; if (s < 86400) return Math.round(s / 3600) + ' jam lalu'; return Math.round(s / 86400) + ' hari lalu'; }

  /* ---------- ikon ---------- */
  var IC = {
    home: '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
    check: '<path d="m4.5 12.5 4.5 4.5 10.5-10.5"/>',
    x: '<path d="M6 6l12 12M18 6 6 18"/>',
    store: '<path d="M4 9.5 5.5 4h13L20 9.5"/><path d="M4 9.5a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0 2.7 2.7 0 0 0 5.3 0"/><path d="M5.5 12.5V20h13v-7.5"/>',
    shield: '<path d="M12 3.5 5 6v5.5c0 4.2 2.9 7.4 7 9 4.1-1.6 7-4.8 7-9V6l-7-2.5z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><circle cx="17.5" cy="9" r="2.5"/><path d="M17 14.5c2.4 0 4 1.5 4.5 4.5"/>',
    flag: '<path d="M5 21V4"/><path d="M5 4h11l-2 4 2 4H5"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/>',
    chart: '<path d="M4 20V4"/><path d="M4 20h16"/><path d="m7.5 14 3.5-4 3 2.5 4.5-6"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
    alert: '<path d="M12 4 2.5 20h19L12 4z"/><path d="M12 10v4M12 17.2v.1"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.1"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    bag: '<path d="M5 8h14l-1 12H6L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
    leaf: '<path d="M5 19c0-8 5-13 14-14 0 9-5 14-13 14"/><path d="M5 19c2-4 5-7 9-9"/>',
    wallet: '<path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H18v3"/><path d="M4 7.5V18a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1H6.5A2.5 2.5 0 0 1 4 7.5z"/><circle cx="16" cy="13.5" r="1.1"/>',
    ban: '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>',
    eye: '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    edit: '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="m13.5 6.5 4 4"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    tag: '<path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
    pin: '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    box: '<path d="m12 3 8.5 4.5v9L12 21l-8.5-4.5v-9L12 3z"/><path d="m3.5 7.5 8.5 4.5 8.5-4.5M12 12v9"/>',
    crown: '<path d="M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5L3 8z"/>',
    star: '<path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9 6.8 19.6l1-5.8-4.3-4.1 5.9-.9L12 3.5z"/>',
    phone: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
    mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    arrow: '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>'
  };
  function svg(n) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (IC[n] || '') + '</svg>'; }
  function fillIcons(root) { $$('[data-i]', root).forEach(function (el) { if (!el.firstChild) el.innerHTML = svg(el.getAttribute('data-i')); }); }

  /* ---------- state ---------- */
  var S = {
    users: RT.users, toko: RT.toko, paket: RT.paket, pesanan: RT.pesanan, laporan: RT.laporan, kat: RT.kategori, set: RT.pengaturan, admin: RT.admin, reportAkun: RT.reportAkun,
    page: 'dashboard', tabVerif: 'menunggu', tabLap: 'baru', chart: 'pesanan', tabAkun: 'baru', f: { ts: 'semua', tq: '', ur: 'semua', uq: '', rs: 'semua', rt: 'semua', rq: '', rn: 25 }
  };
  var KAT_USAHA = { toko_roti: 'Toko roti', warung: 'Warung', katering: 'Katering', kafe: 'Kafe', restoran: 'Restoran', lainnya: 'Lainnya' };
  var TSTATUS = { menunggu: ['Menunggu verifikasi', 'b-wait'], aktif: ['Aktif', 'b-done'], ditolak: ['Perlu perbaikan', 'b-off'], ditangguhkan: ['Ditangguhkan', 'b-off'] };
  var ROLE = { admin: ['Admin', 'b-off'], penjual: ['Penjual', 'b-wait'], pembeli: ['Pembeli', 'b-ready'] };
  var JENIS = { isi_tidak_sesuai: 'Isi tidak sesuai', tidak_layak: 'Makanan tidak layak', alergen: 'Masalah alergen', penjual_tidak_melayani: 'Penjual tidak melayani', lainnya: 'Lainnya' };
  var TINDAKAN = { abaikan: 'Diabaikan', peringatan: 'Peringatan ke penjual', tangguhkan_paket: 'Paket ditangguhkan', tangguhkan_toko: 'Toko ditangguhkan' };
  var LSTATUS = { baru: ['Baru', 'b-off'], diproses: ['Diproses', 'b-wait'], selesai: ['Selesai', 'b-done'] };
  var PAID = ['selesai', 'siap_diambil', 'tidak_diambil'];
  var OSTATUS = { selesai: ['Selesai', 'b-done'], siap_diambil: ['Siap diambil', 'b-ready'], menunggu_pembayaran: ['Menunggu bayar', 'b-wait'], tidak_diambil: ['Tidak diambil', 'b-gray'], kedaluwarsa: ['Kedaluwarsa', 'b-gray'], dibatalkan: ['Dibatalkan', 'b-off'] };
  var JENIS_AKUN = { penipuan: 'Indikasi penipuan', spam: 'Spam atau promosi', kasar: 'Perilaku kasar', data_palsu: 'Data tidak benar', tidak_ambil: 'Sering tidak mengambil pesanan', lainnya: 'Lainnya' };
  var TINDAKAN_AKUN = { abaikan: 'Diabaikan', peringatan: 'Peringatan ke pemilik akun', tangguhkan: 'Akun ditangguhkan' };

  /* ---------- turunan ---------- */
  function uById(id) { return S.users.filter(function (u) { return u.id === id; })[0]; }
  function tById(id) { return S.toko.filter(function (t) { return t.id === id; })[0]; }
  function pById(id) { return S.paket.filter(function (p) { return p.id === id; })[0]; }
  function katNama(id) { var k = S.kat.filter(function (x) { return x.id === id; })[0]; return k ? k.nama : '-'; }
  function tokoBy(st) { return S.toko.filter(function (t) { return t.status === st; }); }
  function aktifPaketToko(tid) { return S.paket.filter(function (p) { return p.toko === tid && (p.status === 'tersedia' || p.status === 'habis'); }); }
  function aktifOrdToko(tid) { return S.pesanan.filter(function (o) { return o.toko === tid && (o.status === 'siap_diambil' || o.status === 'menunggu_pembayaran'); }); }
  function isPaid(o) { return PAID.indexOf(o.status) > -1; }
  function paidOrders() { return S.pesanan.filter(isPaid); }
  function paidSum(k) { return paidOrders().reduce(function (a, o) { return a + o[k]; }, 0); }
  function stats() {
    var done = S.pesanan.filter(function (o) { return o.status === 'selesai'; });
    return {
      tokoAktif: tokoBy('aktif').length,
      pembeli: S.users.filter(function (u) { return u.role === 'pembeli' && u.aktif; }).length,
      pesanan: S.pesanan.length,
      pajak: paidSum('pajak'), nTrx: paidOrders().length,
      akunBaru: S.reportAkun.filter(function (r) { return r.status === 'baru'; }).length,
      porsi: done.reduce(function (a, o) { return a + o.jumlah; }, 0),
      menunggu: tokoBy('menunggu').length,
      laporBaru: S.laporan.filter(function (l) { return l.status === 'baru'; }).length
    };
  }
  /* penangguhan: tutup paket aktif dan batalkan pesanan aktif (sesuai alur 4.6) */
  function batalkanOrd(list, alasan) { list.forEach(function (o) { o.status = 'dibatalkan'; o.oleh = 'admin'; o.alasan = alasan; }); return list.length; }
  function tangguhkanToko(t, alasan) {
    var pk = aktifPaketToko(t.id), os = aktifOrdToko(t.id);
    pk.forEach(function (p) { p.status = 'ditangguhkan'; });
    t.status = 'ditangguhkan'; t.catatan = alasan;
    return { paket: pk.length, pesanan: batalkanOrd(os, 'Toko ditangguhkan') };
  }
  function tangguhkanPaket(p) {
    var os = S.pesanan.filter(function (o) { return o.paket === p.id && (o.status === 'siap_diambil' || o.status === 'menunggu_pembayaran'); });
    p.status = 'ditangguhkan'; return { pesanan: batalkanOrd(os, 'Paket ditangguhkan') };
  }

  /* penangguhan akun: pembeli -> pesanan aktifnya dibatalkan; penjual -> tokonya ikut ditangguhkan */
  function jmlReport(uid) { return S.reportAkun.filter(function (r) { return r.akun === uid; }).length; }
  function dampakAkun(u) {
    var d = { toko: 0, paket: 0, pesanan: 0 };
    if (u.role === 'penjual') S.toko.filter(function (t) { return t.owner === u.id && t.status !== 'ditangguhkan'; }).forEach(function (t) { d.toko++; d.paket += aktifPaketToko(t.id).length; d.pesanan += aktifOrdToko(t.id).length; });
    else if (u.role === 'pembeli') d.pesanan = S.pesanan.filter(function (o) { return o.pembeli === u.id && (o.status === 'siap_diambil' || o.status === 'menunggu_pembayaran'); }).length;
    return d;
  }
  function dampakText(u, d) {
    if (u.role === 'penjual') return d.toko ? 'Toko miliknya ikut ditangguhkan: ' + d.paket + ' paket ditutup dan ' + d.pesanan + ' pesanan aktif dibatalkan dengan refund penuh (simulasi).' : 'Akun ini tidak punya toko yang masih berjalan.';
    return d.pesanan ? d.pesanan + ' pesanan aktifnya dibatalkan (simulasi).' : 'Tidak ada pesanan aktif yang terdampak.';
  }
  function tangguhkanAkun(u, alasan) {
    var r = { toko: 0, paket: 0, pesanan: 0 };
    u.aktif = 0; u.alasan = alasan; u.tangguh_pada = now();
    if (u.role === 'penjual') S.toko.filter(function (t) { return t.owner === u.id && t.status !== 'ditangguhkan'; }).forEach(function (t) { var x = tangguhkanToko(t, 'Akun pemilik ditangguhkan: ' + alasan); r.toko++; r.paket += x.paket; r.pesanan += x.pesanan; });
    else if (u.role === 'pembeli') r.pesanan = batalkanOrd(S.pesanan.filter(function (o) { return o.pembeli === u.id && (o.status === 'siap_diambil' || o.status === 'menunggu_pembayaran'); }), 'Akun pembeli ditangguhkan');
    return r;
  }
  function ringkasTangguh(u, r) { return u.role === 'penjual' ? r.toko + ' toko dan ' + r.paket + ' paket ditutup, ' + r.pesanan + ' pesanan dibatalkan.' : r.pesanan + ' pesanan aktif dibatalkan.'; }
  function pulihkanAkun(u) { u.aktif = 1; u.alasan = ''; u.tangguh_pada = null; }

  /* ---------- gambar (slot dengan fallback) ---------- */
  function fotoToko(t, cls) { return '<div class="ph k-roti ' + (cls || '') + '">' + svg('store') + '<img src="assets/img/toko/' + esc(t.foto) + '" alt="Foto ' + esc(t.nama) + '" loading="lazy" onerror="this.remove()"></div>'; }
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
    $('#modal').classList.add('show'); fillIcons(b); b.scrollTop = 0; uxModalOpened();
  }
  function closeModal() { var was = $('#modal').classList.contains('show'); $('#modal').classList.remove('show'); if (was) uxModalClosed(); }
  function confirmBox(title, text, btnLabel, danger, fn) {
    openModal('<div class="m-body"><h2>' + title + '</h2><p>' + text + '</p><div style="display:flex;gap:10px;margin-top:16px"><button class="btn btn-o btn-w" data-act="close">Batal</button><button class="btn ' + (danger ? 'btn-d' : 'btn-p') + ' btn-w" id="btnOk">' + btnLabel + '</button></div></div>');
    $('#btnOk').onclick = function () { closeModal(); fn(); };
  }
  /* modal dengan satu kolom alasan wajib */
  function reasonBox(title, text, label, btnLabel, fn, extra, ph) {
    openModal('<div class="m-body"><h2>' + title + '</h2><p>' + text + '</p>' + (extra || '') +
      '<div class="field"><label for="rsn">' + label + '</label><textarea id="rsn" placeholder="' + esc(ph || 'Tulis alasan yang jelas agar penjual bisa memperbaiki') + '"></textarea><span class="em" id="rsnE"></span></div>' +
      '<button class="btn btn-d btn-w" id="btnRsn" style="margin-top:16px">' + btnLabel + '</button></div>');
    $('#btnRsn').onclick = function () {
      var v = $('#rsn').value.trim();
      if (v.length < 5) { $('#rsn').parentNode.classList.add('err'); $('#rsnE').textContent = 'Alasan wajib diisi (minimal 5 karakter).'; return; }
      closeModal(); fn(v);
    };
  }

  /* ---------- navigasi ---------- */
  var NAV = [
    { label: 'Utama', items: [['dashboard', 'Dashboard Platform', 'home'], ['transaksi', 'Detail Transaksi', 'wallet']] },
    { label: 'Toko', items: [['verifikasi', 'Verifikasi Toko', 'shield'], ['toko', 'Daftar Toko', 'store']] },
    { label: 'Pengguna & Moderasi', items: [['pengguna', 'Pengguna', 'users'], ['laporan', 'Laporan Masalah', 'flag'], ['akun', 'Report Akun', 'ban']] },
    { label: 'Sistem', items: [['pengaturan', 'Kategori & Pengaturan', 'gear']] }
  ];
  var TITLES = {
    dashboard: ['Dashboard Platform', 'Ringkasan toko, pesanan, transaksi, dan dampak'],
    verifikasi: ['Verifikasi Toko', 'Periksa data dan foto toko baru sebelum boleh berjualan'],
    toko: ['Daftar Toko', 'Semua toko, status, dan penangguhan'],
    pengguna: ['Pengguna', 'Akun pembeli, penjual, dan admin'],
    laporan: ['Laporan Masalah', 'Laporan dari pembeli yang perlu ditinjau'],
    akun: ['Report Akun', 'Akun bermasalah yang dilaporkan pengguna'],
    transaksi: ['Detail Transaksi', 'Riwayat transaksi dan pajak untuk admin'],
    pengaturan: ['Kategori & Pengaturan', 'Parameter sistem yang berlaku untuk semua transaksi']
  };
  function buildNav() {
    var i = 0, h = '';
    NAV.forEach(function (g) {
      h += '<div class="nav-label" style="--i:' + (i++) + '">' + g.label + '</div>';
      g.items.forEach(function (it) {
        h += '<button class="nav-item" data-go="' + it[0] + '" style="--i:' + (i++) + '"><span class="ic">' + svg(it[2]) + '</span>' + it[1] + (it[0] === 'verifikasi' ? '<span class="cnt" id="cntV"></span>' : it[0] === 'laporan' ? '<span class="cnt" id="cntL"></span>' : it[0] === 'akun' ? '<span class="cnt" id="cntA"></span>' : '') + '</button>';
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
    try { history.replaceState(null, '', '#' + p); } catch (e) {}
  }
  function openSide() { $('#sidebar').classList.add('open'); $('#scrim').classList.add('show'); }
  function closeSide() { $('#sidebar').classList.remove('open'); $('#scrim').classList.remove('show'); }

  /* ---------- komponen ---------- */
  function tabs(list, cur, key) {
    return '<div class="tabs" role="tablist">' + list.map(function (t) {
      return '<button class="tab ' + (String(t[0]) === String(cur) ? 'on' : '') + '" role="tab" data-act="tab" data-key="' + key + '" data-v="' + t[0] + '">' + t[1] + (t[2] != null ? '<small>' + t[2] + '</small>' : '') + '</button>';
    }).join('') + '</div>';
  }
  function empty(t, p) { return '<div class="card empty"><div class="ph k-cam">' + svg('check') + '</div><h3>' + t + '</h3><p>' + p + '</p></div>'; }
  function badge(m) { return '<span class="badge ' + m[1] + '">' + m[0] + '</span>'; }
  function who(u) { return '<div class="who"><div class="av">' + esc(u.nama.charAt(0)) + '</div><div><b>' + esc(u.nama) + '</b><small>' + esc(u.email) + '</small></div></div>'; }
  function osm(t) { return 'https://www.openstreetmap.org/?mlat=' + t.lat + '&mlon=' + t.lng + '#map=17/' + t.lat + '/' + t.lng; }
  function chartSvg(vals, labels, fmt) {
    var max = Math.max.apply(null, vals.concat([1])), W = 560, H = 220, pl = 8, pb = 28, pt = 24, n = vals.length, bw = 44, gap = (W - pl * 2 - bw * n) / (n - 1);
    var g = '<defs><linearGradient id="bg1" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#F7A503"/><stop offset="1" stop-color="#B3BC53"/></linearGradient></defs>';
    [0, .5, 1].forEach(function (f) { var y = pt + (H - pt - pb) * (1 - f); g += '<line class="gridl" x1="0" x2="' + W + '" y1="' + y + '" y2="' + y + '"/>'; });
    vals.forEach(function (v, k) {
      var h = (H - pt - pb) * (v / max), x = pl + k * (bw + gap), y = H - pb - h;
      if (v > 0) g += '<rect class="bar-r" style="animation-delay:' + (k * 60) + 'ms" x="' + x + '" y="' + y + '" width="' + bw + '" height="' + h + '" rx="9"/><text class="vl" x="' + (x + bw / 2) + '" y="' + (y - 6) + '" text-anchor="middle">' + fmt(v) + '</text>';
      else g += '<rect x="' + x + '" y="' + (H - pb - 4) + '" width="' + bw + '" height="4" rx="2" fill="#F0E4C0"/>';
      g += '<text class="axl" x="' + (x + bw / 2) + '" y="' + (H - 8) + '" text-anchor="middle">' + labels[k] + '</text>';
    });
    return '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Grafik mingguan">' + g + '</svg>';
  }
  function weekly(mode) {
    var vals = [], labels = [], n = now(), i;
    for (i = 6; i >= 0; i--) {
      var k = dayKey(n - i * 86400), list = S.pesanan.filter(function (o) { return (mode === 'pajak' ? isPaid(o) : o.status === 'selesai') && dayKey(o.ts) === k; });
      vals.push(mode === 'pesanan' ? list.length : mode === 'porsi' ? list.reduce(function (a, o) { return a + o.jumlah; }, 0) : list.reduce(function (a, o) { return a + o.pajak; }, 0));
      labels.push(tglPendek(n - i * 86400));
    }
    return chartSvg(vals, labels, mode === 'pajak' ? function (v) { return (Math.round(v / 100) / 10).toString().replace('.', ',') + 'rb'; } : function (v) { return v; });
  }
  function sapaan() {
    var h = parseInt(new Date(now() * 1000).toLocaleString('en-GB', { hour: 'numeric', hour12: false, timeZone: TZ }), 10);
    return h < 11 ? 'Selamat pagi' : h < 15 ? 'Selamat siang' : h < 18 ? 'Selamat sore' : 'Selamat malam';
  }

  /* ---------- RENDER: dashboard ---------- */
  function renderDashboard() {
    var s = stats(), menunggu = tokoBy('menunggu').sort(function (a, b) { return (b.premium ? 1 : 0) - (a.premium ? 1 : 0) || a.dibuat - b.dibuat; });
    var lapBaru = S.laporan.filter(function (l) { return l.status === 'baru'; });
    var h = '';
    if (s.menunggu || s.laporBaru || s.akunBaru) h += '<div class="banner bn-wait rise">' + svg('alert') + '<div><b>' + [s.menunggu ? s.menunggu + ' toko menunggu verifikasi' : '', s.laporBaru ? s.laporBaru + ' laporan baru' : '', s.akunBaru ? s.akunBaru + ' report akun baru' : ''].filter(Boolean).join(' &middot; ') + '</b><p>' + sapaan() + ', ' + esc(S.admin.nama) + '. Ada yang perlu kamu tinjau hari ini.</p></div></div>';
    h += '<div class="g4 mb">' +
      '<button class="card stat hl hov rise" data-go="toko"><div class="stat-top"><span class="stat-l">Toko Aktif</span><span class="stat-ic" data-i="store"></span></div><div class="stat-v">' + s.tokoAktif + ' <small>toko</small></div><div class="stat-n">Dari ' + S.toko.length + ' toko terdaftar</div></button>' +
      '<button class="card stat hov rise" style="--i:1" data-go="pengguna"><div class="stat-top"><span class="stat-l">Pembeli</span><span class="stat-ic ic-b" data-i="users"></span></div><div class="stat-v">' + s.pembeli + ' <small>akun aktif</small></div><div class="stat-n">Pengguna dengan peran pembeli</div></button>' +
      '<div class="card stat hov rise" style="--i:2"><div class="stat-top"><span class="stat-l">Jumlah Pesanan</span><span class="stat-ic ic-a" data-i="bag"></span></div><div class="stat-v">' + num(s.pesanan) + ' <small>pesanan</small></div><div class="stat-n">Semua status</div></div>' +
      '<button class="card stat hov rise" style="--i:3" data-go="transaksi"><div class="stat-top"><span class="stat-l">Detail Transaksi</span><span class="stat-ic ic-g" data-i="wallet"></span></div><div class="stat-v sm">' + rp(s.pajak) + '</div><div class="stat-n">PPN ' + S.set.pajak_persen + '% yang tercatat dari ' + num(s.nTrx) + ' transaksi</div></button></div>';
    h += '<div class="g4 mb">' +
      '<div class="card stat hov rise"><div class="stat-top"><span class="stat-l">Paket Terselamatkan</span><span class="stat-ic ic-g" data-i="leaf"></span></div><div class="stat-v">' + num(s.porsi) + ' <small>paket</small></div><div class="stat-n">Dari pesanan berstatus Selesai</div></div>' +
      '<button class="card stat hov rise" style="--i:1" data-go="verifikasi"><div class="stat-top"><span class="stat-l">Menunggu Verifikasi</span><span class="stat-ic ic-a" data-i="shield"></span></div><div class="stat-v">' + s.menunggu + ' <small>toko</small></div><div class="stat-n">Perlu diperiksa admin</div></button>' +
      '<button class="card stat hov rise" style="--i:2" data-go="laporan"><div class="stat-top"><span class="stat-l">Laporan Baru</span><span class="stat-ic ic-r" data-i="flag"></span></div><div class="stat-v">' + s.laporBaru + ' <small>laporan</small></div><div class="stat-n">Belum ditangani</div></button>' +
      '<button class="card stat hov rise" style="--i:3" data-go="akun"><div class="stat-top"><span class="stat-l">Report Akun Baru</span><span class="stat-ic ic-r" data-i="ban"></span></div><div class="stat-v">' + s.akunBaru + ' <small>report</small></div><div class="stat-n">Akun yang dilaporkan pengguna</div></button></div>';

    h += '<div class="g2 mb"><div class="card rise"><div class="card-h"><div><div class="card-t">Grafik mingguan</div><div class="card-s">7 hari terakhir, dari pesanan selesai</div></div>' +
      '<div class="tabs" style="margin:0">' + [['pesanan', 'Pesanan'], ['porsi', 'Paket'], ['pajak', 'Pajak']].map(function (t) { return '<button class="tab ' + (S.chart === t[0] ? 'on' : '') + '" data-act="chart" data-v="' + t[0] + '" style="padding:6px 14px">' + t[1] + '</button>'; }).join('') + '</div></div><div id="chart">' + weekly(S.chart) + '</div></div>' +
      '<div class="card rise" style="--i:1"><div class="card-h"><div><div class="card-t">Perlu tindakan</div><div class="card-s">Antrian terlama lebih dulu</div></div></div>' +
      (menunggu.slice(0, 3).map(function (t) { return '<div class="todo"><span class="ii ic-a" data-i="shield"></span><div><b>' + esc(t.nama) + (t.premium ? ' <span class="badge-pro">Prioritas</span>' : '') + '</b><small>Mendaftar ' + ago(t.dibuat) + '</small></div><button class="btn btn-p btn-sm" data-go="verifikasi">Periksa</button></div>'; }).join('') +
        lapBaru.slice(0, 3).map(function (l) { var t = tById(l.toko); return '<div class="todo"><span class="ii ic-r" data-i="flag"></span><div><b>' + JENIS[l.jenis] + '</b><small>' + esc(t.nama) + ' &middot; ' + ago(l.dibuat) + '</small></div><button class="btn btn-o btn-sm" data-act="lapor" data-id="' + l.id + '">Tangani</button></div>'; }).join('') +
        S.reportAkun.filter(function (r) { return r.status === 'baru'; }).slice(0, 3).map(function (r) { var u = uById(r.akun); return '<div class="todo"><span class="ii ic-r" data-i="ban"></span><div><b>Report akun: ' + JENIS_AKUN[r.jenis] + '</b><small>' + esc(u.nama) + ' &middot; ' + ago(r.dibuat) + '</small></div><button class="btn btn-o btn-sm" data-act="akunLapor" data-id="' + r.id + '">Tangani</button></div>'; }).join('') ||
        '<p class="fhint">Tidak ada antrian. Semua sudah ditangani.</p>') + '</div></div>';

    var week = {}, lim = dayKey(now() - 6 * 86400);
    S.pesanan.filter(function (o) { return o.status === 'selesai' && dayKey(o.ts) >= lim; }).forEach(function (o) { week[o.toko] = (week[o.toko] || 0) + o.jumlah; });
    var top = Object.keys(week).map(function (k) { return [tById(+k), week[k]]; }).sort(function (a, b) { return b[1] - a[1]; }).slice(0, 5), mx = top.length ? top[0][1] : 1;
    var stCount = ['aktif', 'menunggu', 'ditolak', 'ditangguhkan'].map(function (k) { return [k, tokoBy(k).length]; });
    h += '<div class="g2 mb"><div class="card rise"><div class="card-h"><div><div class="card-t">Toko terlaris minggu ini</div><div class="card-s">Berdasarkan paket yang selesai diambil</div></div></div><div class="bl">' +
      (top.map(function (r) { return '<div class="bl-r"><span>' + esc(r[0].nama) + '</span><div class="bar-t"><i style="width:' + Math.round(r[1] / mx * 100) + '%"></i></div><b>' + r[1] + '</b></div>'; }).join('') || '<p class="fhint">Belum ada data.</p>') + '</div></div>' +
      '<div class="card rise" style="--i:1"><div class="card-h"><div><div class="card-t">Sebaran status toko</div><div class="card-s">' + S.toko.length + ' toko terdaftar</div></div></div><div class="bl">' +
      stCount.map(function (r) { return '<div class="bl-r"><span>' + TSTATUS[r[0]][0] + '</span><div class="bar-t"><i style="width:' + Math.round(r[1] / S.toko.length * 100) + '%"></i></div><b>' + r[1] + '</b></div>'; }).join('') + '</div></div></div>';
    $('#page-dashboard').innerHTML = h;
  }

  /* ---------- RENDER: verifikasi toko ---------- */
  function vkCard(t) {
    var o = uById(t.owner), pend = t.status === 'menunggu', act = '';
    if (pend) act = '<button class="btn btn-p" data-act="setuju" data-id="' + t.id + '">' + svg('check') + 'Setujui</button><button class="btn btn-d" data-act="tolak" data-id="' + t.id + '">' + svg('x') + 'Tolak</button>';
    else act = '<button class="btn btn-o btn-sm" data-act="detail" data-id="' + t.id + '">Lihat detail</button>';
    return '<article class="card vk rise">' + fotoToko(t) +
      '<div><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">' + badge(TSTATUS[t.status]) + (t.premium && pend ? '<span class="badge-pro">' + svg('crown').replace('<svg', '<svg style="width:12px;height:12px"') + ' Prioritas</span>' : '') + '<span class="tag">' + KAT_USAHA[t.kat] + '</span></div>' +
      '<h3>' + esc(t.nama) + '</h3>' +
      '<div class="vk-info"><span>' + svg('users') + '<span><b>' + esc(o.nama) + '</b><br>' + esc(o.email) + '</span></span><span>' + svg('phone') + esc(t.telp) + '</span>' +
      '<span>' + svg('pin') + '<span>' + esc(t.alamat) + '<br><a class="link" href="' + osm(t) + '" target="_blank" rel="noopener">' + t.lat + ', ' + t.lng + ' &middot; buka peta</a></span></span>' +
      '<span>' + svg('clock') + 'Buka ' + t.buka + '&ndash;' + t.tutup + '<br>Daftar ' + tgl(t.dibuat) + '</span></div>' +
      (t.catatan && !pend ? '<div class="note" style="margin-top:12px">' + svg('info') + '<div><b>Catatan admin:</b> ' + esc(t.catatan) + '</div></div>' : '') + '</div>' +
      '<div class="vk-act">' + act + '</div></article>';
  }
  function renderVerifikasi() {
    var pend = tokoBy('menunggu').sort(function (a, b) { return (b.premium ? 1 : 0) - (a.premium ? 1 : 0) || a.dibuat - b.dibuat; });
    var rev = S.toko.filter(function (t) { return t.status === 'ditolak'; });
    var list = S.tabVerif === 'menunggu' ? pend : rev;
    var h = '<div class="sec-h rise"><h1>Verifikasi toko</h1><p>Toko baru belum bisa memposting paket. Periksa data, titik lokasi, dan foto. Penolakan wajib disertai alasan, dan penjual bisa memperbaiki lalu mengajukan ulang. Toko ReTaste Pro mendapat antrian prioritas.</p></div>' +
      tabs([['menunggu', 'Menunggu', pend.length], ['ditolak', 'Perlu perbaikan', rev.length]], S.tabVerif, 'tabVerif') +
      (list.length ? '<div class="ord-list">' + list.map(vkCard).join('') + '</div>' : empty(S.tabVerif === 'menunggu' ? 'Tidak ada antrian verifikasi' : 'Tidak ada toko yang ditolak', 'Toko baru akan muncul di sini setelah penjual mengisi profilnya.'));
    $('#page-verifikasi').innerHTML = h;
  }

  /* ---------- RENDER: daftar toko ---------- */
  function tokoRows() {
    var f = S.f, q = f.tq.toLowerCase();
    var list = S.toko.filter(function (t) { return (f.ts === 'semua' || t.status === f.ts) && (!q || (t.nama + ' ' + uById(t.owner).nama + ' ' + t.alamat).toLowerCase().indexOf(q) > -1); });
    if (!list.length) return '<p class="fhint" style="padding:10px 4px">Tidak ada toko yang cocok.</p>';
    return '<div class="tbl-w"><table class="tbl"><thead><tr><th>Toko</th><th>Pemilik</th><th>Status</th><th class="r">Rating</th><th class="r">Paket aktif</th><th class="r">Aksi</th></tr></thead><tbody>' +
      list.map(function (t) {
        var o = uById(t.owner), a = '<button class="btn btn-o btn-sm" data-act="detail" data-id="' + t.id + '">' + svg('eye') + 'Detail</button>';
        if (t.status === 'aktif') a += '<button class="btn btn-d btn-sm" data-act="tangguh" data-id="' + t.id + '">Tangguhkan</button>';
        else if (t.status === 'ditangguhkan') a += '<button class="btn btn-p btn-sm" data-act="aktifkan" data-id="' + t.id + '">Aktifkan</button>';
        else if (t.status === 'menunggu') a += '<button class="btn btn-p btn-sm" data-go="verifikasi">Verifikasi</button>';
        return '<tr><td><b>' + esc(t.nama) + '</b>' + (t.premium ? ' <span class="badge-pro">Pro</span>' : '') + '<br><span style="font-weight:600;color:var(--faint);font-size:.78rem">' + KAT_USAHA[t.kat] + '</span></td><td>' + esc(o.nama) + '</td><td>' + badge(TSTATUS[t.status]) + '</td>' +
          '<td class="r">' + (t.ulasan ? '&#9733; ' + t.rating.toFixed(1).replace('.', ',') + ' (' + t.ulasan + ')' : '-') + '</td><td class="r">' + aktifPaketToko(t.id).length + '</td><td><div class="acts">' + a + '</div></td></tr>';
      }).join('') + '</tbody></table></div>';
  }
  function renderToko() {
    var f = S.f, opt = function (v, t) { return '<option value="' + v + '"' + (f.ts === v ? ' selected' : '') + '>' + t + '</option>'; };
    $('#page-toko').innerHTML = '<div class="sec-h rise"><h1>Daftar toko</h1><p>Admin hanya boleh menangguhkan toko atau paket, tidak mengubah isi paket milik penjual.</p></div>' +
      '<div class="fbar rise"><div class="field"><label for="fTs">Status</label><select id="fTs" data-f="ts">' + opt('semua', 'Semua status') + opt('aktif', 'Aktif') + opt('menunggu', 'Menunggu verifikasi') + opt('ditolak', 'Perlu perbaikan') + opt('ditangguhkan', 'Ditangguhkan') + '</select></div>' +
      '<div class="field"><label for="fTq">Cari toko / pemilik / alamat</label><input id="fTq" data-f="tq" value="' + esc(f.tq) + '" placeholder="Ketik untuk mencari"></div></div>' +
      '<div class="card rise" id="tokoTbl">' + tokoRows() + '</div>';
  }

  /* ---------- RENDER: pengguna ---------- */
  function userRows() {
    var f = S.f, q = f.uq.toLowerCase();
    var list = S.users.filter(function (u) { return (f.ur === 'semua' || u.role === f.ur) && (!q || (u.nama + ' ' + u.email).toLowerCase().indexOf(q) > -1); });
    if (!list.length) return '<p class="fhint" style="padding:10px 4px">Tidak ada pengguna yang cocok.</p>';
    return '<div class="tbl-w"><table class="tbl"><thead><tr><th>Pengguna</th><th>Peran</th><th>Status akun</th><th>Terdaftar</th><th class="r">Report</th><th class="r">Aksi</th></tr></thead><tbody>' +
      list.map(function (u) {
        var a = u.role === 'admin' ? '<span class="fhint">-</span>' : u.aktif ? '<button class="btn btn-d btn-sm" data-act="tangguhAkun" data-id="' + u.id + '">' + svg('ban') + 'Tangguhkan</button>' : '<button class="btn btn-p btn-sm" data-act="pulihAkun" data-id="' + u.id + '">Pulihkan</button>';
        return '<tr><td>' + who(u) + '</td><td>' + badge(ROLE[u.role]) + '</td><td>' + (u.aktif ? '<span class="badge b-done">Aktif</span>' : '<span class="badge b-off">Ditangguhkan</span>' + (u.alasan ? '<br><span style="font-weight:600;color:var(--faint);font-size:.78rem">' + esc(u.alasan) + '</span>' : '')) + '</td><td>' + tgl(u.dibuat) + '</td><td class="r">' + (jmlReport(u.id) || '-') + '</td><td><div class="acts">' + a + '</div></td></tr>';
      }).join('') + '</tbody></table></div>';
  }
  function renderPengguna() {
    var f = S.f, opt = function (v, t) { return '<option value="' + v + '"' + (f.ur === v ? ' selected' : '') + '>' + t + '</option>'; };
    $('#page-pengguna').innerHTML = '<div class="sec-h rise"><h1>Pengguna</h1><p>Akun yang ditangguhkan tidak bisa login. Riwayat transaksinya tetap tersimpan.</p></div>' +
      '<div class="fbar rise"><div class="field"><label for="fUr">Peran</label><select id="fUr" data-f="ur">' + opt('semua', 'Semua peran') + opt('pembeli', 'Pembeli') + opt('penjual', 'Penjual') + opt('admin', 'Admin') + '</select></div>' +
      '<div class="field"><label for="fUq">Cari nama / email</label><input id="fUq" data-f="uq" value="' + esc(f.uq) + '" placeholder="Ketik untuk mencari"></div></div>' +
      '<div class="card rise" id="userTbl">' + userRows() + '</div>';
  }

  /* ---------- RENDER: laporan ---------- */
  function lapCard(l) {
    var t = tById(l.toko), pel = uById(l.pelapor), p = pById(l.paket);
    return '<article class="card lap rise"><div><div style="display:flex;gap:8px;flex-wrap:wrap">' + badge(LSTATUS[l.status]) + '<span class="tag">' + JENIS[l.jenis] + '</span>' + (l.tindakan ? '<span class="tag moss">' + TINDAKAN[l.tindakan] + '</span>' : '') + '</div>' +
      '<h3>' + esc(t.nama) + (p ? ' &middot; ' + esc(p.nama) : '') + '</h3><p>' + esc(l.alasan) + '</p>' +
      '<div class="lap-meta"><span>Pesanan ' + esc(l.kode) + '</span><span>Pelapor: ' + esc(pel.nama) + '</span><span>' + ago(l.dibuat) + '</span></div></div>' +
      '<div><button class="btn ' + (l.status === 'selesai' ? 'btn-o' : 'btn-p') + ' btn-sm" data-act="lapor" data-id="' + l.id + '">' + (l.status === 'selesai' ? 'Lihat' : 'Tangani') + '</button></div></article>';
  }
  function renderLaporan() {
    var by = function (s) { return S.laporan.filter(function (l) { return l.status === s; }).sort(function (a, b) { return b.dibuat - a.dibuat; }); };
    var b = by('baru'), d = by('diproses'), s = by('selesai'), list = { baru: b, diproses: d, selesai: s }[S.tabLap];
    $('#page-laporan').innerHTML = '<div class="sec-h rise"><h1>Laporan masalah</h1><p>Pilih tindakan untuk tiap laporan: abaikan, beri peringatan, tangguhkan paket, atau tangguhkan toko. Catatan admin wajib diisi.</p></div>' +
      tabs([['baru', 'Baru', b.length], ['diproses', 'Diproses', d.length], ['selesai', 'Selesai', s.length]], S.tabLap, 'tabLap') +
      (list.length ? '<div class="ord-list">' + list.map(lapCard).join('') + '</div>' : empty('Tidak ada laporan di sini', 'Laporan dengan status ini akan tampil di halaman ini.'));
  }
  function openLapor(id) {
    var l = S.laporan.filter(function (x) { return x.id === id; })[0]; if (!l) return;
    var t = tById(l.toko), pel = uById(l.pelapor), p = pById(l.paket), done = l.status === 'selesai';
    var head = '<div class="m-body"><h2>Laporan #' + l.id + '</h2><div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">' + badge(LSTATUS[l.status]) + '<span class="tag">' + JENIS[l.jenis] + '</span></div>' +
      '<div class="kv" style="margin-bottom:12px"><div><span>Pesanan</span><b>' + esc(l.kode) + '</b></div><div><span>Pelapor</span><b>' + esc(pel.nama) + '</b></div><div><span>Toko</span><b>' + esc(t.nama) + ' (' + TSTATUS[t.status][0] + ')</b></div><div><span>Paket</span><b>' + (p ? esc(p.nama) + ' (' + p.status + ')' : '-') + '</b></div><div><span>Dikirim</span><b>' + tglJam(l.dibuat) + '</b></div></div>' +
      '<div class="card-s" style="margin-bottom:4px">Alasan pelapor</div><p style="color:var(--text);font-weight:600">' + esc(l.alasan) + '</p>' +
      (l.foto ? '<div class="ph k-cam foto-lap">' + svg('info') + '<img src="assets/img/laporan/' + esc(l.foto) + '" alt="Foto bukti laporan" onerror="this.remove()"></div>' : '');
    if (done) { openModal(head + '<div class="note ok" style="margin-top:12px">' + svg('check') + '<div><b>' + TINDAKAN[l.tindakan] + '</b><br>' + esc(l.catatan) + '<br><small>Ditangani ' + tglJam(l.ditangani) + '</small></div></div></div>'); return; }
    var imp = {
      abaikan: 'Laporan ditutup tanpa tindakan ke penjual.',
      peringatan: 'Penjual menerima notifikasi peringatan. Toko dan paket tetap berjalan.',
      tangguhkan_paket: 'Paket ini dihentikan' + (p ? ' dan ' + S.pesanan.filter(function (o) { return o.paket === p.id && (o.status === 'siap_diambil' || o.status === 'menunggu_pembayaran'); }).length + ' pesanan aktif dibatalkan dengan refund penuh (simulasi).' : '.'),
      tangguhkan_toko: 'Semua paket aktif toko ditutup dan ' + aktifOrdToko(t.id).length + ' pesanan aktif dibatalkan dengan refund penuh (simulasi).'
    };
    var opts = [['abaikan', 'Abaikan'], ['peringatan', 'Beri peringatan'], ['tangguhkan_paket', 'Tangguhkan paket'], ['tangguhkan_toko', 'Tangguhkan toko']];
    openModal(head + '<div class="field" style="margin-top:14px"><label>Tindakan admin</label><div class="opts" id="opts">' + opts.map(function (o, i) {
      var dis = (o[0] === 'tangguhkan_paket' && (!p || p.status === 'ditangguhkan')) || (o[0] === 'tangguhkan_toko' && t.status === 'ditangguhkan');
      return '<label class="opt" ' + (dis ? 'style="opacity:.5;cursor:not-allowed"' : '') + '><input type="radio" name="tdk" value="' + o[0] + '"' + (i === 0 ? ' checked' : '') + (dis ? ' disabled' : '') + '><span class="rd"></span><span><b>' + o[1] + '</b><small>' + imp[o[0]] + '</small></span></label>';
    }).join('') + '</div></div>' +
      '<div class="field" style="margin-top:12px"><label for="lCat">Catatan admin (wajib)</label><textarea id="lCat" placeholder="Jelaskan dasar keputusan">' + esc(l.catatan) + '</textarea><span class="em" id="lCatE"></span></div>' +
      '<div style="display:flex;gap:10px;margin-top:16px">' + (l.status === 'baru' ? '<button class="btn btn-o btn-w" id="btnProses">Tandai diproses</button>' : '') + '<button class="btn btn-p btn-w" id="btnSimpan">Simpan keputusan</button></div></div>');
    if ($('#btnProses')) $('#btnProses').onclick = function () { l.status = 'diproses'; l.catatan = $('#lCat').value.trim(); closeModal(); toast('Laporan ditandai sedang diproses.'); render(); };
    $('#btnSimpan').onclick = function () {
      var tdk = $('input[name="tdk"]:checked').value, cat = $('#lCat').value.trim();
      if (cat.length < 5) { $('#lCat').parentNode.classList.add('err'); $('#lCatE').textContent = 'Catatan admin wajib diisi (minimal 5 karakter).'; return; }
      var msg = 'Laporan selesai. ' + TINDAKAN[tdk] + '.';
      if (tdk === 'tangguhkan_paket' && p) { var r = tangguhkanPaket(p); msg += ' ' + r.pesanan + ' pesanan dibatalkan.'; }
      if (tdk === 'tangguhkan_toko') { var r2 = tangguhkanToko(t, cat); msg += ' ' + r2.paket + ' paket ditutup, ' + r2.pesanan + ' pesanan dibatalkan.'; }
      if (tdk === 'peringatan') msg += ' Notifikasi dikirim ke ' + uById(t.owner).nama + '.';
      l.status = 'selesai'; l.tindakan = tdk; l.catatan = cat; l.ditangani = now();
      closeModal(); toast(msg); render();
    };
  }

  /* ---------- RENDER: detail transaksi (riwayat + pajak) ---------- */
  function trxList() {
    var f = S.f, q = f.rq.toLowerCase();
    return S.pesanan.filter(function (o) {
      if (f.rs !== 'semua' && o.status !== f.rs) return false;
      if (f.rt !== 'semua' && String(o.toko) !== f.rt) return false;
      return !q || (o.kode + ' ' + uById(o.pembeli).nama + ' ' + tById(o.toko).nama).toLowerCase().indexOf(q) > -1;
    }).sort(function (a, b) { return b.ts - a.ts || b.id - a.id; });
  }
  function trxRows() {
    var list = trxList();
    if (!list.length) return '<p class="fhint" style="padding:10px 4px">Tidak ada transaksi yang cocok.</p>';
    var shown = list.slice(0, S.f.rn), paid = list.filter(isPaid), fs = 'font-weight:600;color:var(--faint);font-size:.78rem';
    var sum = function (k) { return paid.reduce(function (a, o) { return a + o[k]; }, 0); };
    return '<div class="tbl-w"><table class="tbl"><thead><tr><th>Transaksi</th><th>Pembeli</th><th>Toko &amp; paket</th><th class="r">Pajak</th><th>Status</th><th class="r">Aksi</th></tr></thead><tbody>' +
      shown.map(function (o) {
        var p = pById(o.paket);
        return '<tr><td style="white-space:nowrap">' + esc(o.kode) + '<br><span style="' + fs + '">' + tglJam(o.ts) + '</span></td><td>' + esc(uById(o.pembeli).nama) + '</td>' +
          '<td>' + esc(tById(o.toko).nama) + '<br><span style="' + fs + '">' + (p ? esc(p.nama) : '-') + ' &times; ' + o.jumlah + '</span></td>' +
          '<td class="r">' + (isPaid(o) ? rp(o.pajak) : '-') + '</td><td>' + badge(OSTATUS[o.status]) + '</td>' +
          '<td><div class="acts"><button class="btn btn-o btn-sm" data-act="trx" data-id="' + o.id + '">' + svg('eye') + 'Detail</button></div></td></tr>';
      }).join('') + '</tbody><tfoot><tr><td colspan="3">Transaksi kena pajak: ' + num(paid.length) + ' dari ' + num(list.length) + '</td><td class="r">' + rp(sum('pajak')) + '</td><td colspan="2"></td></tr></tfoot></table></div>' +
      (list.length > shown.length ? '<div style="text-align:center;margin-top:14px"><button class="btn btn-o" data-act="trxMore">Tampilkan lebih banyak (' + num(list.length - shown.length) + ' lagi)</button></div>' : '');
  }
  function renderTransaksi() {
    var s = stats(), f = S.f, pct = S.set.pajak_persen, hari = dayKey(now());
    var pajakHari = paidOrders().filter(function (o) { return dayKey(o.ts) === hari; }).reduce(function (a, o) { return a + o.pajak; }, 0);
    var lim7 = dayKey(now() - 6 * 86400), pajak7 = paidOrders().filter(function (o) { return dayKey(o.ts) >= lim7; }).reduce(function (a, o) { return a + o.pajak; }, 0);
    var opt = function (cur, v, t) { return '<option value="' + v + '"' + (cur === v ? ' selected' : '') + '>' + t + '</option>'; };
    var stOpts = opt(f.rs, 'semua', 'Semua status') + Object.keys(OSTATUS).map(function (k) { return opt(f.rs, k, OSTATUS[k][0]); }).join('');
    var tkOpts = opt(f.rt, 'semua', 'Semua toko') + S.toko.filter(function (t) { return t.status !== 'menunggu' && t.status !== 'ditolak'; }).map(function (t) { return opt(f.rt, String(t.id), esc(t.nama)); }).join('');
    $('#page-transaksi').innerHTML = '<div class="sec-h rise"><h1>Detail transaksi</h1><p>Riwayat pajak ' + pct + '% dari setiap transaksi.</p></div>' +
      '<div class="g4 mb">' +
      '<div class="card stat hl hov rise"><div class="stat-top"><span class="stat-l">Pendapatan PPN</span><span class="stat-ic" data-i="tag"></span></div><div class="stat-v sm">' + rp(s.pajak) + '</div><div class="stat-n">PPN ' + pct + '% masuk ke admin</div></div>' +
      '<div class="card stat hov rise" style="--i:1"><div class="stat-top"><span class="stat-l">PPN Hari Ini</span><span class="stat-ic ic-b" data-i="clock"></span></div><div class="stat-v sm">' + rp(pajakHari) + '</div><div class="stat-n">Dari transaksi hari ini</div></div>' +
      '<div class="card stat hov rise" style="--i:2"><div class="stat-top"><span class="stat-l">PPN 7 Hari Terakhir</span><span class="stat-ic ic-g" data-i="chart"></span></div><div class="stat-v sm">' + rp(pajak7) + '</div><div class="stat-n">Termasuk hari ini</div></div>' +
      '<div class="card stat hov rise" style="--i:3"><div class="stat-top"><span class="stat-l">Transaksi Kena Pajak</span><span class="stat-ic ic-a" data-i="bag"></span></div><div class="stat-v">' + num(s.nTrx) + ' <small>transaksi</small></div><div class="stat-n">Berstatus terbayar</div></div></div>' +
      '<div class="note ok rise" style="margin:0 0 16px">' + svg('info') + '<div>PPN ' + pct + '% ditambahkan ke setiap pembelian dan dicatat sebagai pendapatan admin. Admin hanya mengelola PPN, jadi harga dan pendapatan toko tidak ditampilkan di sini. PPN hanya dihitung untuk transaksi berstatus Selesai, Siap diambil, atau Tidak diambil.</div></div>' +
      '<div class="fbar rise"><div class="field"><label for="fRs">Status</label><select id="fRs" data-f="rs">' + stOpts + '</select></div>' +
      '<div class="field"><label for="fRt">Toko</label><select id="fRt" data-f="rt">' + tkOpts + '</select></div>' +
      '<div class="field"><label for="fRq">Cari kode / pembeli / toko</label><input id="fRq" data-f="rq" value="' + esc(f.rq) + '" placeholder="Ketik untuk mencari"></div></div>' +
      '<div class="card rise" id="trxTbl">' + trxRows() + '</div>';
  }
  function openTrx(id) {
    var o = S.pesanan.filter(function (x) { return x.id === id; })[0]; if (!o) return;
    var p = pById(o.paket), paid = isPaid(o), pct = S.set.pajak_persen;
    var info = paid ? 'PPN ' + rp(o.pajak) + ' dicatat sebagai pendapatan admin.' : o.status === 'dibatalkan' ? 'Pesanan dibatalkan. PPN tidak dicatat sebagai pendapatan dan dana dikembalikan ke pembeli (simulasi).' + (o.alasan ? ' Alasan: ' + esc(o.alasan) + '.' : '') : 'Belum dibayar. PPN dicatat setelah pembayaran berhasil.';
    openModal('<div class="m-body"><div style="display:flex;gap:8px;flex-wrap:wrap">' + badge(OSTATUS[o.status]) + '</div><h2 style="margin-top:8px">' + esc(o.kode) + '</h2><p>' + tglJam(o.ts) + '</p>' +
      '<div class="kv" style="margin-top:12px"><div><span>Pembeli</span><b>' + esc(uById(o.pembeli).nama) + '</b></div><div><span>Toko</span><b>' + esc(tById(o.toko).nama) + '</b></div><div><span>Paket</span><b>' + (p ? esc(p.nama) : '-') + '</b></div>' +
      '<div><span>Jumlah</span><b>' + o.jumlah + '</b></div>' +
      '<div><span>Tarif PPN</span><b>' + pct + '%</b></div><div><span>PPN untuk admin</span><b>' + rp(o.pajak) + '</b></div></div>' +
      '<div class="note' + (paid ? ' ok' : '') + '">' + svg('info') + '<div>' + info + '</div></div></div>');
  }

  /* ---------- RENDER: report akun ---------- */
  function akunCard(r) {
    var a = uById(r.akun), pel = uById(r.pelapor), done = r.status === 'selesai';
    return '<article class="card lap rise"><div><div style="display:flex;gap:8px;flex-wrap:wrap">' + badge(LSTATUS[r.status]) + '<span class="tag">' + JENIS_AKUN[r.jenis] + '</span>' + badge(ROLE[a.role]) + (!a.aktif ? '<span class="badge b-off">Akun ditangguhkan</span>' : '') + (r.tindakan ? '<span class="tag moss">' + TINDAKAN_AKUN[r.tindakan] + '</span>' : '') + '</div>' +
      '<h3>' + esc(a.nama) + '</h3><p>' + esc(r.alasan) + '</p>' +
      '<div class="lap-meta"><span>' + esc(a.email) + '</span><span>Pelapor: ' + esc(pel.nama) + '</span><span>' + ago(r.dibuat) + '</span></div></div>' +
      '<div><button class="btn ' + (done ? 'btn-o' : 'btn-p') + ' btn-sm" data-act="akunLapor" data-id="' + r.id + '">' + (done ? 'Lihat' : 'Tangani') + '</button></div></article>';
  }
  function renderAkun() {
    var by = function (s) { return S.reportAkun.filter(function (r) { return r.status === s; }).sort(function (a, b) { return b.dibuat - a.dibuat; }); };
    var b = by('baru'), d = by('diproses'), s = by('selesai'), list = { baru: b, diproses: d, selesai: s }[S.tabAkun];
    $('#page-akun').innerHTML = '<div class="sec-h rise"><h1>Report akun</h1><p>Laporan terhadap akun pembeli atau penjual. Pilih tindakan: abaikan, beri peringatan, atau tangguhkan akun. Catatan admin wajib diisi.</p></div>' +
      tabs([['baru', 'Baru', b.length], ['diproses', 'Diproses', d.length], ['selesai', 'Selesai', s.length]], S.tabAkun, 'tabAkun') +
      (list.length ? '<div class="ord-list">' + list.map(akunCard).join('') + '</div>' : empty('Tidak ada report di sini', 'Report akun dengan status ini akan tampil di halaman ini.'));
  }
  function openAkun(id) {
    var r = S.reportAkun.filter(function (x) { return x.id === id; })[0]; if (!r) return;
    var a = uById(r.akun), pel = uById(r.pelapor), done = r.status === 'selesai';
    var head = '<div class="m-body"><h2>Report akun #' + r.id + '</h2><div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">' + badge(LSTATUS[r.status]) + '<span class="tag">' + JENIS_AKUN[r.jenis] + '</span></div>' +
      '<div class="kv" style="margin-bottom:12px"><div><span>Akun dilaporkan</span><b>' + esc(a.nama) + ' (' + ROLE[a.role][0] + ')</b></div><div><span>Email</span><b>' + esc(a.email) + '</b></div><div><span>Status akun</span><b>' + (a.aktif ? 'Aktif' : 'Ditangguhkan') + '</b></div>' +
      '<div><span>Total report untuk akun ini</span><b>' + jmlReport(a.id) + '</b></div><div><span>Pelapor</span><b>' + esc(pel.nama) + '</b></div><div><span>Dikirim</span><b>' + tglJam(r.dibuat) + '</b></div></div>' +
      '<div class="card-s" style="margin-bottom:4px">Alasan pelapor</div><p style="color:var(--text);font-weight:600">' + esc(r.alasan) + '</p>' +
      (!a.aktif && a.alasan ? '<div class="note">' + svg('info') + '<div><b>Alasan penangguhan:</b> ' + esc(a.alasan) + '</div></div>' : '');
    if (done) { openModal(head + '<div class="note ok" style="margin-top:12px">' + svg('check') + '<div><b>' + TINDAKAN_AKUN[r.tindakan] + '</b><br>' + esc(r.catatan) + '<br><small>Ditangani ' + tglJam(r.ditangani) + '</small></div></div></div>'); return; }
    var imp = {
      abaikan: 'Report ditutup tanpa tindakan ke pemilik akun.',
      peringatan: 'Pemilik akun menerima notifikasi peringatan. Akun tetap aktif.',
      tangguhkan: 'Akun tidak bisa login. ' + dampakText(a, dampakAkun(a))
    };
    var opts = [['abaikan', 'Abaikan'], ['peringatan', 'Beri peringatan'], ['tangguhkan', 'Tangguhkan akun']];
    openModal(head + '<div class="field" style="margin-top:14px"><label>Tindakan admin</label><div class="opts" id="opts">' + opts.map(function (o, i) {
      var dis = o[0] === 'tangguhkan' && (!a.aktif || a.role === 'admin');
      return '<label class="opt" ' + (dis ? 'style="opacity:.5;cursor:not-allowed"' : '') + '><input type="radio" name="tdk" value="' + o[0] + '"' + (i === 0 ? ' checked' : '') + (dis ? ' disabled' : '') + '><span class="rd"></span><span><b>' + o[1] + '</b><small>' + imp[o[0]] + '</small></span></label>';
    }).join('') + '</div></div>' +
      '<div class="field" style="margin-top:12px"><label for="aCat">Catatan admin (wajib)</label><textarea id="aCat" placeholder="Jelaskan dasar keputusan. Untuk penangguhan, catatan ini tersimpan sebagai alasan penangguhan akun.">' + esc(r.catatan) + '</textarea><span class="em" id="aCatE"></span></div>' +
      '<div style="display:flex;gap:10px;margin-top:16px">' + (r.status === 'baru' ? '<button class="btn btn-o btn-w" id="btnProses">Tandai diproses</button>' : '') + '<button class="btn btn-p btn-w" id="btnSimpan">Simpan keputusan</button></div></div>');
    if ($('#btnProses')) $('#btnProses').onclick = function () { r.status = 'diproses'; r.catatan = $('#aCat').value.trim(); closeModal(); toast('Report ditandai sedang diproses.'); render(); };
    $('#btnSimpan').onclick = function () {
      var tdk = $('input[name="tdk"]:checked').value, cat = $('#aCat').value.trim();
      if (cat.length < 5) { $('#aCat').parentNode.classList.add('err'); $('#aCatE').textContent = 'Catatan admin wajib diisi (minimal 5 karakter).'; return; }
      var msg = 'Report selesai. ' + TINDAKAN_AKUN[tdk] + '.';
      if (tdk === 'tangguhkan') msg += ' ' + ringkasTangguh(a, tangguhkanAkun(a, cat));
      if (tdk === 'peringatan') msg += ' Notifikasi dikirim ke ' + a.nama + '.';
      r.status = 'selesai'; r.tindakan = tdk; r.catatan = cat; r.ditangani = now();
      closeModal(); toast(msg); render();
    };
  }

  /* ---------- RENDER: kategori & pengaturan ---------- */
  var CFG = [
    ['radius_km', 'Radius pencarian bawaan', 'km', 1, 50], ['diskon_minimal_persen', 'Diskon minimal harga paket', '%', 10, 90],
    ['batas_bayar_menit', 'Batas waktu pembayaran', 'menit', 5, 60], ['maks_per_pembeli', 'Maks. jumlah per pembeli', 'paket', 1, 10],
    ['tutup_pesan_menit', 'Pemesanan ditutup sebelum jam ambil berakhir', 'menit', 0, 60], ['maks_foto_kb', 'Ukuran maks. unggah foto', 'KB', 256, 10240],
    ['komisi_standar_persen', 'Komisi platform (akun gratis)', '%', 0, 50], ['komisi_pro_persen', 'Komisi platform (ReTaste Pro)', '%', 0, 50],
    ['maks_paket_aktif_gratis', 'Maks. paket aktif akun gratis', 'paket', 1, 20], ['diskon_mahasiswa_persen', 'Diskon harga mahasiswa', '%', 0, 30]
  ];
  function renderPengaturan() {
    var h = '<div class="sec-h rise"><h1>Kategori &amp; pengaturan</h1><p>Perubahan berlaku untuk transaksi berikutnya. Pesanan yang sudah berjalan tidak ikut berubah.</p></div><div class="g2">' +
      '<form class="card rise" id="fSet" novalidate><div class="card-h"><div class="card-t">Pengaturan sistem</div></div><div class="set-grid">' +
      CFG.map(function (c) { return '<div class="field"><label for="s_' + c[0] + '">' + c[1] + '<em>' + c[2] + '</em></label><input id="s_' + c[0] + '" type="number" min="' + c[3] + '" max="' + c[4] + '" value="' + S.set[c[0]] + '" inputmode="numeric"><span class="fhint">Rentang ' + c[3] + '&ndash;' + c[4] + '</span><span class="em" data-em="' + c[0] + '"></span></div>'; }).join('') +
      '<div class="note ok" style="grid-column:1/-1">' + svg('info') + '<div>PPN transaksi ditetapkan tetap sebesar 11%. Pembeli membayar PPN di atas harga jual, dan PPN pesanan tercatat sebagai pendapatan admin.</div></div>' +
      '</div><div style="margin-top:18px"><button class="btn btn-p" type="submit">Simpan pengaturan</button></div></form>' +
      '<div class="card rise" style="--i:1"><div class="card-h"><div><div class="card-t">Kategori paket</div><div class="card-s">Dipakai penjual saat memposting paket</div></div></div>' +
      S.kat.map(function (k) {
        var used = S.paket.filter(function (p) { return p.kat === k.id; }).length;
        return '<div class="kat-row"><b>' + esc(k.nama) + '</b><small>' + used + ' paket</small><button class="btn btn-o btn-sm" data-act="katEdit" data-id="' + k.id + '" aria-label="Ubah ' + esc(k.nama) + '">' + svg('edit') + '</button><button class="btn btn-d btn-sm" data-act="katDel" data-id="' + k.id + '" aria-label="Hapus ' + esc(k.nama) + '"' + (used ? ' disabled title="Masih dipakai paket"' : '') + '>' + svg('trash') + '</button></div>';
      }).join('') +
      '<form id="fKat" style="margin-top:14px" novalidate><div class="field"><label for="kNew">Tambah kategori</label><div style="display:flex;gap:8px"><input id="kNew" placeholder="Contoh: Sayur & Buah" maxlength="50"><button class="btn btn-p" type="submit">' + svg('plus') + 'Tambah</button></div><span class="em" id="kE"></span></div></form></div></div>';
    $('#page-pengaturan').innerHTML = h;
  }

  function render() {
    ({ dashboard: renderDashboard, verifikasi: renderVerifikasi, toko: renderToko, pengguna: renderPengguna, laporan: renderLaporan, akun: renderAkun, transaksi: renderTransaksi, pengaturan: renderPengaturan })[S.page]();
    fillIcons($('#page-' + S.page));
    var s = stats(); $('#cntV').textContent = s.menunggu || ''; $('#cntL').textContent = s.laporBaru || ''; $('#cntA').textContent = s.akunBaru || '';
  }

  /* ---------- AKSI ---------- */
  function openDetail(id) {
    var t = tById(id), o = uById(t.owner), ords = S.pesanan.filter(function (x) { return x.toko === id && x.status === 'selesai'; });
    openModal('<div class="m-img">' + fotoToko(t) + '</div><div class="m-body"><div style="display:flex;gap:8px;flex-wrap:wrap">' + badge(TSTATUS[t.status]) + (t.premium ? '<span class="badge-pro">Pro</span>' : '') + '</div><h2 style="margin-top:8px">' + esc(t.nama) + '</h2><p>' + KAT_USAHA[t.kat] + ' &middot; ' + esc(t.alamat) + '</p>' +
      '<div class="kv"><div><span>Pemilik</span><b>' + esc(o.nama) + '</b></div><div><span>Email</span><b>' + esc(o.email) + '</b></div><div><span>Telepon</span><b>' + esc(t.telp) + '</b></div><div><span>Jam operasional</span><b>' + t.buka + '&ndash;' + t.tutup + '</b></div>' +
      '<div><span>Titik lokasi</span><b><a class="link" href="' + osm(t) + '" target="_blank" rel="noopener">' + t.lat + ', ' + t.lng + '</a></b></div><div><span>Rating</span><b>' + (t.ulasan ? t.rating.toFixed(1).replace('.', ',') + ' (' + t.ulasan + ' ulasan)' : 'Belum ada') + '</b></div>' +
      '<div><span>Paket aktif</span><b>' + aktifPaketToko(id).length + '</b></div><div><span>Pesanan aktif</span><b>' + aktifOrdToko(id).length + '</b></div><div><span>Paket terselamatkan</span><b>' + ords.reduce(function (a, x) { return a + x.jumlah; }, 0) + '</b></div><div><span>Terdaftar</span><b>' + tgl(t.dibuat) + '</b></div></div>' +
      (t.catatan ? '<div class="note" style="margin-top:12px">' + svg('info') + '<div><b>Catatan admin:</b> ' + esc(t.catatan) + '</div></div>' : '') + '</div>');
  }
  function setuju(id) {
    var t = tById(id); confirmBox('Setujui toko?', esc(t.nama) + ' akan berstatus Aktif dan bisa langsung memposting paket.', 'Ya, setujui', false, function () {
      t.status = 'aktif'; t.catatan = ''; toast(t.nama + ' disetujui dan kini aktif.'); render();
    });
  }
  function tolak(id) {
    var t = tById(id); reasonBox('Tolak toko', 'Penjual menerima alasan ini, lalu bisa memperbaiki data dan mengajukan ulang: <b>' + esc(t.nama) + '</b>.', 'Alasan penolakan (wajib)', 'Tolak toko', function (v) {
      t.status = 'ditolak'; t.catatan = v; toast(t.nama + ' ditolak. Alasan dikirim ke penjual.'); render();
    });
  }
  function tangguh(id) {
    var t = tById(id), np = aktifPaketToko(id).length, no = aktifOrdToko(id).length;
    reasonBox('Tangguhkan toko', 'Menangguhkan <b>' + esc(t.nama) + '</b> akan menutup <b>' + np + ' paket aktif</b> dan membatalkan <b>' + no + ' pesanan aktif</b> dengan refund penuh (simulasi).', 'Alasan penangguhan (wajib)', 'Tangguhkan toko', function (v) {
      var r = tangguhkanToko(t, v); toast(t.nama + ' ditangguhkan. ' + r.paket + ' paket ditutup, ' + r.pesanan + ' pesanan dibatalkan.'); render();
    });
  }
  function aktifkan(id) {
    var t = tById(id); confirmBox('Aktifkan kembali toko?', esc(t.nama) + ' bisa memposting paket lagi. Paket yang sudah ditangguhkan tetap tertutup.', 'Ya, aktifkan', false, function () { t.status = 'aktif'; t.catatan = ''; toast(t.nama + ' diaktifkan kembali.'); render(); });
  }
  function tangguhAkunUI(id) {
    var u = uById(id), d = dampakAkun(u);
    reasonBox('Tangguhkan akun', 'Akun <b>' + esc(u.nama) + '</b> tidak akan bisa login. ' + dampakText(u, d) + ' Riwayat transaksinya tetap tersimpan.', 'Alasan penangguhan (wajib)', 'Tangguhkan akun', function (v) {
      var r = tangguhkanAkun(u, v); toast('Akun ' + u.nama + ' ditangguhkan. ' + ringkasTangguh(u, r)); render();
    }, '', 'Contoh: terbukti mengirim spam atau berulang kali tidak mengambil pesanan');
  }
  function pulihAkunUI(id) {
    var u = uById(id);
    confirmBox('Pulihkan akun?', esc(u.nama) + ' bisa login kembali.' + (u.role === 'penjual' ? ' Toko yang ikut ditangguhkan tidak otomatis aktif. Aktifkan lewat Daftar Toko.' : ''), 'Ya, pulihkan', false, function () {
      pulihkanAkun(u); toast('Akun ' + u.nama + ' dipulihkan.'); render();
    });
  }
  function refreshRows() {
    if (S.page === 'toko') $('#tokoTbl').innerHTML = tokoRows();
    else if (S.page === 'transaksi') $('#trxTbl').innerHTML = trxRows();
    else $('#userTbl').innerHTML = userRows();
    fillIcons(document);
  }
  function katForm(k) {
    openModal('<div class="m-body"><h2>Ubah kategori</h2><div class="field"><label for="kName">Nama kategori</label><input id="kName" value="' + esc(k.nama) + '" maxlength="50"><span class="em" id="kNE"></span></div><button class="btn btn-p btn-w" id="btnK" style="margin-top:16px">Simpan</button></div>');
    $('#btnK').onclick = function () {
      var v = $('#kName').value.trim(), err = katErr(v, k.id); if (err) { $('#kName').parentNode.classList.add('err'); $('#kNE').textContent = err; return; }
      k.nama = v; closeModal(); toast('Kategori diperbarui.'); render();
    };
  }
  function katErr(v, id) {
    if (v.length < 2) return 'Nama kategori minimal 2 karakter.';
    if (S.kat.some(function (k) { return k.id !== id && k.nama.toLowerCase() === v.toLowerCase(); })) return 'Nama kategori sudah ada.';
    return '';
  }
  function simpanSet(e) {
    e.preventDefault(); var bad = 0, vals = {};
    $$('#fSet .field.err').forEach(function (x) { x.classList.remove('err'); }); $$('#fSet [data-em]').forEach(function (x) { x.textContent = ''; });
    CFG.forEach(function (c) {
      var v = parseFloat($('#s_' + c[0]).value), s = $('[data-em="' + c[0] + '"]');
      if (!(v >= c[3] && v <= c[4])) { bad++; s.textContent = 'Isi angka ' + c[3] + ' sampai ' + c[4] + '.'; s.closest('.field').classList.add('err'); } else vals[c[0]] = v;
    });
    if (!bad && vals.komisi_pro_persen > vals.komisi_standar_persen) { bad++; var s2 = $('[data-em="komisi_pro_persen"]'); s2.textContent = 'Komisi Pro tidak boleh lebih tinggi dari komisi akun gratis.'; s2.closest('.field').classList.add('err'); }
    if (bad) return toast('Periksa kembali isian yang ditandai merah.', true);
    Object.keys(vals).forEach(function (k) { S.set[k] = vals[k]; }); toast('Pengaturan disimpan. Berlaku untuk transaksi berikutnya.');
  }

  /* ---------- event ---------- */
  document.addEventListener('click', function (e) {
    var g = e.target.closest('[data-go]'); if (g) { go(g.getAttribute('data-go')); return; }
    var a = e.target.closest('[data-act]'); if (!a || a.disabled) return;
    var act = a.getAttribute('data-act'), id = +a.getAttribute('data-id');
    if (act === 'close') closeModal();
    else if (act === 'tab') { S[a.getAttribute('data-key')] = a.getAttribute('data-v'); render(); }
    else if (act === 'chart') { S.chart = a.getAttribute('data-v'); render(); }
    else if (act === 'detail') openDetail(id);
    else if (act === 'setuju') setuju(id);
    else if (act === 'tolak') tolak(id);
    else if (act === 'tangguh') tangguh(id);
    else if (act === 'aktifkan') aktifkan(id);
    else if (act === 'tangguhAkun') tangguhAkunUI(id);
    else if (act === 'pulihAkun') pulihAkunUI(id);
    else if (act === 'akunLapor') openAkun(id);
    else if (act === 'trx') openTrx(id);
    else if (act === 'trxMore') { S.f.rn += 25; refreshRows(); }
    else if (act === 'lapor') openLapor(id);
    else if (act === 'katEdit') katForm(S.kat.filter(function (k) { return k.id === id; })[0]);
    else if (act === 'katDel') {
      var k = S.kat.filter(function (x) { return x.id === id; })[0];
      confirmBox('Hapus kategori?', 'Kategori <b>' + esc(k.nama) + '</b> akan dihapus. Kategori yang masih dipakai paket tidak bisa dihapus.', 'Ya, hapus', true, function () { S.kat = S.kat.filter(function (x) { return x.id !== id; }); toast('Kategori dihapus.'); render(); });
    }
  });
  document.addEventListener('change', function (e) {
    var f = e.target.getAttribute && e.target.getAttribute('data-f');
    if (f && e.target.tagName === 'SELECT') { S.f[f] = e.target.value; if (S.page === 'transaksi') S.f.rn = 25; refreshRows(); }
  });
  document.addEventListener('input', function (e) {
    var f = e.target.getAttribute && e.target.getAttribute('data-f');
    if (f && e.target.tagName === 'INPUT') { S.f[f] = e.target.value; if (S.page === 'transaksi') S.f.rn = 25; refreshRows(); }
  });
  document.addEventListener('submit', function (e) {
    e.preventDefault();
    if (e.target.id === 'fSet') simpanSet(e);
    else if (e.target.id === 'fKat') {
      var v = $('#kNew').value.trim(), err = katErr(v, 0); if (err) { $('#kE').textContent = err; $('#kNew').parentNode.parentNode.classList.add('err'); return; }
      S.kat.push({ id: Math.max.apply(null, S.kat.map(function (k) { return k.id; }).concat([0])) + 1, nama: v }); toast('Kategori ditambahkan.'); render();
    }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !document.getElementById('swal')) { closeModal(); closeSide(); } });
  $('#modal').addEventListener('mousedown', function (e) { if (e.target.id === 'modal') closeModal(); });
  $('#burger').addEventListener('click', openSide);
  $('#scrim').addEventListener('click', closeSide);

  function tick() {
    var d = new Date(now() * 1000);
    $('#clkT').textContent = d.toLocaleTimeString('id-ID', { hour12: false, timeZone: TZ }).replace(/\./g, ':');
    $('#clkD').textContent = d.toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric', timeZone: TZ });
  }

  buildNav(); fillIcons(document);
  var start = (location.hash || '').replace('#', '');
  go(TITLES[start] ? start : 'dashboard');
  tick(); setInterval(tick, 1000);
})();

</script>
</body>
</html>