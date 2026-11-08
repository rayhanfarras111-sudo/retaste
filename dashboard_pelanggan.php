<?php
/**
 * ReTaste - Dashboard Pelanggan (pembeli)
 * Letakkan di: htdocs/retaste/dashboard_pelanggan.php  |  aset di: assets/
 *
 * Struktur layout mengikuti dashboard GEND-OFF, isi mengikuti alur sistem pembeli:
 * cari paket tersedia -> pesan -> bayar (15 menit) -> ambil dengan kode -> ulasan / laporan.
 */
session_start();
date_default_timezone_set('Asia/Jakarta');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'pembeli') {
    header('Location: login.php?role=pembeli');
    exit;
}

$nama   = $_SESSION['nama']  ?? ($_SESSION['username'] ?? 'Andi');
$email  = $_SESSION['email'] ?? 'andi@rejekisore.test';
$inisial = mb_strtoupper(mb_substr($nama, 0, 1));
$tahun   = date('Y');

/* Dashboard data is loaded from MySQL; packages are fetched from api_paket.php. */
$now = time();
$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
require __DIR__ . '/koneksi.php';

$settings = $pdo->query('SELECT kunci, nilai FROM pengaturan')->fetchAll(PDO::FETCH_KEY_PAIR);
$pengaturan = [
    'diskon_minimal_persen' => (int)($settings['diskon_minimal_persen'] ?? 50),
    'batas_bayar_menit' => (int)($settings['batas_bayar_menit'] ?? 15),
    'maks_per_pembeli' => (int)($settings['maks_per_pembeli'] ?? 2),
    'tutup_pesan_menit' => (int)($settings['tutup_pesan_menit_sebelum'] ?? 10),
    'maks_foto_kb' => (int)($settings['maks_ukuran_foto_kb'] ?? 2048),
    'komisi_standar_persen' => (int)($settings['komisi_standar_persen'] ?? 10),
    'maks_paket_aktif_gratis' => (int)($settings['maks_paket_aktif_gratis'] ?? 2),
    'diskon_mahasiswa_pct' => (int)($settings['diskon_mahasiswa_persen'] ?? 5),
    'pajak_persen' => 11,
];

$userStmt = $pdo->prepare(
    'SELECT nama, email, no_telp, email_kampus, email_kampus_terverifikasi_pada
     FROM users WHERE id_user = ? AND role = \'pembeli\' LIMIT 1'
);
$userStmt->execute([(int)$_SESSION['user_id']]);
$buyer = $userStmt->fetch();
if (!$buyer) {
    session_destroy();
    header('Location: login.php?role=pembeli');
    exit;
}
$nama = $buyer['nama'];
$email = $buyer['email'];
$inisial = mb_strtoupper(mb_substr($nama, 0, 1));
$pembeli = [
    'nama' => $nama,
    'email' => $email,
    'no_telp' => $buyer['no_telp'] ?? '',
    'email_kampus' => $buyer['email_kampus'] ?? '',
    'mahasiswa' => $buyer['email_kampus_terverifikasi_pada'] !== null,
];
$kategori = $pdo->query('SELECT id_kategori AS id, nama FROM kategori ORDER BY id_kategori')->fetchAll();
$paket = [];
$ordersStmt = $pdo->prepare(
    "SELECT o.id_pesanan AS id, o.kode_pesanan AS kode, o.id_paket AS paket_id,
            p.nama_paket AS nama, t.nama_toko AS toko, t.id_toko AS toko_id,
            p.foto, p.id_kategori AS kategori, p.harga_normal AS normal,
            o.harga_satuan AS satuan, o.jumlah, o.potongan_mahasiswa AS pot_mhs,
            o.ppn_nominal AS pajak,
            o.status, UNIX_TIMESTAMP(o.batas_bayar) AS batas_bayar,
            o.kode_pengambilan AS kode_ambil,
            UNIX_TIMESTAMP(CONCAT(p.tanggal_ambil, ' ', p.jam_mulai)) AS ambil_mulai,
            UNIX_TIMESTAMP(CONCAT(p.tanggal_ambil, ' ', p.jam_selesai)) AS ambil_selesai,
            UNIX_TIMESTAMP(o.created_at) AS dibuat, UNIX_TIMESTAMP(o.waktu_selesai) AS selesai_ts,
            u.rating AS rating_ulasan, u.komentar AS komentar_ulasan,
            EXISTS (SELECT 1 FROM laporan l WHERE l.id_pesanan = o.id_pesanan AND l.id_pelapor = o.id_pembeli) AS dilaporkan
     FROM pesanan o
     JOIN paket p ON p.id_paket = o.id_paket
     JOIN toko t ON t.id_toko = p.id_toko
     LEFT JOIN ulasan u ON u.id_pesanan = o.id_pesanan
     WHERE o.id_pembeli = ?
     ORDER BY o.created_at DESC"
);
$ordersStmt->execute([(int)$_SESSION['user_id']]);
$pesanan = array_map(static function (array $order): array {
    $review = $order['rating_ulasan'] === null ? null : [
        'rating' => (int)$order['rating_ulasan'],
        'komentar' => $order['komentar_ulasan'] ?? '',
    ];
    unset($order['rating_ulasan'], $order['komentar_ulasan']);
    $order['id'] = (int)$order['id'];
    $order['paket_id'] = (int)$order['paket_id'];
    $order['toko_id'] = (int)$order['toko_id'];
    $order['kategori'] = (int)$order['kategori'];
    $order['normal'] = (int)$order['normal'];
    $order['satuan'] = (int)$order['satuan'];
    $order['jumlah'] = (int)$order['jumlah'];
    $order['pot_mhs'] = (int)$order['pot_mhs'];
    $order['pajak'] = (int)$order['pajak'];
    $order['dilaporkan'] = (bool)$order['dilaporkan'];
    foreach (['batas_bayar', 'ambil_mulai', 'ambil_selesai', 'dibuat', 'selesai_ts'] as $timestamp) {
        $order[$timestamp] = $order[$timestamp] === null ? null : (int)$order[$timestamp];
    }
    $order['ulasan'] = $review;
    return $order;
}, $ordersStmt->fetchAll());

$RT = [
    'serverNow'  => $now,
    'pengaturan' => $pengaturan,
    'pembeli'    => $pembeli,
    'kategori'   => $kategori,
    'paket'      => $paket,
    'pesanan'    => $pesanan,
    'csrf'       => $csrf,
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard Pelanggan &ndash; ReTaste</title>
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
.brand-mark{width:54px;height:54px;padding:5px;border-radius:16px;flex-shrink:0;display:grid;place-items:center;background:rgba(255,255,255,.97);box-shadow:0 8px 18px rgba(0,0,0,.25);transition:transform .35s var(--ease)}
.brand:hover .brand-mark{transform:rotate(-6deg) scale(1.06)}
.brand-mark img{width:100%;height:100%;object-fit:contain}
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
.filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:12px;align-items:end;margin-bottom:18px}
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
.food-safety{padding:13px 15px;margin:12px 0;border-radius:12px;background:#F7F8E9;border:1px solid #DCE2B7}
.food-safety h3{font-size:.98rem;margin-bottom:8px}
.food-safety dl{display:grid;grid-template-columns: minmax(120px,.8fr) 1.4fr;gap:6px 12px;font-size:.82rem}
.food-safety dt{color:var(--muted);font-weight:800}
.food-safety dd{color:var(--text);font-weight:600}
.food-safety p{margin:9px 0 0;padding-top:8px;border-top:1px solid #DCE2B7;font-size:.78rem;color:var(--muted)}
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

.toasts{position:fixed;right:22px;bottom:22px;z-index:300;display:grid;gap:10px}
.toast{display:flex;align-items:center;gap:10px;min-width:250px;max-width:360px;padding:12px 18px;border-radius:12px;font-size:.88rem;font-weight:700;color:#fff;background:var(--dark);border-left:4px solid var(--amber);box-shadow:0 14px 34px rgba(29,37,9,.35);animation:tin .3s var(--ease)}
.toast.err{border-left-color:#FF8A73}
.toast.out{opacity:0;transform:translateX(30px);transition:.4s}
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
</style>
</head>
<body>
<div class="scrim" id="scrim"></div>
<div class="app">

  <!-- ===== SIDEBAR (logo di pojok kiri) ===== -->
  <aside class="sidebar" id="sidebar">
    <a href="index.php" class="brand" aria-label="ReTaste - ke halaman utama">
      <span class="brand-mark"><img src="assets/img/favicon.png" alt="Logo ReTaste"></span>
      <span><b>Re<span>Taste</span></b><small>Good Food. New Chances.</small></span>
    </a>
    <nav class="nav" id="nav" aria-label="Menu pelanggan"></nav>
    <div class="side-foot">
      <a href="logout.php" class="logout" onclick="return confirm('Yakin ingin keluar?')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg> Keluar dari akun</a>
      <div class="side-ver">ReTaste &middot; Pelanggan<br>&copy; <?= $tahun ?> ReTaste</div>
    </div>
  </aside>

  <!-- ===== MAIN ===== -->
  <div class="main">
    <header class="topbar">
      <div class="tb-l">
        <button class="burger" id="burger" aria-label="Buka menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
        <div><div class="tb-title" id="tbT">Dashboard</div><div class="tb-sub" id="tbS"></div></div>
      </div>
      <div class="tb-r">
        <div class="clk" aria-label="Waktu sekarang"><span class="clk-ic" data-i="clock"></span><div><b id="clkT">--:--:--</b><small id="clkD">-- --- ----</small></div></div>
        <div class="chip-user"><div class="av"><?= htmlspecialchars($inisial) ?></div><div><b><?= htmlspecialchars($nama) ?></b><small>Pembeli</small></div></div>
      </div>
    </header>

    <main class="content" id="content">
      <section class="page active" id="page-dashboard"></section>
      <section class="page" id="page-cari"></section>
      <section class="page" id="page-pesanan"></section>
      <section class="page" id="page-riwayat"></section>
      <section class="page" id="page-profil"></section>
    </main>
  </div>
</div>

<div class="modal" id="modal" role="dialog" aria-modal="true"><div class="mbox" id="mbox"></div></div>
<div class="toasts" id="toasts" aria-live="polite"></div>

<script>window.RT = <?= json_encode($RT, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script>
//---------------------------------------------------------------------
//
// QR Code Generator for JavaScript
//
// Copyright (c) 2009 Kazuhiko Arase
//
// URL: http://www.d-project.com/
//
// Licensed under the MIT license:
//  http://www.opensource.org/licenses/mit-license.php
//
// The word 'QR Code' is registered trademark of
// DENSO WAVE INCORPORATED
//  http://www.denso-wave.com/qrcode/faqpatent-e.html
//
//---------------------------------------------------------------------

var qrcode = function() {

  //---------------------------------------------------------------------
  // qrcode
  //---------------------------------------------------------------------

  /**
   * qrcode
   * @param typeNumber 1 to 40
   * @param errorCorrectionLevel 'L','M','Q','H'
   */
  var qrcode = function(typeNumber, errorCorrectionLevel) {

    var PAD0 = 0xEC;
    var PAD1 = 0x11;

    var _typeNumber = typeNumber;
    var _errorCorrectionLevel = QRErrorCorrectionLevel[errorCorrectionLevel];
    var _modules = null;
    var _moduleCount = 0;
    var _dataCache = null;
    var _dataList = [];

    var _this = {};

    var makeImpl = function(test, maskPattern) {

      _moduleCount = _typeNumber * 4 + 17;
      _modules = function(moduleCount) {
        var modules = new Array(moduleCount);
        for (var row = 0; row < moduleCount; row += 1) {
          modules[row] = new Array(moduleCount);
          for (var col = 0; col < moduleCount; col += 1) {
            modules[row][col] = null;
          }
        }
        return modules;
      }(_moduleCount);

      setupPositionProbePattern(0, 0);
      setupPositionProbePattern(_moduleCount - 7, 0);
      setupPositionProbePattern(0, _moduleCount - 7);
      setupPositionAdjustPattern();
      setupTimingPattern();
      setupTypeInfo(test, maskPattern);

      if (_typeNumber >= 7) {
        setupTypeNumber(test);
      }

      if (_dataCache == null) {
        _dataCache = createData(_typeNumber, _errorCorrectionLevel, _dataList);
      }

      mapData(_dataCache, maskPattern);
    };

    var setupPositionProbePattern = function(row, col) {

      for (var r = -1; r <= 7; r += 1) {

        if (row + r <= -1 || _moduleCount <= row + r) continue;

        for (var c = -1; c <= 7; c += 1) {

          if (col + c <= -1 || _moduleCount <= col + c) continue;

          if ( (0 <= r && r <= 6 && (c == 0 || c == 6) )
              || (0 <= c && c <= 6 && (r == 0 || r == 6) )
              || (2 <= r && r <= 4 && 2 <= c && c <= 4) ) {
            _modules[row + r][col + c] = true;
          } else {
            _modules[row + r][col + c] = false;
          }
        }
      }
    };

    var getBestMaskPattern = function() {

      var minLostPoint = 0;
      var pattern = 0;

      for (var i = 0; i < 8; i += 1) {

        makeImpl(true, i);

        var lostPoint = QRUtil.getLostPoint(_this);

        if (i == 0 || minLostPoint > lostPoint) {
          minLostPoint = lostPoint;
          pattern = i;
        }
      }

      return pattern;
    };

    var setupTimingPattern = function() {

      for (var r = 8; r < _moduleCount - 8; r += 1) {
        if (_modules[r][6] != null) {
          continue;
        }
        _modules[r][6] = (r % 2 == 0);
      }

      for (var c = 8; c < _moduleCount - 8; c += 1) {
        if (_modules[6][c] != null) {
          continue;
        }
        _modules[6][c] = (c % 2 == 0);
      }
    };

    var setupPositionAdjustPattern = function() {

      var pos = QRUtil.getPatternPosition(_typeNumber);

      for (var i = 0; i < pos.length; i += 1) {

        for (var j = 0; j < pos.length; j += 1) {

          var row = pos[i];
          var col = pos[j];

          if (_modules[row][col] != null) {
            continue;
          }

          for (var r = -2; r <= 2; r += 1) {

            for (var c = -2; c <= 2; c += 1) {

              if (r == -2 || r == 2 || c == -2 || c == 2
                  || (r == 0 && c == 0) ) {
                _modules[row + r][col + c] = true;
              } else {
                _modules[row + r][col + c] = false;
              }
            }
          }
        }
      }
    };

    var setupTypeNumber = function(test) {

      var bits = QRUtil.getBCHTypeNumber(_typeNumber);

      for (var i = 0; i < 18; i += 1) {
        var mod = (!test && ( (bits >> i) & 1) == 1);
        _modules[Math.floor(i / 3)][i % 3 + _moduleCount - 8 - 3] = mod;
      }

      for (var i = 0; i < 18; i += 1) {
        var mod = (!test && ( (bits >> i) & 1) == 1);
        _modules[i % 3 + _moduleCount - 8 - 3][Math.floor(i / 3)] = mod;
      }
    };

    var setupTypeInfo = function(test, maskPattern) {

      var data = (_errorCorrectionLevel << 3) | maskPattern;
      var bits = QRUtil.getBCHTypeInfo(data);

      // vertical
      for (var i = 0; i < 15; i += 1) {

        var mod = (!test && ( (bits >> i) & 1) == 1);

        if (i < 6) {
          _modules[i][8] = mod;
        } else if (i < 8) {
          _modules[i + 1][8] = mod;
        } else {
          _modules[_moduleCount - 15 + i][8] = mod;
        }
      }

      // horizontal
      for (var i = 0; i < 15; i += 1) {

        var mod = (!test && ( (bits >> i) & 1) == 1);

        if (i < 8) {
          _modules[8][_moduleCount - i - 1] = mod;
        } else if (i < 9) {
          _modules[8][15 - i - 1 + 1] = mod;
        } else {
          _modules[8][15 - i - 1] = mod;
        }
      }

      // fixed module
      _modules[_moduleCount - 8][8] = (!test);
    };

    var mapData = function(data, maskPattern) {

      var inc = -1;
      var row = _moduleCount - 1;
      var bitIndex = 7;
      var byteIndex = 0;
      var maskFunc = QRUtil.getMaskFunction(maskPattern);

      for (var col = _moduleCount - 1; col > 0; col -= 2) {

        if (col == 6) col -= 1;

        while (true) {

          for (var c = 0; c < 2; c += 1) {

            if (_modules[row][col - c] == null) {

              var dark = false;

              if (byteIndex < data.length) {
                dark = ( ( (data[byteIndex] >>> bitIndex) & 1) == 1);
              }

              var mask = maskFunc(row, col - c);

              if (mask) {
                dark = !dark;
              }

              _modules[row][col - c] = dark;
              bitIndex -= 1;

              if (bitIndex == -1) {
                byteIndex += 1;
                bitIndex = 7;
              }
            }
          }

          row += inc;

          if (row < 0 || _moduleCount <= row) {
            row -= inc;
            inc = -inc;
            break;
          }
        }
      }
    };

    var createBytes = function(buffer, rsBlocks) {

      var offset = 0;

      var maxDcCount = 0;
      var maxEcCount = 0;

      var dcdata = new Array(rsBlocks.length);
      var ecdata = new Array(rsBlocks.length);

      for (var r = 0; r < rsBlocks.length; r += 1) {

        var dcCount = rsBlocks[r].dataCount;
        var ecCount = rsBlocks[r].totalCount - dcCount;

        maxDcCount = Math.max(maxDcCount, dcCount);
        maxEcCount = Math.max(maxEcCount, ecCount);

        dcdata[r] = new Array(dcCount);

        for (var i = 0; i < dcdata[r].length; i += 1) {
          dcdata[r][i] = 0xff & buffer.getBuffer()[i + offset];
        }
        offset += dcCount;

        var rsPoly = QRUtil.getErrorCorrectPolynomial(ecCount);
        var rawPoly = qrPolynomial(dcdata[r], rsPoly.getLength() - 1);

        var modPoly = rawPoly.mod(rsPoly);
        ecdata[r] = new Array(rsPoly.getLength() - 1);
        for (var i = 0; i < ecdata[r].length; i += 1) {
          var modIndex = i + modPoly.getLength() - ecdata[r].length;
          ecdata[r][i] = (modIndex >= 0)? modPoly.getAt(modIndex) : 0;
        }
      }

      var totalCodeCount = 0;
      for (var i = 0; i < rsBlocks.length; i += 1) {
        totalCodeCount += rsBlocks[i].totalCount;
      }

      var data = new Array(totalCodeCount);
      var index = 0;

      for (var i = 0; i < maxDcCount; i += 1) {
        for (var r = 0; r < rsBlocks.length; r += 1) {
          if (i < dcdata[r].length) {
            data[index] = dcdata[r][i];
            index += 1;
          }
        }
      }

      for (var i = 0; i < maxEcCount; i += 1) {
        for (var r = 0; r < rsBlocks.length; r += 1) {
          if (i < ecdata[r].length) {
            data[index] = ecdata[r][i];
            index += 1;
          }
        }
      }

      return data;
    };

    var createData = function(typeNumber, errorCorrectionLevel, dataList) {

      var rsBlocks = QRRSBlock.getRSBlocks(typeNumber, errorCorrectionLevel);

      var buffer = qrBitBuffer();

      for (var i = 0; i < dataList.length; i += 1) {
        var data = dataList[i];
        buffer.put(data.getMode(), 4);
        buffer.put(data.getLength(), QRUtil.getLengthInBits(data.getMode(), typeNumber) );
        data.write(buffer);
      }

      // calc num max data.
      var totalDataCount = 0;
      for (var i = 0; i < rsBlocks.length; i += 1) {
        totalDataCount += rsBlocks[i].dataCount;
      }

      if (buffer.getLengthInBits() > totalDataCount * 8) {
        throw 'code length overflow. ('
          + buffer.getLengthInBits()
          + '>'
          + totalDataCount * 8
          + ')';
      }

      // end code
      if (buffer.getLengthInBits() + 4 <= totalDataCount * 8) {
        buffer.put(0, 4);
      }

      // padding
      while (buffer.getLengthInBits() % 8 != 0) {
        buffer.putBit(false);
      }

      // padding
      while (true) {

        if (buffer.getLengthInBits() >= totalDataCount * 8) {
          break;
        }
        buffer.put(PAD0, 8);

        if (buffer.getLengthInBits() >= totalDataCount * 8) {
          break;
        }
        buffer.put(PAD1, 8);
      }

      return createBytes(buffer, rsBlocks);
    };

    _this.addData = function(data, mode) {

      mode = mode || 'Byte';

      var newData = null;

      switch(mode) {
      case 'Numeric' :
        newData = qrNumber(data);
        break;
      case 'Alphanumeric' :
        newData = qrAlphaNum(data);
        break;
      case 'Byte' :
        newData = qr8BitByte(data);
        break;
      case 'Kanji' :
        newData = qrKanji(data);
        break;
      default :
        throw 'mode:' + mode;
      }

      _dataList.push(newData);
      _dataCache = null;
    };

    _this.isDark = function(row, col) {
      if (row < 0 || _moduleCount <= row || col < 0 || _moduleCount <= col) {
        throw row + ',' + col;
      }
      return _modules[row][col];
    };

    _this.getModuleCount = function() {
      return _moduleCount;
    };

    _this.make = function() {
      if (_typeNumber < 1) {
        var typeNumber = 1;

        for (; typeNumber < 40; typeNumber++) {
          var rsBlocks = QRRSBlock.getRSBlocks(typeNumber, _errorCorrectionLevel);
          var buffer = qrBitBuffer();

          for (var i = 0; i < _dataList.length; i++) {
            var data = _dataList[i];
            buffer.put(data.getMode(), 4);
            buffer.put(data.getLength(), QRUtil.getLengthInBits(data.getMode(), typeNumber) );
            data.write(buffer);
          }

          var totalDataCount = 0;
          for (var i = 0; i < rsBlocks.length; i++) {
            totalDataCount += rsBlocks[i].dataCount;
          }

          if (buffer.getLengthInBits() <= totalDataCount * 8) {
            break;
          }
        }

        _typeNumber = typeNumber;
      }

      makeImpl(false, getBestMaskPattern() );
    };

    _this.createTableTag = function(cellSize, margin) {

      cellSize = cellSize || 2;
      margin = (typeof margin == 'undefined')? cellSize * 4 : margin;

      var qrHtml = '';

      qrHtml += '<table style="';
      qrHtml += ' border-width: 0px; border-style: none;';
      qrHtml += ' border-collapse: collapse;';
      qrHtml += ' padding: 0px; margin: ' + margin + 'px;';
      qrHtml += '">';
      qrHtml += '<tbody>';

      for (var r = 0; r < _this.getModuleCount(); r += 1) {

        qrHtml += '<tr>';

        for (var c = 0; c < _this.getModuleCount(); c += 1) {
          qrHtml += '<td style="';
          qrHtml += ' border-width: 0px; border-style: none;';
          qrHtml += ' border-collapse: collapse;';
          qrHtml += ' padding: 0px; margin: 0px;';
          qrHtml += ' width: ' + cellSize + 'px;';
          qrHtml += ' height: ' + cellSize + 'px;';
          qrHtml += ' background-color: ';
          qrHtml += _this.isDark(r, c)? '#000000' : '#ffffff';
          qrHtml += ';';
          qrHtml += '"/>';
        }

        qrHtml += '</tr>';
      }

      qrHtml += '</tbody>';
      qrHtml += '</table>';

      return qrHtml;
    };

    _this.createSvgTag = function(cellSize, margin, alt, title) {

      var opts = {};
      if (typeof arguments[0] == 'object') {
        // Called by options.
        opts = arguments[0];
        // overwrite cellSize and margin.
        cellSize = opts.cellSize;
        margin = opts.margin;
        alt = opts.alt;
        title = opts.title;
      }

      cellSize = cellSize || 2;
      margin = (typeof margin == 'undefined')? cellSize * 4 : margin;

      // Compose alt property surrogate
      alt = (typeof alt === 'string') ? {text: alt} : alt || {};
      alt.text = alt.text || null;
      alt.id = (alt.text) ? alt.id || 'qrcode-description' : null;

      // Compose title property surrogate
      title = (typeof title === 'string') ? {text: title} : title || {};
      title.text = title.text || null;
      title.id = (title.text) ? title.id || 'qrcode-title' : null;

      var size = _this.getModuleCount() * cellSize + margin * 2;
      var c, mc, r, mr, qrSvg='', rect;

      rect = 'l' + cellSize + ',0 0,' + cellSize +
        ' -' + cellSize + ',0 0,-' + cellSize + 'z ';

      qrSvg += '<svg version="1.1" xmlns="http://www.w3.org/2000/svg"';
      qrSvg += !opts.scalable ? ' width="' + size + 'px" height="' + size + 'px"' : '';
      qrSvg += ' viewBox="0 0 ' + size + ' ' + size + '" ';
      qrSvg += ' preserveAspectRatio="xMinYMin meet"';
      qrSvg += (title.text || alt.text) ? ' role="img" aria-labelledby="' +
          escapeXml([title.id, alt.id].join(' ').trim() ) + '"' : '';
      qrSvg += '>';
      qrSvg += (title.text) ? '<title id="' + escapeXml(title.id) + '">' +
          escapeXml(title.text) + '</title>' : '';
      qrSvg += (alt.text) ? '<description id="' + escapeXml(alt.id) + '">' +
          escapeXml(alt.text) + '</description>' : '';
      qrSvg += '<rect width="100%" height="100%" fill="white" cx="0" cy="0"/>';
      qrSvg += '<path d="';

      for (r = 0; r < _this.getModuleCount(); r += 1) {
        mr = r * cellSize + margin;
        for (c = 0; c < _this.getModuleCount(); c += 1) {
          if (_this.isDark(r, c) ) {
            mc = c*cellSize+margin;
            qrSvg += 'M' + mc + ',' + mr + rect;
          }
        }
      }

      qrSvg += '" stroke="transparent" fill="black"/>';
      qrSvg += '</svg>';

      return qrSvg;
    };

    _this.createDataURL = function(cellSize, margin) {

      cellSize = cellSize || 2;
      margin = (typeof margin == 'undefined')? cellSize * 4 : margin;

      var size = _this.getModuleCount() * cellSize + margin * 2;
      var min = margin;
      var max = size - margin;

      return createDataURL(size, size, function(x, y) {
        if (min <= x && x < max && min <= y && y < max) {
          var c = Math.floor( (x - min) / cellSize);
          var r = Math.floor( (y - min) / cellSize);
          return _this.isDark(r, c)? 0 : 1;
        } else {
          return 1;
        }
      } );
    };

    _this.createImgTag = function(cellSize, margin, alt) {

      cellSize = cellSize || 2;
      margin = (typeof margin == 'undefined')? cellSize * 4 : margin;

      var size = _this.getModuleCount() * cellSize + margin * 2;

      var img = '';
      img += '<img';
      img += '\u0020src="';
      img += _this.createDataURL(cellSize, margin);
      img += '"';
      img += '\u0020width="';
      img += size;
      img += '"';
      img += '\u0020height="';
      img += size;
      img += '"';
      if (alt) {
        img += '\u0020alt="';
        img += escapeXml(alt);
        img += '"';
      }
      img += '/>';

      return img;
    };

    var escapeXml = function(s) {
      var escaped = '';
      for (var i = 0; i < s.length; i += 1) {
        var c = s.charAt(i);
        switch(c) {
        case '<': escaped += '&lt;'; break;
        case '>': escaped += '&gt;'; break;
        case '&': escaped += '&amp;'; break;
        case '"': escaped += '&quot;'; break;
        default : escaped += c; break;
        }
      }
      return escaped;
    };

    var _createHalfASCII = function(margin) {
      var cellSize = 1;
      margin = (typeof margin == 'undefined')? cellSize * 2 : margin;

      var size = _this.getModuleCount() * cellSize + margin * 2;
      var min = margin;
      var max = size - margin;

      var y, x, r1, r2, p;

      var blocks = {
        '██': '█',
        '█ ': '▀',
        ' █': '▄',
        '  ': ' '
      };

      var blocksLastLineNoMargin = {
        '██': '▀',
        '█ ': '▀',
        ' █': ' ',
        '  ': ' '
      };

      var ascii = '';
      for (y = 0; y < size; y += 2) {
        r1 = Math.floor((y - min) / cellSize);
        r2 = Math.floor((y + 1 - min) / cellSize);
        for (x = 0; x < size; x += 1) {
          p = '█';

          if (min <= x && x < max && min <= y && y < max && _this.isDark(r1, Math.floor((x - min) / cellSize))) {
            p = ' ';
          }

          if (min <= x && x < max && min <= y+1 && y+1 < max && _this.isDark(r2, Math.floor((x - min) / cellSize))) {
            p += ' ';
          }
          else {
            p += '█';
          }

          // Output 2 characters per pixel, to create full square. 1 character per pixels gives only half width of square.
          ascii += (margin < 1 && y+1 >= max) ? blocksLastLineNoMargin[p] : blocks[p];
        }

        ascii += '\n';
      }

      if (size % 2 && margin > 0) {
        return ascii.substring(0, ascii.length - size - 1) + Array(size+1).join('▀');
      }

      return ascii.substring(0, ascii.length-1);
    };

    _this.createASCII = function(cellSize, margin) {
      cellSize = cellSize || 1;

      if (cellSize < 2) {
        return _createHalfASCII(margin);
      }

      cellSize -= 1;
      margin = (typeof margin == 'undefined')? cellSize * 2 : margin;

      var size = _this.getModuleCount() * cellSize + margin * 2;
      var min = margin;
      var max = size - margin;

      var y, x, r, p;

      var white = Array(cellSize+1).join('██');
      var black = Array(cellSize+1).join('  ');

      var ascii = '';
      var line = '';
      for (y = 0; y < size; y += 1) {
        r = Math.floor( (y - min) / cellSize);
        line = '';
        for (x = 0; x < size; x += 1) {
          p = 1;

          if (min <= x && x < max && min <= y && y < max && _this.isDark(r, Math.floor((x - min) / cellSize))) {
            p = 0;
          }

          // Output 2 characters per pixel, to create full square. 1 character per pixels gives only half width of square.
          line += p ? white : black;
        }

        for (r = 0; r < cellSize; r += 1) {
          ascii += line + '\n';
        }
      }

      return ascii.substring(0, ascii.length-1);
    };

    _this.renderTo2dContext = function(context, cellSize) {
      cellSize = cellSize || 2;
      var length = _this.getModuleCount();
      for (var row = 0; row < length; row++) {
        for (var col = 0; col < length; col++) {
          context.fillStyle = _this.isDark(row, col) ? 'black' : 'white';
          context.fillRect(col * cellSize, row * cellSize, cellSize, cellSize);
        }
      }
    }

    return _this;
  };

  //---------------------------------------------------------------------
  // qrcode.stringToBytes
  //---------------------------------------------------------------------

  qrcode.stringToBytesFuncs = {
    'default' : function(s) {
      var bytes = [];
      for (var i = 0; i < s.length; i += 1) {
        var c = s.charCodeAt(i);
        bytes.push(c & 0xff);
      }
      return bytes;
    }
  };

  qrcode.stringToBytes = qrcode.stringToBytesFuncs['default'];

  //---------------------------------------------------------------------
  // qrcode.createStringToBytes
  //---------------------------------------------------------------------

  /**
   * @param unicodeData base64 string of byte array.
   * [16bit Unicode],[16bit Bytes], ...
   * @param numChars
   */
  qrcode.createStringToBytes = function(unicodeData, numChars) {

    // create conversion map.

    var unicodeMap = function() {

      var bin = base64DecodeInputStream(unicodeData);
      var read = function() {
        var b = bin.read();
        if (b == -1) throw 'eof';
        return b;
      };

      var count = 0;
      var unicodeMap = {};
      while (true) {
        var b0 = bin.read();
        if (b0 == -1) break;
        var b1 = read();
        var b2 = read();
        var b3 = read();
        var k = String.fromCharCode( (b0 << 8) | b1);
        var v = (b2 << 8) | b3;
        unicodeMap[k] = v;
        count += 1;
      }
      if (count != numChars) {
        throw count + ' != ' + numChars;
      }

      return unicodeMap;
    }();

    var unknownChar = '?'.charCodeAt(0);

    return function(s) {
      var bytes = [];
      for (var i = 0; i < s.length; i += 1) {
        var c = s.charCodeAt(i);
        if (c < 128) {
          bytes.push(c);
        } else {
          var b = unicodeMap[s.charAt(i)];
          if (typeof b == 'number') {
            if ( (b & 0xff) == b) {
              // 1byte
              bytes.push(b);
            } else {
              // 2bytes
              bytes.push(b >>> 8);
              bytes.push(b & 0xff);
            }
          } else {
            bytes.push(unknownChar);
          }
        }
      }
      return bytes;
    };
  };

  //---------------------------------------------------------------------
  // QRMode
  //---------------------------------------------------------------------

  var QRMode = {
    MODE_NUMBER :    1 << 0,
    MODE_ALPHA_NUM : 1 << 1,
    MODE_8BIT_BYTE : 1 << 2,
    MODE_KANJI :     1 << 3
  };

  //---------------------------------------------------------------------
  // QRErrorCorrectionLevel
  //---------------------------------------------------------------------

  var QRErrorCorrectionLevel = {
    L : 1,
    M : 0,
    Q : 3,
    H : 2
  };

  //---------------------------------------------------------------------
  // QRMaskPattern
  //---------------------------------------------------------------------

  var QRMaskPattern = {
    PATTERN000 : 0,
    PATTERN001 : 1,
    PATTERN010 : 2,
    PATTERN011 : 3,
    PATTERN100 : 4,
    PATTERN101 : 5,
    PATTERN110 : 6,
    PATTERN111 : 7
  };

  //---------------------------------------------------------------------
  // QRUtil
  //---------------------------------------------------------------------

  var QRUtil = function() {

    var PATTERN_POSITION_TABLE = [
      [],
      [6, 18],
      [6, 22],
      [6, 26],
      [6, 30],
      [6, 34],
      [6, 22, 38],
      [6, 24, 42],
      [6, 26, 46],
      [6, 28, 50],
      [6, 30, 54],
      [6, 32, 58],
      [6, 34, 62],
      [6, 26, 46, 66],
      [6, 26, 48, 70],
      [6, 26, 50, 74],
      [6, 30, 54, 78],
      [6, 30, 56, 82],
      [6, 30, 58, 86],
      [6, 34, 62, 90],
      [6, 28, 50, 72, 94],
      [6, 26, 50, 74, 98],
      [6, 30, 54, 78, 102],
      [6, 28, 54, 80, 106],
      [6, 32, 58, 84, 110],
      [6, 30, 58, 86, 114],
      [6, 34, 62, 90, 118],
      [6, 26, 50, 74, 98, 122],
      [6, 30, 54, 78, 102, 126],
      [6, 26, 52, 78, 104, 130],
      [6, 30, 56, 82, 108, 134],
      [6, 34, 60, 86, 112, 138],
      [6, 30, 58, 86, 114, 142],
      [6, 34, 62, 90, 118, 146],
      [6, 30, 54, 78, 102, 126, 150],
      [6, 24, 50, 76, 102, 128, 154],
      [6, 28, 54, 80, 106, 132, 158],
      [6, 32, 58, 84, 110, 136, 162],
      [6, 26, 54, 82, 110, 138, 166],
      [6, 30, 58, 86, 114, 142, 170]
    ];
    var G15 = (1 << 10) | (1 << 8) | (1 << 5) | (1 << 4) | (1 << 2) | (1 << 1) | (1 << 0);
    var G18 = (1 << 12) | (1 << 11) | (1 << 10) | (1 << 9) | (1 << 8) | (1 << 5) | (1 << 2) | (1 << 0);
    var G15_MASK = (1 << 14) | (1 << 12) | (1 << 10) | (1 << 4) | (1 << 1);

    var _this = {};

    var getBCHDigit = function(data) {
      var digit = 0;
      while (data != 0) {
        digit += 1;
        data >>>= 1;
      }
      return digit;
    };

    _this.getBCHTypeInfo = function(data) {
      var d = data << 10;
      while (getBCHDigit(d) - getBCHDigit(G15) >= 0) {
        d ^= (G15 << (getBCHDigit(d) - getBCHDigit(G15) ) );
      }
      return ( (data << 10) | d) ^ G15_MASK;
    };

    _this.getBCHTypeNumber = function(data) {
      var d = data << 12;
      while (getBCHDigit(d) - getBCHDigit(G18) >= 0) {
        d ^= (G18 << (getBCHDigit(d) - getBCHDigit(G18) ) );
      }
      return (data << 12) | d;
    };

    _this.getPatternPosition = function(typeNumber) {
      return PATTERN_POSITION_TABLE[typeNumber - 1];
    };

    _this.getMaskFunction = function(maskPattern) {

      switch (maskPattern) {

      case QRMaskPattern.PATTERN000 :
        return function(i, j) { return (i + j) % 2 == 0; };
      case QRMaskPattern.PATTERN001 :
        return function(i, j) { return i % 2 == 0; };
      case QRMaskPattern.PATTERN010 :
        return function(i, j) { return j % 3 == 0; };
      case QRMaskPattern.PATTERN011 :
        return function(i, j) { return (i + j) % 3 == 0; };
      case QRMaskPattern.PATTERN100 :
        return function(i, j) { return (Math.floor(i / 2) + Math.floor(j / 3) ) % 2 == 0; };
      case QRMaskPattern.PATTERN101 :
        return function(i, j) { return (i * j) % 2 + (i * j) % 3 == 0; };
      case QRMaskPattern.PATTERN110 :
        return function(i, j) { return ( (i * j) % 2 + (i * j) % 3) % 2 == 0; };
      case QRMaskPattern.PATTERN111 :
        return function(i, j) { return ( (i * j) % 3 + (i + j) % 2) % 2 == 0; };

      default :
        throw 'bad maskPattern:' + maskPattern;
      }
    };

    _this.getErrorCorrectPolynomial = function(errorCorrectLength) {
      var a = qrPolynomial([1], 0);
      for (var i = 0; i < errorCorrectLength; i += 1) {
        a = a.multiply(qrPolynomial([1, QRMath.gexp(i)], 0) );
      }
      return a;
    };

    _this.getLengthInBits = function(mode, type) {

      if (1 <= type && type < 10) {

        // 1 - 9

        switch(mode) {
        case QRMode.MODE_NUMBER    : return 10;
        case QRMode.MODE_ALPHA_NUM : return 9;
        case QRMode.MODE_8BIT_BYTE : return 8;
        case QRMode.MODE_KANJI     : return 8;
        default :
          throw 'mode:' + mode;
        }

      } else if (type < 27) {

        // 10 - 26

        switch(mode) {
        case QRMode.MODE_NUMBER    : return 12;
        case QRMode.MODE_ALPHA_NUM : return 11;
        case QRMode.MODE_8BIT_BYTE : return 16;
        case QRMode.MODE_KANJI     : return 10;
        default :
          throw 'mode:' + mode;
        }

      } else if (type < 41) {

        // 27 - 40

        switch(mode) {
        case QRMode.MODE_NUMBER    : return 14;
        case QRMode.MODE_ALPHA_NUM : return 13;
        case QRMode.MODE_8BIT_BYTE : return 16;
        case QRMode.MODE_KANJI     : return 12;
        default :
          throw 'mode:' + mode;
        }

      } else {
        throw 'type:' + type;
      }
    };

    _this.getLostPoint = function(qrcode) {

      var moduleCount = qrcode.getModuleCount();

      var lostPoint = 0;

      // LEVEL1

      for (var row = 0; row < moduleCount; row += 1) {
        for (var col = 0; col < moduleCount; col += 1) {

          var sameCount = 0;
          var dark = qrcode.isDark(row, col);

          for (var r = -1; r <= 1; r += 1) {

            if (row + r < 0 || moduleCount <= row + r) {
              continue;
            }

            for (var c = -1; c <= 1; c += 1) {

              if (col + c < 0 || moduleCount <= col + c) {
                continue;
              }

              if (r == 0 && c == 0) {
                continue;
              }

              if (dark == qrcode.isDark(row + r, col + c) ) {
                sameCount += 1;
              }
            }
          }

          if (sameCount > 5) {
            lostPoint += (3 + sameCount - 5);
          }
        }
      };

      // LEVEL2

      for (var row = 0; row < moduleCount - 1; row += 1) {
        for (var col = 0; col < moduleCount - 1; col += 1) {
          var count = 0;
          if (qrcode.isDark(row, col) ) count += 1;
          if (qrcode.isDark(row + 1, col) ) count += 1;
          if (qrcode.isDark(row, col + 1) ) count += 1;
          if (qrcode.isDark(row + 1, col + 1) ) count += 1;
          if (count == 0 || count == 4) {
            lostPoint += 3;
          }
        }
      }

      // LEVEL3

      for (var row = 0; row < moduleCount; row += 1) {
        for (var col = 0; col < moduleCount - 6; col += 1) {
          if (qrcode.isDark(row, col)
              && !qrcode.isDark(row, col + 1)
              &&  qrcode.isDark(row, col + 2)
              &&  qrcode.isDark(row, col + 3)
              &&  qrcode.isDark(row, col + 4)
              && !qrcode.isDark(row, col + 5)
              &&  qrcode.isDark(row, col + 6) ) {
            lostPoint += 40;
          }
        }
      }

      for (var col = 0; col < moduleCount; col += 1) {
        for (var row = 0; row < moduleCount - 6; row += 1) {
          if (qrcode.isDark(row, col)
              && !qrcode.isDark(row + 1, col)
              &&  qrcode.isDark(row + 2, col)
              &&  qrcode.isDark(row + 3, col)
              &&  qrcode.isDark(row + 4, col)
              && !qrcode.isDark(row + 5, col)
              &&  qrcode.isDark(row + 6, col) ) {
            lostPoint += 40;
          }
        }
      }

      // LEVEL4

      var darkCount = 0;

      for (var col = 0; col < moduleCount; col += 1) {
        for (var row = 0; row < moduleCount; row += 1) {
          if (qrcode.isDark(row, col) ) {
            darkCount += 1;
          }
        }
      }

      var ratio = Math.abs(100 * darkCount / moduleCount / moduleCount - 50) / 5;
      lostPoint += ratio * 10;

      return lostPoint;
    };

    return _this;
  }();

  //---------------------------------------------------------------------
  // QRMath
  //---------------------------------------------------------------------

  var QRMath = function() {

    var EXP_TABLE = new Array(256);
    var LOG_TABLE = new Array(256);

    // initialize tables
    for (var i = 0; i < 8; i += 1) {
      EXP_TABLE[i] = 1 << i;
    }
    for (var i = 8; i < 256; i += 1) {
      EXP_TABLE[i] = EXP_TABLE[i - 4]
        ^ EXP_TABLE[i - 5]
        ^ EXP_TABLE[i - 6]
        ^ EXP_TABLE[i - 8];
    }
    for (var i = 0; i < 255; i += 1) {
      LOG_TABLE[EXP_TABLE[i] ] = i;
    }

    var _this = {};

    _this.glog = function(n) {

      if (n < 1) {
        throw 'glog(' + n + ')';
      }

      return LOG_TABLE[n];
    };

    _this.gexp = function(n) {

      while (n < 0) {
        n += 255;
      }

      while (n >= 256) {
        n -= 255;
      }

      return EXP_TABLE[n];
    };

    return _this;
  }();

  //---------------------------------------------------------------------
  // qrPolynomial
  //---------------------------------------------------------------------

  function qrPolynomial(num, shift) {

    if (typeof num.length == 'undefined') {
      throw num.length + '/' + shift;
    }

    var _num = function() {
      var offset = 0;
      while (offset < num.length && num[offset] == 0) {
        offset += 1;
      }
      var _num = new Array(num.length - offset + shift);
      for (var i = 0; i < num.length - offset; i += 1) {
        _num[i] = num[i + offset];
      }
      return _num;
    }();

    var _this = {};

    _this.getAt = function(index) {
      return _num[index];
    };

    _this.getLength = function() {
      return _num.length;
    };

    _this.multiply = function(e) {

      var num = new Array(_this.getLength() + e.getLength() - 1);

      for (var i = 0; i < _this.getLength(); i += 1) {
        for (var j = 0; j < e.getLength(); j += 1) {
          num[i + j] ^= QRMath.gexp(QRMath.glog(_this.getAt(i) ) + QRMath.glog(e.getAt(j) ) );
        }
      }

      return qrPolynomial(num, 0);
    };

    _this.mod = function(e) {

      if (_this.getLength() - e.getLength() < 0) {
        return _this;
      }

      var ratio = QRMath.glog(_this.getAt(0) ) - QRMath.glog(e.getAt(0) );

      var num = new Array(_this.getLength() );
      for (var i = 0; i < _this.getLength(); i += 1) {
        num[i] = _this.getAt(i);
      }

      for (var i = 0; i < e.getLength(); i += 1) {
        num[i] ^= QRMath.gexp(QRMath.glog(e.getAt(i) ) + ratio);
      }

      // recursive call
      return qrPolynomial(num, 0).mod(e);
    };

    return _this;
  };

  //---------------------------------------------------------------------
  // QRRSBlock
  //---------------------------------------------------------------------

  var QRRSBlock = function() {

    var RS_BLOCK_TABLE = [

      // L
      // M
      // Q
      // H

      // 1
      [1, 26, 19],
      [1, 26, 16],
      [1, 26, 13],
      [1, 26, 9],

      // 2
      [1, 44, 34],
      [1, 44, 28],
      [1, 44, 22],
      [1, 44, 16],

      // 3
      [1, 70, 55],
      [1, 70, 44],
      [2, 35, 17],
      [2, 35, 13],

      // 4
      [1, 100, 80],
      [2, 50, 32],
      [2, 50, 24],
      [4, 25, 9],

      // 5
      [1, 134, 108],
      [2, 67, 43],
      [2, 33, 15, 2, 34, 16],
      [2, 33, 11, 2, 34, 12],

      // 6
      [2, 86, 68],
      [4, 43, 27],
      [4, 43, 19],
      [4, 43, 15],

      // 7
      [2, 98, 78],
      [4, 49, 31],
      [2, 32, 14, 4, 33, 15],
      [4, 39, 13, 1, 40, 14],

      // 8
      [2, 121, 97],
      [2, 60, 38, 2, 61, 39],
      [4, 40, 18, 2, 41, 19],
      [4, 40, 14, 2, 41, 15],

      // 9
      [2, 146, 116],
      [3, 58, 36, 2, 59, 37],
      [4, 36, 16, 4, 37, 17],
      [4, 36, 12, 4, 37, 13],

      // 10
      [2, 86, 68, 2, 87, 69],
      [4, 69, 43, 1, 70, 44],
      [6, 43, 19, 2, 44, 20],
      [6, 43, 15, 2, 44, 16],

      // 11
      [4, 101, 81],
      [1, 80, 50, 4, 81, 51],
      [4, 50, 22, 4, 51, 23],
      [3, 36, 12, 8, 37, 13],

      // 12
      [2, 116, 92, 2, 117, 93],
      [6, 58, 36, 2, 59, 37],
      [4, 46, 20, 6, 47, 21],
      [7, 42, 14, 4, 43, 15],

      // 13
      [4, 133, 107],
      [8, 59, 37, 1, 60, 38],
      [8, 44, 20, 4, 45, 21],
      [12, 33, 11, 4, 34, 12],

      // 14
      [3, 145, 115, 1, 146, 116],
      [4, 64, 40, 5, 65, 41],
      [11, 36, 16, 5, 37, 17],
      [11, 36, 12, 5, 37, 13],

      // 15
      [5, 109, 87, 1, 110, 88],
      [5, 65, 41, 5, 66, 42],
      [5, 54, 24, 7, 55, 25],
      [11, 36, 12, 7, 37, 13],

      // 16
      [5, 122, 98, 1, 123, 99],
      [7, 73, 45, 3, 74, 46],
      [15, 43, 19, 2, 44, 20],
      [3, 45, 15, 13, 46, 16],

      // 17
      [1, 135, 107, 5, 136, 108],
      [10, 74, 46, 1, 75, 47],
      [1, 50, 22, 15, 51, 23],
      [2, 42, 14, 17, 43, 15],

      // 18
      [5, 150, 120, 1, 151, 121],
      [9, 69, 43, 4, 70, 44],
      [17, 50, 22, 1, 51, 23],
      [2, 42, 14, 19, 43, 15],

      // 19
      [3, 141, 113, 4, 142, 114],
      [3, 70, 44, 11, 71, 45],
      [17, 47, 21, 4, 48, 22],
      [9, 39, 13, 16, 40, 14],

      // 20
      [3, 135, 107, 5, 136, 108],
      [3, 67, 41, 13, 68, 42],
      [15, 54, 24, 5, 55, 25],
      [15, 43, 15, 10, 44, 16],

      // 21
      [4, 144, 116, 4, 145, 117],
      [17, 68, 42],
      [17, 50, 22, 6, 51, 23],
      [19, 46, 16, 6, 47, 17],

      // 22
      [2, 139, 111, 7, 140, 112],
      [17, 74, 46],
      [7, 54, 24, 16, 55, 25],
      [34, 37, 13],

      // 23
      [4, 151, 121, 5, 152, 122],
      [4, 75, 47, 14, 76, 48],
      [11, 54, 24, 14, 55, 25],
      [16, 45, 15, 14, 46, 16],

      // 24
      [6, 147, 117, 4, 148, 118],
      [6, 73, 45, 14, 74, 46],
      [11, 54, 24, 16, 55, 25],
      [30, 46, 16, 2, 47, 17],

      // 25
      [8, 132, 106, 4, 133, 107],
      [8, 75, 47, 13, 76, 48],
      [7, 54, 24, 22, 55, 25],
      [22, 45, 15, 13, 46, 16],

      // 26
      [10, 142, 114, 2, 143, 115],
      [19, 74, 46, 4, 75, 47],
      [28, 50, 22, 6, 51, 23],
      [33, 46, 16, 4, 47, 17],

      // 27
      [8, 152, 122, 4, 153, 123],
      [22, 73, 45, 3, 74, 46],
      [8, 53, 23, 26, 54, 24],
      [12, 45, 15, 28, 46, 16],

      // 28
      [3, 147, 117, 10, 148, 118],
      [3, 73, 45, 23, 74, 46],
      [4, 54, 24, 31, 55, 25],
      [11, 45, 15, 31, 46, 16],

      // 29
      [7, 146, 116, 7, 147, 117],
      [21, 73, 45, 7, 74, 46],
      [1, 53, 23, 37, 54, 24],
      [19, 45, 15, 26, 46, 16],

      // 30
      [5, 145, 115, 10, 146, 116],
      [19, 75, 47, 10, 76, 48],
      [15, 54, 24, 25, 55, 25],
      [23, 45, 15, 25, 46, 16],

      // 31
      [13, 145, 115, 3, 146, 116],
      [2, 74, 46, 29, 75, 47],
      [42, 54, 24, 1, 55, 25],
      [23, 45, 15, 28, 46, 16],

      // 32
      [17, 145, 115],
      [10, 74, 46, 23, 75, 47],
      [10, 54, 24, 35, 55, 25],
      [19, 45, 15, 35, 46, 16],

      // 33
      [17, 145, 115, 1, 146, 116],
      [14, 74, 46, 21, 75, 47],
      [29, 54, 24, 19, 55, 25],
      [11, 45, 15, 46, 46, 16],

      // 34
      [13, 145, 115, 6, 146, 116],
      [14, 74, 46, 23, 75, 47],
      [44, 54, 24, 7, 55, 25],
      [59, 46, 16, 1, 47, 17],

      // 35
      [12, 151, 121, 7, 152, 122],
      [12, 75, 47, 26, 76, 48],
      [39, 54, 24, 14, 55, 25],
      [22, 45, 15, 41, 46, 16],

      // 36
      [6, 151, 121, 14, 152, 122],
      [6, 75, 47, 34, 76, 48],
      [46, 54, 24, 10, 55, 25],
      [2, 45, 15, 64, 46, 16],

      // 37
      [17, 152, 122, 4, 153, 123],
      [29, 74, 46, 14, 75, 47],
      [49, 54, 24, 10, 55, 25],
      [24, 45, 15, 46, 46, 16],

      // 38
      [4, 152, 122, 18, 153, 123],
      [13, 74, 46, 32, 75, 47],
      [48, 54, 24, 14, 55, 25],
      [42, 45, 15, 32, 46, 16],

      // 39
      [20, 147, 117, 4, 148, 118],
      [40, 75, 47, 7, 76, 48],
      [43, 54, 24, 22, 55, 25],
      [10, 45, 15, 67, 46, 16],

      // 40
      [19, 148, 118, 6, 149, 119],
      [18, 75, 47, 31, 76, 48],
      [34, 54, 24, 34, 55, 25],
      [20, 45, 15, 61, 46, 16]
    ];

    var qrRSBlock = function(totalCount, dataCount) {
      var _this = {};
      _this.totalCount = totalCount;
      _this.dataCount = dataCount;
      return _this;
    };

    var _this = {};

    var getRsBlockTable = function(typeNumber, errorCorrectionLevel) {

      switch(errorCorrectionLevel) {
      case QRErrorCorrectionLevel.L :
        return RS_BLOCK_TABLE[(typeNumber - 1) * 4 + 0];
      case QRErrorCorrectionLevel.M :
        return RS_BLOCK_TABLE[(typeNumber - 1) * 4 + 1];
      case QRErrorCorrectionLevel.Q :
        return RS_BLOCK_TABLE[(typeNumber - 1) * 4 + 2];
      case QRErrorCorrectionLevel.H :
        return RS_BLOCK_TABLE[(typeNumber - 1) * 4 + 3];
      default :
        return undefined;
      }
    };

    _this.getRSBlocks = function(typeNumber, errorCorrectionLevel) {

      var rsBlock = getRsBlockTable(typeNumber, errorCorrectionLevel);

      if (typeof rsBlock == 'undefined') {
        throw 'bad rs block @ typeNumber:' + typeNumber +
            '/errorCorrectionLevel:' + errorCorrectionLevel;
      }

      var length = rsBlock.length / 3;

      var list = [];

      for (var i = 0; i < length; i += 1) {

        var count = rsBlock[i * 3 + 0];
        var totalCount = rsBlock[i * 3 + 1];
        var dataCount = rsBlock[i * 3 + 2];

        for (var j = 0; j < count; j += 1) {
          list.push(qrRSBlock(totalCount, dataCount) );
        }
      }

      return list;
    };

    return _this;
  }();

  //---------------------------------------------------------------------
  // qrBitBuffer
  //---------------------------------------------------------------------

  var qrBitBuffer = function() {

    var _buffer = [];
    var _length = 0;

    var _this = {};

    _this.getBuffer = function() {
      return _buffer;
    };

    _this.getAt = function(index) {
      var bufIndex = Math.floor(index / 8);
      return ( (_buffer[bufIndex] >>> (7 - index % 8) ) & 1) == 1;
    };

    _this.put = function(num, length) {
      for (var i = 0; i < length; i += 1) {
        _this.putBit( ( (num >>> (length - i - 1) ) & 1) == 1);
      }
    };

    _this.getLengthInBits = function() {
      return _length;
    };

    _this.putBit = function(bit) {

      var bufIndex = Math.floor(_length / 8);
      if (_buffer.length <= bufIndex) {
        _buffer.push(0);
      }

      if (bit) {
        _buffer[bufIndex] |= (0x80 >>> (_length % 8) );
      }

      _length += 1;
    };

    return _this;
  };

  //---------------------------------------------------------------------
  // qrNumber
  //---------------------------------------------------------------------

  var qrNumber = function(data) {

    var _mode = QRMode.MODE_NUMBER;
    var _data = data;

    var _this = {};

    _this.getMode = function() {
      return _mode;
    };

    _this.getLength = function(buffer) {
      return _data.length;
    };

    _this.write = function(buffer) {

      var data = _data;

      var i = 0;

      while (i + 2 < data.length) {
        buffer.put(strToNum(data.substring(i, i + 3) ), 10);
        i += 3;
      }

      if (i < data.length) {
        if (data.length - i == 1) {
          buffer.put(strToNum(data.substring(i, i + 1) ), 4);
        } else if (data.length - i == 2) {
          buffer.put(strToNum(data.substring(i, i + 2) ), 7);
        }
      }
    };

    var strToNum = function(s) {
      var num = 0;
      for (var i = 0; i < s.length; i += 1) {
        num = num * 10 + chatToNum(s.charAt(i) );
      }
      return num;
    };

    var chatToNum = function(c) {
      if ('0' <= c && c <= '9') {
        return c.charCodeAt(0) - '0'.charCodeAt(0);
      }
      throw 'illegal char :' + c;
    };

    return _this;
  };

  //---------------------------------------------------------------------
  // qrAlphaNum
  //---------------------------------------------------------------------

  var qrAlphaNum = function(data) {

    var _mode = QRMode.MODE_ALPHA_NUM;
    var _data = data;

    var _this = {};

    _this.getMode = function() {
      return _mode;
    };

    _this.getLength = function(buffer) {
      return _data.length;
    };

    _this.write = function(buffer) {

      var s = _data;

      var i = 0;

      while (i + 1 < s.length) {
        buffer.put(
          getCode(s.charAt(i) ) * 45 +
          getCode(s.charAt(i + 1) ), 11);
        i += 2;
      }

      if (i < s.length) {
        buffer.put(getCode(s.charAt(i) ), 6);
      }
    };

    var getCode = function(c) {

      if ('0' <= c && c <= '9') {
        return c.charCodeAt(0) - '0'.charCodeAt(0);
      } else if ('A' <= c && c <= 'Z') {
        return c.charCodeAt(0) - 'A'.charCodeAt(0) + 10;
      } else {
        switch (c) {
        case ' ' : return 36;
        case '$' : return 37;
        case '%' : return 38;
        case '*' : return 39;
        case '+' : return 40;
        case '-' : return 41;
        case '.' : return 42;
        case '/' : return 43;
        case ':' : return 44;
        default :
          throw 'illegal char :' + c;
        }
      }
    };

    return _this;
  };

  //---------------------------------------------------------------------
  // qr8BitByte
  //---------------------------------------------------------------------

  var qr8BitByte = function(data) {

    var _mode = QRMode.MODE_8BIT_BYTE;
    var _data = data;
    var _bytes = qrcode.stringToBytes(data);

    var _this = {};

    _this.getMode = function() {
      return _mode;
    };

    _this.getLength = function(buffer) {
      return _bytes.length;
    };

    _this.write = function(buffer) {
      for (var i = 0; i < _bytes.length; i += 1) {
        buffer.put(_bytes[i], 8);
      }
    };

    return _this;
  };

  //---------------------------------------------------------------------
  // qrKanji
  //---------------------------------------------------------------------

  var qrKanji = function(data) {

    var _mode = QRMode.MODE_KANJI;
    var _data = data;

    var stringToBytes = qrcode.stringToBytesFuncs['SJIS'];
    if (!stringToBytes) {
      throw 'sjis not supported.';
    }
    !function(c, code) {
      // self test for sjis support.
      var test = stringToBytes(c);
      if (test.length != 2 || ( (test[0] << 8) | test[1]) != code) {
        throw 'sjis not supported.';
      }
    }('\u53cb', 0x9746);

    var _bytes = stringToBytes(data);

    var _this = {};

    _this.getMode = function() {
      return _mode;
    };

    _this.getLength = function(buffer) {
      return ~~(_bytes.length / 2);
    };

    _this.write = function(buffer) {

      var data = _bytes;

      var i = 0;

      while (i + 1 < data.length) {

        var c = ( (0xff & data[i]) << 8) | (0xff & data[i + 1]);

        if (0x8140 <= c && c <= 0x9FFC) {
          c -= 0x8140;
        } else if (0xE040 <= c && c <= 0xEBBF) {
          c -= 0xC140;
        } else {
          throw 'illegal char at ' + (i + 1) + '/' + c;
        }

        c = ( (c >>> 8) & 0xff) * 0xC0 + (c & 0xff);

        buffer.put(c, 13);

        i += 2;
      }

      if (i < data.length) {
        throw 'illegal char at ' + (i + 1);
      }
    };

    return _this;
  };

  //=====================================================================
  // GIF Support etc.
  //

  //---------------------------------------------------------------------
  // byteArrayOutputStream
  //---------------------------------------------------------------------

  var byteArrayOutputStream = function() {

    var _bytes = [];

    var _this = {};

    _this.writeByte = function(b) {
      _bytes.push(b & 0xff);
    };

    _this.writeShort = function(i) {
      _this.writeByte(i);
      _this.writeByte(i >>> 8);
    };

    _this.writeBytes = function(b, off, len) {
      off = off || 0;
      len = len || b.length;
      for (var i = 0; i < len; i += 1) {
        _this.writeByte(b[i + off]);
      }
    };

    _this.writeString = function(s) {
      for (var i = 0; i < s.length; i += 1) {
        _this.writeByte(s.charCodeAt(i) );
      }
    };

    _this.toByteArray = function() {
      return _bytes;
    };

    _this.toString = function() {
      var s = '';
      s += '[';
      for (var i = 0; i < _bytes.length; i += 1) {
        if (i > 0) {
          s += ',';
        }
        s += _bytes[i];
      }
      s += ']';
      return s;
    };

    return _this;
  };

  //---------------------------------------------------------------------
  // base64EncodeOutputStream
  //---------------------------------------------------------------------

  var base64EncodeOutputStream = function() {

    var _buffer = 0;
    var _buflen = 0;
    var _length = 0;
    var _base64 = '';

    var _this = {};

    var writeEncoded = function(b) {
      _base64 += String.fromCharCode(encode(b & 0x3f) );
    };

    var encode = function(n) {
      if (n < 0) {
        // error.
      } else if (n < 26) {
        return 0x41 + n;
      } else if (n < 52) {
        return 0x61 + (n - 26);
      } else if (n < 62) {
        return 0x30 + (n - 52);
      } else if (n == 62) {
        return 0x2b;
      } else if (n == 63) {
        return 0x2f;
      }
      throw 'n:' + n;
    };

    _this.writeByte = function(n) {

      _buffer = (_buffer << 8) | (n & 0xff);
      _buflen += 8;
      _length += 1;

      while (_buflen >= 6) {
        writeEncoded(_buffer >>> (_buflen - 6) );
        _buflen -= 6;
      }
    };

    _this.flush = function() {

      if (_buflen > 0) {
        writeEncoded(_buffer << (6 - _buflen) );
        _buffer = 0;
        _buflen = 0;
      }

      if (_length % 3 != 0) {
        // padding
        var padlen = 3 - _length % 3;
        for (var i = 0; i < padlen; i += 1) {
          _base64 += '=';
        }
      }
    };

    _this.toString = function() {
      return _base64;
    };

    return _this;
  };

  //---------------------------------------------------------------------
  // base64DecodeInputStream
  //---------------------------------------------------------------------

  var base64DecodeInputStream = function(str) {

    var _str = str;
    var _pos = 0;
    var _buffer = 0;
    var _buflen = 0;

    var _this = {};

    _this.read = function() {

      while (_buflen < 8) {

        if (_pos >= _str.length) {
          if (_buflen == 0) {
            return -1;
          }
          throw 'unexpected end of file./' + _buflen;
        }

        var c = _str.charAt(_pos);
        _pos += 1;

        if (c == '=') {
          _buflen = 0;
          return -1;
        } else if (c.match(/^\s$/) ) {
          // ignore if whitespace.
          continue;
        }

        _buffer = (_buffer << 6) | decode(c.charCodeAt(0) );
        _buflen += 6;
      }

      var n = (_buffer >>> (_buflen - 8) ) & 0xff;
      _buflen -= 8;
      return n;
    };

    var decode = function(c) {
      if (0x41 <= c && c <= 0x5a) {
        return c - 0x41;
      } else if (0x61 <= c && c <= 0x7a) {
        return c - 0x61 + 26;
      } else if (0x30 <= c && c <= 0x39) {
        return c - 0x30 + 52;
      } else if (c == 0x2b) {
        return 62;
      } else if (c == 0x2f) {
        return 63;
      } else {
        throw 'c:' + c;
      }
    };

    return _this;
  };

  //---------------------------------------------------------------------
  // gifImage (B/W)
  //---------------------------------------------------------------------

  var gifImage = function(width, height) {

    var _width = width;
    var _height = height;
    var _data = new Array(width * height);

    var _this = {};

    _this.setPixel = function(x, y, pixel) {
      _data[y * _width + x] = pixel;
    };

    _this.write = function(out) {

      //---------------------------------
      // GIF Signature

      out.writeString('GIF87a');

      //---------------------------------
      // Screen Descriptor

      out.writeShort(_width);
      out.writeShort(_height);

      out.writeByte(0x80); // 2bit
      out.writeByte(0);
      out.writeByte(0);

      //---------------------------------
      // Global Color Map

      // black
      out.writeByte(0x00);
      out.writeByte(0x00);
      out.writeByte(0x00);

      // white
      out.writeByte(0xff);
      out.writeByte(0xff);
      out.writeByte(0xff);

      //---------------------------------
      // Image Descriptor

      out.writeString(',');
      out.writeShort(0);
      out.writeShort(0);
      out.writeShort(_width);
      out.writeShort(_height);
      out.writeByte(0);

      //---------------------------------
      // Local Color Map

      //---------------------------------
      // Raster Data

      var lzwMinCodeSize = 2;
      var raster = getLZWRaster(lzwMinCodeSize);

      out.writeByte(lzwMinCodeSize);

      var offset = 0;

      while (raster.length - offset > 255) {
        out.writeByte(255);
        out.writeBytes(raster, offset, 255);
        offset += 255;
      }

      out.writeByte(raster.length - offset);
      out.writeBytes(raster, offset, raster.length - offset);
      out.writeByte(0x00);

      //---------------------------------
      // GIF Terminator
      out.writeString(';');
    };

    var bitOutputStream = function(out) {

      var _out = out;
      var _bitLength = 0;
      var _bitBuffer = 0;

      var _this = {};

      _this.write = function(data, length) {

        if ( (data >>> length) != 0) {
          throw 'length over';
        }

        while (_bitLength + length >= 8) {
          _out.writeByte(0xff & ( (data << _bitLength) | _bitBuffer) );
          length -= (8 - _bitLength);
          data >>>= (8 - _bitLength);
          _bitBuffer = 0;
          _bitLength = 0;
        }

        _bitBuffer = (data << _bitLength) | _bitBuffer;
        _bitLength = _bitLength + length;
      };

      _this.flush = function() {
        if (_bitLength > 0) {
          _out.writeByte(_bitBuffer);
        }
      };

      return _this;
    };

    var getLZWRaster = function(lzwMinCodeSize) {

      var clearCode = 1 << lzwMinCodeSize;
      var endCode = (1 << lzwMinCodeSize) + 1;
      var bitLength = lzwMinCodeSize + 1;

      // Setup LZWTable
      var table = lzwTable();

      for (var i = 0; i < clearCode; i += 1) {
        table.add(String.fromCharCode(i) );
      }
      table.add(String.fromCharCode(clearCode) );
      table.add(String.fromCharCode(endCode) );

      var byteOut = byteArrayOutputStream();
      var bitOut = bitOutputStream(byteOut);

      // clear code
      bitOut.write(clearCode, bitLength);

      var dataIndex = 0;

      var s = String.fromCharCode(_data[dataIndex]);
      dataIndex += 1;

      while (dataIndex < _data.length) {

        var c = String.fromCharCode(_data[dataIndex]);
        dataIndex += 1;

        if (table.contains(s + c) ) {

          s = s + c;

        } else {

          bitOut.write(table.indexOf(s), bitLength);

          if (table.size() < 0xfff) {

            if (table.size() == (1 << bitLength) ) {
              bitLength += 1;
            }

            table.add(s + c);
          }

          s = c;
        }
      }

      bitOut.write(table.indexOf(s), bitLength);

      // end code
      bitOut.write(endCode, bitLength);

      bitOut.flush();

      return byteOut.toByteArray();
    };

    var lzwTable = function() {

      var _map = {};
      var _size = 0;

      var _this = {};

      _this.add = function(key) {
        if (_this.contains(key) ) {
          throw 'dup key:' + key;
        }
        _map[key] = _size;
        _size += 1;
      };

      _this.size = function() {
        return _size;
      };

      _this.indexOf = function(key) {
        return _map[key];
      };

      _this.contains = function(key) {
        return typeof _map[key] != 'undefined';
      };

      return _this;
    };

    return _this;
  };

  var createDataURL = function(width, height, getPixel) {
    var gif = gifImage(width, height);
    for (var y = 0; y < height; y += 1) {
      for (var x = 0; x < width; x += 1) {
        gif.setPixel(x, y, getPixel(x, y) );
      }
    }

    var b = byteArrayOutputStream();
    gif.write(b);

    var base64 = base64EncodeOutputStream();
    var bytes = b.toByteArray();
    for (var i = 0; i < bytes.length; i += 1) {
      base64.writeByte(bytes[i]);
    }
    base64.flush();

    return 'data:image/gif;base64,' + base64;
  };

  //---------------------------------------------------------------------
  // returns qrcode function.

  return qrcode;
}();

// multibyte support
!function() {

  qrcode.stringToBytesFuncs['UTF-8'] = function(s) {
    // http://stackoverflow.com/questions/18729405/how-to-convert-utf8-string-to-byte-array
    function toUTF8Array(str) {
      var utf8 = [];
      for (var i=0; i < str.length; i++) {
        var charcode = str.charCodeAt(i);
        if (charcode < 0x80) utf8.push(charcode);
        else if (charcode < 0x800) {
          utf8.push(0xc0 | (charcode >> 6),
              0x80 | (charcode & 0x3f));
        }
        else if (charcode < 0xd800 || charcode >= 0xe000) {
          utf8.push(0xe0 | (charcode >> 12),
              0x80 | ((charcode>>6) & 0x3f),
              0x80 | (charcode & 0x3f));
        }
        // surrogate pair
        else {
          i++;
          // UTF-16 encodes 0x10000-0x10FFFF by
          // subtracting 0x10000 and splitting the
          // 20 bits of 0x0-0xFFFFF into two halves
          charcode = 0x10000 + (((charcode & 0x3ff)<<10)
            | (str.charCodeAt(i) & 0x3ff));
          utf8.push(0xf0 | (charcode >>18),
              0x80 | ((charcode>>12) & 0x3f),
              0x80 | ((charcode>>6) & 0x3f),
              0x80 | (charcode & 0x3f));
        }
      }
      return utf8;
    }
    return toUTF8Array(s);
  };

}();

(function (factory) {
  if (typeof define === 'function' && define.amd) {
      define([], factory);
  } else if (typeof exports === 'object') {
      module.exports = factory();
  }
}(function () {
    return qrcode;
}));
</script>
<script>
/* ReTaste - dashboard.js (Dashboard Pelanggan)
   Data awal dari PHP (window.RT). */
(function () {
  'use strict';
  var RT = window.RT;
  var TZ = 'Asia/Jakarta';
  var P = RT.pengaturan;

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
  function lsGet(k, d) { try { var v = localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch (e) { return d; } }
  function lsSet(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function mmss(sec) { sec = Math.max(0, sec); var m = Math.floor(sec / 60), s = sec % 60; return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s; }

  /* ---------- ikon ---------- */
  var IC = {
    home: '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    bag: '<path d="M5 8h14l-1 12H6L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
    history: '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 20c1-4 4-6 8-6s7 2 8 6"/>',
    pin: '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
    leaf: '<path d="M5 19c0-8 5-13 14-14 0 9-5 14-13 14"/><path d="M5 19c2-4 5-7 9-9"/>',
    wallet: '<path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H18v3"/><path d="M4 7.5V18a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1H6.5A2.5 2.5 0 0 1 4 7.5z"/><circle cx="16" cy="13.5" r="1.1"/>',
    star: '<path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9 6.8 19.6l1-5.8-4.3-4.1 5.9-.9L12 3.5z"/>',
    heart: '<path d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7.6a4.3 4.3 0 0 1 7.5 2.2C19.5 15.4 12 20 12 20z"/>',
    check: '<path d="m4.5 12.5 4.5 4.5 10.5-10.5"/>',
    x: '<path d="M6 6l12 12M18 6 6 18"/>',
    qr: '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><path d="M14 14h2v2h-2zM18 14h2v2h-2zM14 18h2v2h-2zM18 18h2v2h-2z"/>',
    alert: '<path d="M12 4 2.5 20h19L12 4z"/><path d="M12 10v4M12 17.2v.1"/>',
    store: '<path d="M4 9.5 5.5 4h13L20 9.5"/><path d="M4 9.5a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0 2.7 2.7 0 0 0 5.3 0"/><path d="M5.5 12.5V20h13v-7.5"/>',
    tag: '<path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.1"/>',
    gift: '<rect x="3" y="8" width="18" height="4"/><path d="M5 12v8h14v-8M12 8v12"/><path d="M12 8c-3 0-5-1-5-3s3-2 5 3c2-5 5-5 5-3s-2 3-5 3z"/>',
    box: '<path d="m12 3 8.5 4.5v9L12 21l-8.5-4.5v-9L12 3z"/><path d="m3.5 7.5 8.5 4.5 8.5-4.5M12 12v9"/>',
    shield: '<path d="M12 3.5 5 6v5.5c0 4.2 2.9 7.4 7 9 4.1-1.6 7-4.8 7-9V6l-7-2.5z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
    cap: '<path d="m2.5 9 9.5-4.5L21.5 9 12 13.5 2.5 9z"/><path d="M6.5 11.5V16c1.5 1.5 3.4 2 5.5 2s4-.5 5.5-2v-4.5"/>',
    arrow: '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>'
  };
  function svg(n) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (IC[n] || '') + '</svg>'; }
  function fillIcons(root) { $$('[data-i]', root).forEach(function (el) { if (!el.firstChild) el.innerHTML = svg(el.getAttribute('data-i')); }); }

  /* ---------- state ---------- */
  var S = {
    paket: [],
    pesanan: RT.pesanan.slice(),
    kat: RT.kategori,
    user: RT.pembeli,
    fav: lsGet('rt_fav', [1]),
    filter: { kat: 0, harga: 0, jam: 0, q: '', urut: 'terbaru' },
    tabPesanan: 'semua',
    tabRiwayat: 'selesai',
    page: 'dashboard'
  };
  var KAT_CLASS = { 1: 'k-roti', 2: 'k-nasi', 3: 'k-min', 4: 'k-roti', 5: 'k-cam' };
  var KAT_ICON = { 1: 'gift', 2: 'box', 3: 'tag', 4: 'gift', 5: 'box' };
  var STATUS = {
    menunggu_pembayaran: ['Menunggu pembayaran', 'b-wait'],
    siap_diambil: ['Siap diambil', 'b-ready'],
    selesai: ['Selesai', 'b-done'],
    dibatalkan: ['Dibatalkan', 'b-off'],
    kedaluwarsa: ['Kedaluwarsa', 'b-gray'],
    tidak_diambil: ['Tidak diambil', 'b-off']
  };
  var ALERGEN = { susu: 'Susu', telur: 'Telur', kacang: 'Kacang', gluten: 'Gluten', kedelai: 'Kedelai', seafood: 'Seafood', tidak_ada: 'Tidak ada alergen' };

  /* ---------- turunan ---------- */
  function tersedia(p) { return p.stok > 0 && now() < p.selesai; }
  function hargaTermasukPpn(harga) { return harga + Math.round(harga * P.pajak_persen / 100); }
  function total(o) { return o.satuan * o.jumlah + (o.pajak || 0) - o.pot_mhs; }
  function aktif(o) { return o.status === 'menunggu_pembayaran' || o.status === 'siap_diambil'; }
  function dipesan(p) {
    return S.pesanan.filter(function (o) { return o.paket_id === p.id && o.ambil_selesai === p.selesai && (aktif(o) || o.status === 'selesai'); })
      .reduce(function (a, o) { return a + o.jumlah; }, 0);
  }
  function kuota(p) { return Math.max(0, Math.min(P.maks_per_pembeli - dipesan(p), p.stok)); }
  function ratingPaket(p) {
    if (typeof p.ulasan_paket === 'number') return { jumlah: p.ulasan_paket, rata: p.rating_paket };
    var reviews = S.pesanan.filter(function (o) { return o.paket_id === p.id && o.ulasan; });
    return { jumlah: reviews.length, rata: reviews.length ? reviews.reduce(function (sum, o) { return sum + +o.ulasan.rating; }, 0) / reviews.length : 0 };
  }
  function tutup(p) { return now() >= p.selesai - P.tutup_pesan_menit * 60; }
  function diskonPct(p) { return Math.round((1 - p.jual / p.normal) * 100); }
  function findPaket(id) { return S.paket.filter(function (p) { return p.id === id; })[0]; }
  function findOrd(id) { return S.pesanan.filter(function (o) { return o.id === id; })[0]; }
  function isFav(id) { return S.fav.indexOf(id) > -1; }

  /* ---------- gambar (slot dengan fallback warna) ---------- */
  function ph(foto, kat, cls) {
    var src = foto ? (/^uploads\//.test(foto) ? foto : 'assets/img/paket/' + foto) : '';
    var img = src ? '<img src="' + esc(src) + '" alt="" loading="lazy" onerror="this.remove()">' : '';
    return '<div class="ph ' + (KAT_CLASS[kat] || '') + ' ' + (cls || '') + '">' + svg(KAT_ICON[kat] || 'gift') + img + '</div>';
  }
  function slot(src, cls, icon) {
    return '<div class="ph ' + (cls || '') + '">' + svg(icon || 'leaf') + '<img src="' + esc(src) + '" alt="" loading="lazy" onerror="this.remove()"></div>';
  }

  /* ---------- toast ---------- */
  function toast(msg, err) {
    var t = document.createElement('div'); t.className = 'toast' + (err ? ' err' : ''); t.textContent = msg;
    $('#toasts').appendChild(t);
    setTimeout(function () { t.classList.add('out'); setTimeout(function () { t.remove(); }, 400); }, 3200);
  }

  /* ---------- modal ---------- */
  function openModal(html, wide) {
    var b = $('#mbox'); b.className = 'mbox' + (wide ? ' wide' : '');
    b.innerHTML = '<button class="mx" data-act="close" aria-label="Tutup">' + svg('x') + '</button>' + html;
    $('#modal').classList.add('show'); fillIcons(b); b.scrollTop = 0;
  }
  function closeModal() { $('#modal').classList.remove('show'); }

  /* ---------- navigasi ---------- */
  var NAV = [
    { label: 'Utama', items: [['dashboard', 'Dashboard', 'home'], ['cari', 'Cari Paket', 'search']] },
    { label: 'Pesanan', items: [['pesanan', 'Pesanan Saya', 'bag'], ['riwayat', 'Riwayat & Ulasan', 'history']] },
    { label: 'Akun', items: [['profil', 'Profil', 'user']] }
  ];
  var TITLES = {
    dashboard: ['Dashboard', 'Ringkasan pesanan dan paket tersedia'],
    cari: ['Cari Paket', 'Paket makanan sisa yang masih bisa kamu ambil hari ini'],
    pesanan: ['Pesanan Saya', 'Pesanan yang sedang berjalan dan kode pengambilan'],
    riwayat: ['Riwayat & Ulasan', 'Pesanan selesai, ulasan, dan laporan masalah'],
    profil: ['Profil', 'Data akun dan email kampus']
  };
  function buildNav() {
    var i = 0, h = '';
    NAV.forEach(function (g) {
      h += '<div class="nav-label" style="--i:' + (i++) + '">' + g.label + '</div>';
      g.items.forEach(function (it) {
        h += '<button class="nav-item" data-go="' + it[0] + '" style="--i:' + (i++) + '"><span class="ic">' + svg(it[2]) + '</span>' + it[1] + (it[0] === 'pesanan' ? '<span class="cnt" id="cntAktif"></span>' : '') + '</button>';
      });
    });
    $('#nav').innerHTML = h;
  }
  function go(p) {
    S.page = p;
    $$('.page').forEach(function (e) { e.classList.toggle('active', e.id === 'page-' + p); });
    $$('.nav-item').forEach(function (e) { e.classList.toggle('active', e.getAttribute('data-go') === p); });
    $('#tbT').textContent = TITLES[p][0]; $('#tbS').textContent = TITLES[p][1];
    closeSide(); render(); window.scrollTo(0, 0);
    try { history.replaceState(null, '', '#' + p); } catch (e) {}
  }
  function openSide() { $('#sidebar').classList.add('open'); $('#scrim').classList.add('show'); }
  function closeSide() { $('#sidebar').classList.remove('open'); $('#scrim').classList.remove('show'); }

  /* ---------- komponen ---------- */
  function pkCard(p) {
    var tt = tutup(p), review = ratingPaket(p);
    return '<article class="card pk hov rise">' +
      '<div class="pk-img">' + ph(p.foto, p.kategori) + '<span class="pk-off">-' + diskonPct(p) + '%</span>' +
      '<button class="fav ' + (isFav(p.toko_id) ? 'on' : '') + '" data-act="fav" data-id="' + p.toko_id + '" aria-label="Favorit toko ' + esc(p.toko) + '">' + svg('heart') + '</button></div>' +
      '<div class="pk-b">' +
      '<div class="pk-toko"><span>' + esc(p.toko) + '</span><span class="st">&#9733; ' + p.rating.toFixed(1).replace('.', ',') + '</span></div>' +
      '<div class="fhint">' + esc(p.alamat || '') + '</div>' +
      '<h3>' + esc(p.nama) + '</h3>' +
      '<div class="fhint">' + (review.jumlah ? '&#9733; ' + review.rata.toFixed(1).replace('.', ',') + ' / 5 dari ' + review.jumlah + ' ulasan paket' : 'Belum ada ulasan untuk paket ini') + '</div>' +
      '<div><span class="tag ' + (p.tipe === 'kejutan' ? 'amber' : 'moss') + '">' + (p.tipe === 'kejutan' ? 'Paket Kejutan' : 'Menu Spesifik') + '</span></div>' +
      '<div class="pk-price"><b>' + rp(hargaTermasukPpn(p.jual)) + '</b><s>' + rp(hargaTermasukPpn(p.normal)) + '</s></div><div class="fhint">Harga sudah termasuk PPN ' + P.pajak_persen + '%</div>' +
      '<div class="pk-meta"><div>' + svg('clock') + 'Ambil ' + jam(p.mulai) + '&ndash;' + jam(p.selesai) + '</div>' +
      '<div>' + svg('box') + '<span class="' + (p.stok <= 2 ? 'low' : '') + '">Sisa ' + p.stok + ' paket</span></div></div>' +
      '<button class="btn btn-p btn-w" data-act="paket" data-id="' + p.id + '">' + (tt ? 'Lihat detail' : 'Lihat &amp; pesan') + '</button>' +
      '</div></article>';
  }
  function ordCard(o) {
    var st = STATUS[o.status] || [o.status, 'b-gray'], info = '', act = '';
    info += '<span>' + svg('store') + esc(o.toko) + '</span><span>' + svg('bag') + o.jumlah + ' paket</span>';
    if (o.status === 'menunggu_pembayaran') {
      info += '<span>' + svg('clock') + 'Bayar dalam <b class="cd" data-cd="' + o.batas_bayar + '">' + mmss(o.batas_bayar - now()) + '</b></span>';
      act = '<button class="btn btn-p btn-sm" data-act="bayar" data-id="' + o.id + '">Bayar sekarang</button><button class="btn btn-d btn-sm" data-act="batal" data-id="' + o.id + '">Batalkan</button>';
    } else if (o.status === 'siap_diambil') {
      info += '<span>' + svg('clock') + 'Ambil ' + jam(o.ambil_mulai) + '&ndash;' + jam(o.ambil_selesai) + '</span>';
      act = '<button class="btn btn-p btn-sm" data-act="kode" data-id="' + o.id + '">' + svg('qr') + 'Lihat kode &amp; QR</button>';
    } else {
      info += '<span>' + svg('clock') + tgl(o.selesai_ts || o.dibuat) + '</span>';
      if (o.status === 'selesai') {
        if (!o.ulasan) act += '<button class="btn btn-p btn-sm" data-act="ulasan" data-id="' + o.id + '">' + svg('star') + 'Beri ulasan</button>';
        if (!o.dilaporkan) act += '<button class="btn btn-o btn-sm" data-act="lapor" data-id="' + o.id + '">' + svg('alert') + 'Laporkan</button>';
        else act += '<span class="badge b-gray">Laporan terkirim</span>';
      }
    }
    var extra = '';
    if (o.status === 'siap_diambil') extra = '<div class="code-pill">' + esc(o.kode_ambil) + '<small>KODE PENGAMBILAN</small></div>';
    if (o.ulasan) extra = '<div class="rev"><span class="stars ro">' + starsHtml(o.ulasan.rating) + '</span> ' + esc(o.ulasan.komentar || '') + '</div>';
    return '<article class="card ord rise">' + ph(o.foto, o.kategori) +
      '<div class="ord-main"><div class="ord-kode">' + esc(o.kode) + '</div><h3>' + esc(o.nama) + '</h3><div class="ord-info">' + info + '</div>' + extra + '</div>' +
      '<div class="ord-side"><span class="badge ' + st[1] + '">' + st[0] + '</span><span class="tot">' + rp(total(o)) + '</span><div class="ord-act">' + act + '</div></div></article>';
  }
  function starsHtml(n) { var h = ''; for (var i = 1; i <= 5; i++) h += '<button class="' + (i <= n ? 'on' : '') + '" tabindex="-1">' + svg('star') + '</button>'; return h; }
  function empty(t, p, btn, img) {
    return '<div class="card empty">' + slot(img || 'assets/img/dashboard/empty-state.png', 'k-cam', 'bag') + '<h3>' + t + '</h3><p>' + p + '</p>' + (btn || '') + '</div>';
  }
  function tabs(list, cur, key) {
    return '<div class="tabs" role="tablist">' + list.map(function (t) {
      return '<button class="tab ' + (t[0] === cur ? 'on' : '') + '" role="tab" data-act="tab" data-key="' + key + '" data-v="' + t[0] + '">' + t[1] + (t[2] != null ? '<small>' + t[2] + '</small>' : '') + '</button>';
    }).join('') + '</div>';
  }

  /* ---------- RENDER: dashboard ---------- */
  function sapaan() {
    var h = parseInt(new Date(now() * 1000).toLocaleString('en-GB', { hour: 'numeric', hour12: false, timeZone: TZ }), 10);
    return h < 11 ? 'Selamat pagi' : h < 15 ? 'Selamat siang' : h < 18 ? 'Selamat sore' : 'Selamat malam';
  }
  function stats() {
    var a = S.pesanan.filter(aktif), menunggu = S.pesanan.filter(function (o) { return o.status === 'menunggu_pembayaran'; }).length;
    var done = S.pesanan.filter(function (o) { return o.status === 'selesai'; });
    var porsi = done.reduce(function (s, o) { return s + o.jumlah; }, 0);
    var hemat = done.reduce(function (s, o) { return s + (o.normal - o.satuan) * o.jumlah + o.pot_mhs; }, 0);
    var tunggu = done.filter(function (o) { return !o.ulasan; }).length;
    return { aktif: a.length, menunggu: menunggu, porsi: porsi, hemat: hemat, tunggu: tunggu, done: done };
  }
  function chartHemat(done) {
    var days = [], n = now(), i;
    for (i = 6; i >= 0; i--) days.push(n - i * 86400);
    var key = function (ts) { return new Date(ts * 1000).toLocaleDateString('en-CA', { timeZone: TZ }); };
    var vals = days.map(function (d) {
      var k = key(d);
      return done.filter(function (o) { return key(o.selesai_ts || o.dibuat) === k; }).reduce(function (s, o) { return s + (o.normal - o.satuan) * o.jumlah + o.pot_mhs; }, 0);
    });
    var max = Math.max.apply(null, vals.concat([10000])), W = 560, H = 210, pl = 8, pb = 28, pt = 22, bw = 42, gap = (W - pl * 2 - bw * 7) / 6;
    var g = '<defs><linearGradient id="bg1" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#F7A503"/><stop offset="1" stop-color="#B3BC53"/></linearGradient></defs>';
    [0, .5, 1].forEach(function (f) { var y = pt + (H - pt - pb) * (1 - f); g += '<line class="gridl" x1="0" x2="' + W + '" y1="' + y + '" y2="' + y + '"/>'; });
    vals.forEach(function (v, k) {
      var h = (H - pt - pb) * (v / max), x = pl + k * (bw + gap), y = H - pb - h;
      if (v > 0) g += '<rect class="bar-r" style="animation-delay:' + (k * 60) + 'ms" x="' + x + '" y="' + y + '" width="' + bw + '" height="' + h + '" rx="9"/><text class="vl" x="' + (x + bw / 2) + '" y="' + (y - 6) + '" text-anchor="middle">' + Math.round(v / 1000) + 'rb</text>';
      else g += '<rect x="' + x + '" y="' + (H - pb - 4) + '" width="' + bw + '" height="4" rx="2" fill="#F0E4C0"/>';
      g += '<text class="axl" x="' + (x + bw / 2) + '" y="' + (H - 8) + '" text-anchor="middle">' + tglPendek(days[k]) + '</text>';
    });
    return '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Grafik penghematan 7 hari terakhir">' + g + '</svg>';
  }
  function renderDashboard() {
    var st = stats(), nTersedia = S.paket.filter(tersedia).length, near = S.paket.filter(tersedia).slice(0, 3);
    var aktifList = S.pesanan.filter(aktif).sort(function (a, b) { return b.dibuat - a.dibuat; });
    var h = '';
    h += '<div class="hero-card rise">' +
      '<video class="hero-media" autoplay muted loop playsinline preload="metadata" poster="assets/img/hero-poster.jpg" aria-hidden="true"><source src="assets/video/hero.mp4" type="video/mp4"></video>' +
      '<div class="hero-txt"><h1>' + sapaan() + ', <em>' + esc(S.user.nama) + '</em></h1>' +
      '<p>' + (nTersedia ? 'Ada ' + nTersedia + ' paket makanan sisa layak konsumsi yang tersedia hari ini.' : 'Belum ada paket tersedia saat ini.') + ' Hemat di kantong, selamatkan dari terbuang.</p>' +
      '<div class="hmeta">' + (S.user.mahasiswa ? '<span class="hm">' + svg('cap') + 'Harga mahasiswa <b>-' + P.diskon_mahasiswa_pct + '%</b></span>' : '') + '</div>' +
      '<div class="hero-btns"><button class="btn btn-l" data-go="cari">' + svg('search') + 'Cari paket</button><button class="btn btn-g" data-go="pesanan">Pesanan saya</button></div></div></div>';

    h += '<div class="g4 mb">' +
      '<button class="card stat hl hov rise" style="--i:1" data-go="pesanan"><div class="stat-top"><span class="stat-l">Pesanan Aktif</span><span class="stat-ic" data-i="bag"></span></div><div class="stat-v">' + st.aktif + ' <small>pesanan</small></div><div class="stat-n">' + st.menunggu + ' menunggu pembayaran</div></button>' +
      '<button class="card stat hov rise" style="--i:2" data-go="riwayat"><div class="stat-top"><span class="stat-l">Porsi Terselamatkan</span><span class="stat-ic ic-g" data-i="leaf"></span></div><div class="stat-v">' + st.porsi + ' <small>paket</small></div><div class="stat-n">Dari pesanan yang sudah selesai</div></button>' +
      '<button class="card stat hov rise" style="--i:3" data-go="riwayat"><div class="stat-top"><span class="stat-l">Total Hemat</span><span class="stat-ic ic-a" data-i="wallet"></span></div><div class="stat-v" style="font-size:1.8rem">' + rp(st.hemat) + '</div><div class="stat-n">Selisih harga normal + diskon</div></button>' +
      '<button class="card stat hov rise" style="--i:4" data-go="riwayat"><div class="stat-top"><span class="stat-l">Menunggu Ulasan</span><span class="stat-ic ic-b" data-i="star"></span></div><div class="stat-v">' + st.tunggu + ' <small>pesanan</small></div><div class="stat-n">Bantu toko dengan rating</div></button></div>';

    h += '<div class="g2 mb"><div class="card rise" style="--i:2"><div class="card-h"><div><div class="card-t">Pesanan yang sedang berjalan</div><div class="card-s">Bayar tepat waktu dan tunjukkan kode saat mengambil</div></div><button class="link" data-go="pesanan">Lihat semua ' + svg('arrow') + '</button></div>' +
      (aktifList.length ? '<div class="ord-list">' + aktifList.slice(0, 2).map(ordCard).join('') + '</div>' : empty('Belum ada pesanan aktif', 'Cari paket tersedia dan selamatkan makanan hari ini.', '<button class="btn btn-p" data-go="cari">Cari paket</button>')) + '</div>' +
      '<div class="card rise" style="--i:3"><div class="card-h"><div><div class="card-t">Dampak kamu</div><div class="card-s">Dari transaksi yang tercatat</div></div></div>' +
      '<div class="impact">' + slot('assets/img/dashboard/dampak-banner.jpg', 'k-cam', 'leaf') + '<div class="impact-t"><b>' + st.porsi + ' paket terselamatkan</b></div></div>' +
      '<div class="kv"><div><span>Total hemat</span><b>' + rp(st.hemat) + '</b></div><div><span>Toko favorit</span><b>' + S.fav.length + '</b></div><div><span>Ulasan diberikan</span><b>' + st.done.filter(function (o) { return o.ulasan; }).length + '</b></div></div></div></div>';

    h += '<div class="card mb rise"><div class="card-h"><div><div class="card-t">Paket tersedia</div><div class="card-s">Paket terbaru yang jam ambilnya belum lewat</div></div><button class="link" data-go="cari">Lihat semua ' + svg('arrow') + '</button></div>' +
      (near.length ? '<div class="pk-grid">' + near.map(pkCard).join('') + '</div>' : empty('Belum ada paket tersedia', 'Coba lagi nanti sore, toko biasanya memasang paket menjelang tutup.')) + '</div>';

    h += '<div class="g2 mb"><div class="card rise"><div class="card-h"><div><div class="card-t">Penghematanmu</div><div class="card-s">7 hari terakhir, dari pesanan selesai</div></div></div><div id="chart">' + chartHemat(st.done) + '</div></div>' +
      '<div class="card rise"><div class="card-h"><div><div class="card-t">Aturan singkat</div><div class="card-s">Biar pesananmu lancar</div></div></div>' +
      '<div class="insight"><div class="ii" data-i="clock"></div><div><b>Bayar dalam ' + P.batas_bayar_menit + ' menit</b><p>Stok ditahan selama itu. Lewat batas, pesanan kedaluwarsa dan stok kembali.</p></div></div>' +
      '<div class="insight"><div class="ii" data-i="bag"></div><div><b>Maksimal ' + P.maks_per_pembeli + ' paket</b><p>Berlaku untuk satu paket yang sama, supaya semua kebagian.</p></div></div>' +
      '<div class="insight"><div class="ii" data-i="alert"></div><div><b>Tidak datang = tidak ada refund</b><p>Pesanan yang tidak diambil sampai jam ambil berakhir tidak mendapat pengembalian dana.</p></div></div></div></div>';
    $('#page-dashboard').innerHTML = h;
  }

  /* ---------- RENDER: cari ---------- */
  function renderCari() {
    var f = S.filter;
    var query = f.q.trim().toLocaleLowerCase('id-ID');
    var list = S.paket.filter(tersedia).filter(function (p) {
      if (query && (p.nama + ' ' + p.toko).toLocaleLowerCase('id-ID').indexOf(query) === -1) return false;
      if (f.kat && p.kategori !== f.kat) return false;
      if (f.harga && p.jual > f.harga) return false;
      if (f.jam === 1 && p.selesai - now() > 2 * 3600) return false;
      if (f.jam === 2 && p.selesai - now() <= 2 * 3600) return false;
      return true;
    }).sort(function (a, b) {
      if (f.urut === 'harga_naik') return a.jual - b.jual;
      if (f.urut === 'harga_turun') return b.jual - a.jual;
      if (f.urut === 'rating') return b.rating - a.rating;
      return b.id - a.id;
    });
    var opt = function (v, t, cur) { return '<option value="' + v + '"' + (String(cur) === String(v) ? ' selected' : '') + '>' + t + '</option>'; };
    var h = '<div class="sec-h rise"><h1>Cari paket</h1><p>Paket yang jam ambilnya sudah lewat atau stoknya habis tidak ditampilkan.</p></div>' +
      '<div class="card mb rise"><div class="filters">' +
      '<div class="field full"><label for="fCari">Cari nama paket atau toko</label><input id="fCari" data-f="q" type="search" value="' + esc(f.q) + '" placeholder="Contoh: roti, nasi, Roti Berkah" autocomplete="off"></div>' +
      '<div class="field"><label for="fKat">Kategori</label><select id="fKat" data-f="kat">' + opt(0, 'Semua kategori', f.kat) + S.kat.map(function (k) { return opt(k.id, esc(k.nama), f.kat); }).join('') + '</select></div>' +
      '<div class="field"><label for="fHarga">Rentang harga</label><select id="fHarga" data-f="harga">' + opt(0, 'Semua harga', f.harga) + opt(10000, 'Sampai Rp10.000', f.harga) + opt(15000, 'Sampai Rp15.000', f.harga) + opt(20000, 'Sampai Rp20.000', f.harga) + '</select></div>' +
      '<div class="field"><label for="fJam">Jam ambil</label><select id="fJam" data-f="jam">' + opt(0, 'Kapan saja', f.jam) + opt(1, 'Berakhir dalam 2 jam', f.jam) + opt(2, 'Masih lebih dari 2 jam', f.jam) + '</select></div>' +
      '<div class="field"><label for="fUrut">Urutkan</label><select id="fUrut" data-f="urut">' + opt('terbaru', 'Terbaru', f.urut) + opt('harga_naik', 'Harga terendah', f.urut) + opt('harga_turun', 'Harga tertinggi', f.urut) + opt('rating', 'Rating tertinggi', f.urut) + '</select></div>' +
      '<button class="btn btn-o" data-act="reset">Reset</button></div></div>' +
      '<div class="card-s" style="margin:0 2px 12px;font-weight:700" role="status" aria-live="polite">' + list.length + ' paket ditemukan</div>' +
      (list.length ? '<div class="pk-grid">' + list.map(pkCard).join('') + '</div>' : empty('Tidak ada paket yang cocok', 'Coba ubah kategori, rentang harga, atau jam ambil.', '<button class="btn btn-p" data-act="reset">Reset filter</button>'));
    $('#page-cari').innerHTML = h;
  }

  /* ---------- RENDER: pesanan & riwayat ---------- */
  function renderPesanan() {
    var all = S.pesanan.filter(aktif).sort(function (a, b) { return b.dibuat - a.dibuat; });
    var w = all.filter(function (o) { return o.status === 'menunggu_pembayaran'; }), r = all.filter(function (o) { return o.status === 'siap_diambil'; });
    var list = S.tabPesanan === 'menunggu' ? w : S.tabPesanan === 'siap' ? r : all;
    var h = '<div class="sec-h rise"><h1>Pesanan saya</h1><p>Tunjukkan kode pengambilan kepada penjual pada jam ambil. Kode hanya berlaku sekali.</p></div>' +
      tabs([['semua', 'Semua', all.length], ['menunggu', 'Menunggu pembayaran', w.length], ['siap', 'Siap diambil', r.length]], S.tabPesanan, 'tabPesanan') +
      (list.length ? '<div class="ord-list">' + list.map(ordCard).join('') + '</div>' : empty('Tidak ada pesanan di sini', 'Pesanan yang sedang berjalan akan muncul di halaman ini.', '<button class="btn btn-p" data-go="cari">Cari paket</button>'));
    $('#page-pesanan').innerHTML = h;
  }
  function renderRiwayat() {
    var done = S.pesanan.filter(function (o) { return o.status === 'selesai'; }).sort(function (a, b) { return (b.selesai_ts || 0) - (a.selesai_ts || 0); });
    var other = S.pesanan.filter(function (o) { return ['dibatalkan', 'kedaluwarsa', 'tidak_diambil'].indexOf(o.status) > -1; }).sort(function (a, b) { return b.dibuat - a.dibuat; });
    var list = S.tabRiwayat === 'selesai' ? done : other;
    var h = '<div class="sec-h rise"><h1>Riwayat &amp; ulasan</h1><p>Beri rating setelah pesanan selesai (satu kali per pesanan). Jika makanan tidak sesuai atau tidak layak, laporkan ke admin.</p></div>' +
      tabs([['selesai', 'Selesai', done.length], ['lain', 'Dibatalkan &amp; lainnya', other.length]], S.tabRiwayat, 'tabRiwayat') +
      (list.length ? '<div class="ord-list">' + list.map(ordCard).join('') + '</div>' : empty('Belum ada riwayat', 'Pesanan yang selesai akan tampil di sini.'));
    $('#page-riwayat').innerHTML = h;
  }

  /* ---------- RENDER: profil ---------- */
  function renderProfil() {
    var u = S.user, favToko = {};
    S.paket.forEach(function (p) { favToko[p.toko_id] = p.toko; });
    var h = '<div class="sec-h rise"><h1>Profil</h1><p>Kelola informasi akun pembeli.</p></div><div class="prof-grid">' +
      '<form class="card rise" id="fProfil" novalidate><div class="card-h"><div class="card-t">Data akun</div></div><div class="form-grid">' +
      '<div class="field"><label for="pNama">Nama lengkap</label><input id="pNama" value="' + esc(u.nama) + '" required></div>' +
      '<div class="field"><label for="pEmail">Email (untuk login)</label><input id="pEmail" value="' + esc(u.email) + '" readonly></div>' +
      '<div class="field"><label for="pTelp">No. telepon</label><input id="pTelp" value="' + esc(u.no_telp || '') + '" inputmode="tel"></div>' +
      '<div class="field"><label for="pKampus">Email kampus</label><input id="pKampus" value="' + esc(u.email_kampus || '') + '" placeholder="nama@mhs.kampus.ac.id"></div>' +
      '<div class="field full"><div class="stat-vrf">' + (u.mahasiswa ? '<span class="badge b-done">' + svg('check') + ' Terverifikasi</span><span>Harga mahasiswa -' + P.diskon_mahasiswa_pct + '% aktif otomatis saat memesan.</span>' : '<span class="badge b-wait">Belum terverifikasi</span><button type="button" class="btn btn-o btn-sm" data-act="verif">Kirim tautan verifikasi</button>') + '</div></div>' +
      '</div><div style="margin-top:18px"><button class="btn btn-p" type="submit">Simpan perubahan</button></div></form>' +
      '<div class="card rise" style="--i:1"><div class="card-h"><div class="card-t">Toko favorit</div></div>' +
      (S.fav.length ? '<div class="kv">' + S.fav.map(function (id) { return '<div><span>' + svg('heart').replace('<svg', '<svg style="width:15px;height:15px;vertical-align:-3px;color:#C8432B;margin-right:6px"') + esc(favToko[id] || 'Toko #' + id) + '</span><button class="link" data-act="fav" data-id="' + id + '">Hapus</button></div>'; }).join('') + '</div>' : '<p class="fhint">Belum ada toko favorit. Ketuk ikon hati pada kartu paket.</p>') + '</div>' +
      '</div>';
    $('#page-profil').innerHTML = h;
  }

  function render() {
    var f = { dashboard: renderDashboard, cari: renderCari, pesanan: renderPesanan, riwayat: renderRiwayat, profil: renderProfil }[S.page];
    f(); fillIcons($('#page-' + S.page));
    var c = S.pesanan.filter(aktif).length; $('#cntAktif').textContent = c || '';
  }

  function loadPackages() {
    fetch('api_paket.php?action=list', { credentials: 'same-origin' })
      .then(function (response) {
        return response.json().then(function (body) {
          if (!response.ok || !body.ok || !Array.isArray(body.packages)) {
            throw new Error(body.message || 'Paket gagal dimuat dari database.');
          }
          return body.packages;
        });
      })
      .then(function (packages) {
        S.paket = packages;
        render();
      })
      .catch(function (error) {
        toast(error.message || 'Paket gagal dimuat dari database. Muat ulang halaman untuk mencoba lagi.', true);
      });
  }

  /* ---------- MODAL: detail paket ---------- */
  function openPaket(id) {
    var p = findPaket(id); if (!p) return;
    var q = 1, max = kuota(p), tt = tutup(p), sdh = dipesan(p);
    var al = p.alergen.map(function (a) { return '<span class="chip-al ' + (a === 'tidak_ada' ? 'aman' : '') + '">' + (a === 'tidak_ada' ? svg('check') : svg('alert')).replace('<svg', '<svg style="width:13px;height:13px"') + ALERGEN[a] + '</span>'; }).join('');
    function ringkas() {
      var sub = p.jual * q, pot = S.user.mahasiswa ? Math.round(sub * P.diskon_mahasiswa_pct / 100) : 0;
      var ppn = Math.round(p.jual * P.pajak_persen / 100) * q;
      return '<div><span>Harga paket (' + q + ' &times; ' + rp(p.jual) + ')</span><b>' + rp(sub) + '</b></div>' +
        (pot ? '<div class="dc"><span>Diskon mahasiswa ' + P.diskon_mahasiswa_pct + '%</span><b>-' + rp(pot) + '</b></div>' : '') +
        '<div><span>PPN ' + P.pajak_persen + '%</span><b>' + rp(ppn) + '</b></div>' +
        '<div class="tt"><span>Total bayar</span><b>' + rp(sub + ppn - pot) + '</b></div>';
    }
    var disabled = tt || max < 1, note = '';
    if (tt) note = '<div class="note">' + svg('clock') + 'Pemesanan ditutup ' + P.tutup_pesan_menit + ' menit sebelum jam ambil berakhir.</div>';
    else if (max < 1 && sdh >= P.maks_per_pembeli) note = '<div class="note">' + svg('info') + 'Kamu sudah memesan batas maksimal ' + P.maks_per_pembeli + ' paket untuk paket ini.</div>';
    else if (max < 1) note = '<div class="note">' + svg('info') + 'Stok paket ini sudah habis.</div>';
    openModal('<div class="m-img">' + ph(p.foto, p.kategori) + '</div><div class="m-body">' +
      '<div class="rowx"><div><span class="tag ' + (p.tipe === 'kejutan' ? 'amber' : 'moss') + '">' + (p.tipe === 'kejutan' ? 'Paket Kejutan' : 'Menu Spesifik') + '</span></div><span class="badge b-done">Hemat ' + diskonPct(p) + '%</span></div>' +
      '<h2 style="margin-top:8px">' + esc(p.nama) + '</h2>' +
      '<p>' + esc(p.deskripsi || (p.tipe === 'kejutan' ? 'Isi paket berupa kejutan sesuai kategori. Nilai normal minimal ' + rp(hargaTermasukPpn(p.normal)) + '.' : '')) + '</p>' +
      '<div class="food-safety"><h3>Komposisi atau isi paket</h3><p>' + esc(p.komposisi || 'Belum diinformasikan oleh penjual.') + '</p></div>' +
      '<div class="chips"><span class="chip-i">' + svg('store').replace('<svg', '<svg style="width:13px;height:13px;vertical-align:-2px"') + ' ' + esc(p.toko) + ' &middot; &#9733; ' + p.rating.toFixed(1).replace('.', ',') + ' (' + p.ulasan + ' ulasan toko)</span><span class="chip-i">&#9733; ' + (ratingPaket(p).jumlah ? ratingPaket(p).rata.toFixed(1).replace('.', ',') + ' dari ' + ratingPaket(p).jumlah + ' ulasan paket' : 'Belum ada ulasan paket') + '</span><span class="chip-i">' + svg('pin') + ' ' + esc(p.alamat || 'Alamat toko belum tersedia') + '</span>' +
      '<span class="chip-i">Ambil ' + jam(p.mulai) + '&ndash;' + jam(p.selesai) + '</span><span class="chip-i">Dibuat pukul ' + esc(p.produksi) + '</span><span class="chip-i">Sisa ' + p.stok + '</span></div>' +
      '<div class="card-s" style="margin-bottom:6px">Alergen</div><div class="chips" style="margin-top:0">' + al + '</div>' + (p.catatan_alergen ? '<p style="font-size:.82rem">' + esc(p.catatan_alergen) + '</p>' : '') +
      '<section class="food-safety" aria-label="Informasi keamanan makanan"><h3>Informasi keamanan makanan</h3><dl>' +
      '<dt>Konsumsi sebelum</dt><dd>' + esc(p.konsumsi_sebelum || 'Belum diinformasikan penjual.') + '</dd>' +
      '<dt>Penyimpanan</dt><dd>' + esc(p.penyimpanan || 'Belum diinformasikan penjual.') + '</dd>' +
      '<dt>Kontaminasi silang</dt><dd>' + esc(p.kontaminasi_silang || 'Belum diinformasikan penjual.') + '</dd>' +
      '</dl><p>Informasi disediakan penjual dan mungkin belum mencakup semua risiko. Jika memiliki alergi atau ragu dengan kondisi makanan, tanyakan langsung ke toko sebelum memesan.</p></section>' +
      '<div class="rowx"><div><div class="card-s" style="margin-bottom:4px">Jumlah (maks ' + P.maks_per_pembeli + ')</div><div class="stepper"><button data-act="qm" ' + (disabled ? 'disabled' : '') + ' aria-label="Kurangi">&minus;</button><b id="qv">' + (disabled ? 0 : q) + '</b><button data-act="qp" ' + (disabled || max <= 1 ? 'disabled' : '') + ' aria-label="Tambah">+</button></div></div>' +
      '<div class="pk-price"><b>' + rp(hargaTermasukPpn(p.jual)) + '</b><s>' + rp(hargaTermasukPpn(p.normal)) + '</s></div><div class="fhint">Harga sudah termasuk PPN ' + P.pajak_persen + '%</div></div>' +
      '<div class="sum" id="sum">' + ringkas() + '</div>' + note +
      '<button class="btn btn-p btn-w" id="btnPesan" style="margin-top:14px" ' + (disabled ? 'disabled' : '') + '>Pesan sekarang</button>' +
      '<p class="fhint" style="margin-top:8px;text-align:center">Stok ditahan ' + P.batas_bayar_menit + ' menit sampai kamu membayar.</p></div>');
    var box = $('#mbox');
    function upd() {
      $('#qv').textContent = q; $('#sum').innerHTML = ringkas();
      $('[data-act="qm"]', box).disabled = q <= 1; $('[data-act="qp"]', box).disabled = q >= max;
    }
    if (!disabled) {
      $('[data-act="qm"]', box).disabled = true;
      box.onclick = function (e) {
        var a = e.target.closest('[data-act]'); if (!a) return;
        var act = a.getAttribute('data-act');
        if (act === 'qp' && q < max) { q++; upd(); } else if (act === 'qm' && q > 1) { q--; upd(); } else if (act === 'close') closeModal();
      };
      $('#btnPesan').onclick = function () { buatPesanan(p, q); };
    } else box.onclick = function (e) { if (e.target.closest('[data-act="close"]')) closeModal(); };
  }

  /* ---------- AKSI pesanan ---------- */
  function buatPesanan(p, q) {
    if (tutup(p)) return toast('Pemesanan sudah ditutup untuk paket ini.', true);
    if (q > p.stok) return toast('Maaf, stok sudah habis diambil pembeli lain.', true);
    if (q > kuota(p)) return toast('Melebihi batas ' + P.maks_per_pembeli + ' paket per pembeli.', true);
    var button = $('#btnPesan'); button.disabled = true; button.textContent = 'Menyimpan pesanan...';
    var body = new URLSearchParams({ action: 'create', paket_id: String(p.id), jumlah: String(q), csrf: RT.csrf });
    fetch('api_pesanan.php', {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body.toString(), credentials: 'same-origin'
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok || !data.ok) throw new Error(data.message || 'Pesanan gagal disimpan.');
        return data;
      });
    }).then(function (data) {
      p.stok = data.stock;
      S.pesanan.unshift(data.order);
      closeModal(); toast(data.message);
      S.tabPesanan = 'semua'; go('pesanan'); setTimeout(function () { openBayar(data.order.id); }, 250);
    }).catch(function (error) {
      button.disabled = false; button.textContent = 'Pesan sekarang';
      toast(error.message || 'Pesanan gagal disimpan. Coba lagi.', true);
    });
  }
  function openBayar(id) {
    var o = findOrd(id); if (!o || o.status !== 'menunggu_pembayaran') return;
    openModal('<div class="m-body"><h2>Pembayaran</h2><p>Pilih metode untuk mencatat pembayaran simulasi. Selesaikan sebelum waktu habis: <b class="cd" data-cd="' + o.batas_bayar + '">' + mmss(o.batas_bayar - now()) + '</b></p>' +
      '<div class="sum"><div><span>Harga paket (' + esc(o.nama) + ' &times; ' + o.jumlah + ')</span><b>' + rp(o.satuan * o.jumlah) + '</b></div>' + (o.pot_mhs ? '<div class="dc"><span>Diskon mahasiswa</span><b>-' + rp(o.pot_mhs) + '</b></div>' : '') + '<div><span>PPN ' + P.pajak_persen + '%</span><b>' + rp(o.pajak || 0) + '</b></div><div class="tt"><span>Total bayar</span><b>' + rp(total(o)) + '</b></div></div>' +
      '<div class="paylist"><label class="pay"><input type="radio" name="mtd" value="QRIS (simulasi)" checked> QRIS<small>simulasi</small></label><label class="pay"><input type="radio" name="mtd" value="E-wallet (simulasi)"> E-wallet<small>simulasi</small></label><label class="pay"><input type="radio" name="mtd" value="Transfer bank (simulasi)"> Transfer bank<small>simulasi</small></label></div>' +
      '<button class="btn btn-p btn-w" id="btnBayar">Bayar ' + rp(total(o)) + '</button></div>');
    $('#mbox').onclick = function (e) { if (e.target.closest('[data-act="close"]')) closeModal(); };
    $('#btnBayar').onclick = function () {
      if (now() > o.batas_bayar) { expire(); closeModal(); return toast('Waktu pembayaran sudah habis.', true); }
      var button = $('#btnBayar'), method = $('input[name="mtd"]:checked').value;
      button.disabled = true; button.textContent = 'Memproses pembayaran...';
      var body = new URLSearchParams({ action: 'pay', pesanan_id: String(o.id), metode_bayar: method, csrf: RT.csrf });
      fetch('api_pesanan.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: body.toString(), credentials: 'same-origin'
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || !data.ok) throw new Error(data.message || 'Pembayaran gagal diproses.');
          return data;
        });
      }).then(function (data) {
        o.status = data.order.status; o.kode_ambil = data.order.kode_ambil;
        o.metode = data.order.metode; o.waktu_bayar = data.order.waktu_bayar;
        o.pajak = data.order.pajak; o.total_bayar = data.order.total_bayar;
        render(); openKode(o.id);
      }).catch(function (error) {
        button.disabled = false; button.textContent = 'Bayar ' + rp(total(o));
        toast(error.message || 'Pembayaran gagal diproses. Coba lagi.', true);
      });
    };
  }
  function openKode(id) {
    var o = findOrd(id); if (!o) return;
    var qr = ''; try { var q = qrcode(0, 'M'); q.addData(o.kode_ambil); q.make(); qr = q.createSvgTag({ cellSize: 6, margin: 0, scalable: true }); } catch (e) { qr = ''; }
    openModal('<div class="m-body"><h2>Kode pengambilan</h2><p>Tunjukkan kode atau QR ini kepada penjual di <b>' + esc(o.toko) + '</b>. Hanya sah pada jam ambil dan hanya sekali pakai.</p>' +
      '<div class="qr-box"><div class="qr">' + qr + '</div><div class="code">' + esc(o.kode_ambil) + '</div><small>Ambil pukul ' + jam(o.ambil_mulai) + '&ndash;' + jam(o.ambil_selesai) + '</small></div>' +
      '<div class="sum"><div><span>Pesanan</span><b>' + esc(o.kode) + '</b></div><div><span>' + esc(o.nama) + '</span><b>&times; ' + o.jumlah + '</b></div><div class="tt"><span>Dibayar</span><b>' + rp(total(o)) + '</b></div></div>' +
      '<div class="note">' + svg('alert') + 'Pesanan yang tidak diambil sampai jam ambil berakhir tidak mendapat pengembalian dana.</div></div>');
    $('#mbox').onclick = function (e) { if (e.target.closest('[data-act="close"]')) closeModal(); };
  }
  function batal(id) {
    var o = findOrd(id); if (!o || o.status !== 'menunggu_pembayaran') return;
    if (!confirm('Batalkan pesanan ini? Stok akan dikembalikan ke paket.')) return;
    o.status = 'dibatalkan'; var p = findPaket(o.paket_id); if (p && p.selesai === o.ambil_selesai) p.stok += o.jumlah;
    toast('Pesanan dibatalkan.'); render();
  }
  function expire() {
    var changed = false, n = now();
    S.pesanan.forEach(function (o) {
      if (o.status === 'menunggu_pembayaran' && o.batas_bayar <= n) {
        o.status = 'kedaluwarsa'; changed = true;
        var p = findPaket(o.paket_id); if (p && p.selesai === o.ambil_selesai) p.stok += o.jumlah;
        toast('Pesanan ' + o.kode + ' kedaluwarsa karena belum dibayar.', true);
      } else if (o.status === 'siap_diambil' && o.ambil_selesai <= n) { o.status = 'tidak_diambil'; changed = true; }
    });
    if (changed) { render(); if ($('#modal').classList.contains('show')) closeModal(); }
  }
  function openUlasan(id) {
    var o = findOrd(id); if (!o) return; var val = 0;
    openModal('<div class="m-body"><h2>Beri ulasan</h2><p>' + esc(o.nama) + ' &middot; ' + esc(o.toko) + '</p>' +
      '<div class="field"><label>Rating</label><div class="stars" id="stars">' + [1, 2, 3, 4, 5].map(function (i) { return '<button type="button" data-v="' + i + '" aria-label="' + i + ' bintang">' + svg('star') + '</button>'; }).join('') + '</div></div>' +
      '<div class="field" style="margin-top:12px"><label for="uKom">Komentar (opsional)</label><textarea id="uKom" placeholder="Ceritakan isi paket, rasa, dan pelayanannya"></textarea></div>' +
      '<button class="btn btn-p btn-w" id="btnUl" style="margin-top:16px">Kirim ulasan</button></div>');
    var box = $('#mbox');
    box.onclick = function (e) {
      if (e.target.closest('[data-act="close"]')) return closeModal();
      var b = e.target.closest('#stars button'); if (!b) return;
      val = +b.getAttribute('data-v'); $$('#stars button').forEach(function (x) { x.classList.toggle('on', +x.getAttribute('data-v') <= val); });
    };
    $('#btnUl').onclick = function () {
      if (!val) return toast('Pilih rating 1 sampai 5 bintang dulu.', true);
      o.ulasan = { rating: val, komentar: $('#uKom').value.trim() }; closeModal(); toast('Terima kasih, ulasanmu tersimpan.'); render();
    };
  }
  function openLapor(id) {
    var o = findOrd(id); if (!o) return;
    openModal('<div class="m-body"><h2>Laporkan masalah</h2><p>Laporan dikirim ke admin ReTaste untuk ditinjau. Pesanan: ' + esc(o.kode) + '</p>' +
      '<div class="field"><label for="lJenis">Jenis masalah</label><select id="lJenis"><option value="isi_tidak_sesuai">Isi tidak sesuai kategori / foto / deskripsi</option><option value="tidak_layak">Makanan tidak layak konsumsi</option><option value="alergen">Alergen tidak sesuai informasi</option><option value="penjual_tidak_melayani">Penjual tidak melayani</option><option value="lainnya">Lainnya</option></select></div>' +
      '<div class="field" style="margin-top:12px"><label for="lAlasan">Alasan (wajib)</label><textarea id="lAlasan" placeholder="Jelaskan apa yang terjadi"></textarea></div>' +
      '<div class="field" style="margin-top:12px"><label for="lFoto">Foto (opsional, maks 2 MB)</label><input id="lFoto" type="file" accept="image/jpeg,image/png"></div>' +
      '<button class="btn btn-p btn-w" id="btnLp" style="margin-top:16px">Kirim laporan</button></div>');
    $('#mbox').onclick = function (e) { if (e.target.closest('[data-act="close"]')) closeModal(); };
    $('#btnLp').onclick = function () {
      var al = $('#lAlasan').value.trim(), f = $('#lFoto').files[0];
      if (!al) return toast('Alasan wajib diisi.', true);
      if (f && (f.size > 2 * 1024 * 1024 || !/^image\/(jpeg|png)$/.test(f.type))) return toast('Foto harus JPG/PNG dan maksimal 2 MB.', true);
      o.dilaporkan = true; closeModal(); toast('Laporan terkirim. Admin akan meninjaunya.'); render();
    };
  }
  /* ---------- event ---------- */
  document.addEventListener('click', function (e) {
    var g = e.target.closest('[data-go]'); if (g) { go(g.getAttribute('data-go')); return; }
    var a = e.target.closest('[data-act]'); if (!a) return;
    var act = a.getAttribute('data-act'), id = +a.getAttribute('data-id');
    if (act === 'close') closeModal();
    else if (act === 'paket') openPaket(id);
    else if (act === 'bayar') openBayar(id);
    else if (act === 'kode') openKode(id);
    else if (act === 'batal') batal(id);
    else if (act === 'ulasan') openUlasan(id);
    else if (act === 'lapor') openLapor(id);
    else if (act === 'verif') toast('Tautan verifikasi dikirim ke email kampusmu (simulasi).');
    else if (act === 'reset') { S.filter = { kat: 0, harga: 0, jam: 0, q: '', urut: 'terbaru' }; render(); }
    else if (act === 'tab') { S[a.getAttribute('data-key')] = a.getAttribute('data-v'); render(); }
    else if (act === 'fav') {
      var i = S.fav.indexOf(id); if (i > -1) { S.fav.splice(i, 1); toast('Dihapus dari toko favorit.'); } else { S.fav.push(id); toast('Ditambahkan ke toko favorit.'); }
      lsSet('rt_fav', S.fav); render();
    }
  });
  document.addEventListener('input', function (e) {
    if (!e.target.matches('[data-f="q"]')) return;
    var cursor = e.target.selectionStart;
    S.filter.q = e.target.value;
    render();
    var search = $('#fCari');
    search.focus();
    search.setSelectionRange(cursor, cursor);
  });
  document.addEventListener('change', function (e) {
    var f = e.target.getAttribute && e.target.getAttribute('data-f');
    if (f) { S.filter[f] = (f === 'urut' || f === 'q') ? e.target.value : +e.target.value; render(); }
  });
  document.addEventListener('submit', function (e) {
    e.preventDefault();
    if (e.target.id === 'fProfil') {
      var n = $('#pNama').value.trim(); if (!n) return toast('Nama tidak boleh kosong.', true);
      S.user.nama = n; S.user.no_telp = $('#pTelp').value.trim(); S.user.email_kampus = $('#pKampus').value.trim(); toast('Profil disimpan.'); render();
    }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeModal(); closeSide(); } });
  $('#modal').addEventListener('mousedown', function (e) { if (e.target.id === 'modal') closeModal(); });
  $('#burger').addEventListener('click', openSide);
  $('#scrim').addEventListener('click', closeSide);

  /* ---------- jam & hitung mundur ---------- */
  function tick() {
    var d = new Date(now() * 1000);
    $('#clkT').textContent = d.toLocaleTimeString('id-ID', { hour12: false, timeZone: TZ }).replace(/\./g, ':');
    $('#clkD').textContent = d.toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric', timeZone: TZ });
    $$('[data-cd]').forEach(function (el) { el.textContent = mmss(+el.getAttribute('data-cd') - now()); });
    expire();
  }

  /* ---------- mulai ---------- */
  buildNav(); fillIcons(document);
  var start = (location.hash || '').replace('#', '');
  go(TITLES[start] ? start : 'dashboard');
  loadPackages();
  tick(); setInterval(tick, 1000);
})();
</script>
</body>
</html>
