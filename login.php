<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

const DASHBOARD = ['pembeli' => 'dashboard_pelanggan.php', 'penjual' => 'dashboard_penjual.php', 'admin' => 'dashboard_admin.php'];
const ROLE_LABEL = ['pembeli' => 'Pembeli', 'penjual' => 'Penjual', 'admin' => 'Admin'];

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
function csrf_ok(): bool { return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']); }
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------- Penyimpanan pengguna di tabel users ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) { require __DIR__ . '/koneksi.php'; /* harus menghasilkan variabel $pdo (PDO, mode ERRMODE_EXCEPTION) */ }
    if (!$pdo instanceof PDO) { throw new RuntimeException('Koneksi database tidak menghasilkan objek PDO.'); }
    return $pdo;
}
function cari_user(string $email): ?array {
    $email = strtolower($email);
    $st = db()->prepare('SELECT id_user AS id, nama, email, no_telp AS telp, password AS hash, role, (status_akun = "aktif") AS aktif FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function buat_user(string $nama, string $email, string $telp, string $password, string $role): array {
    $email = strtolower($email); $hash = password_hash($password, PASSWORD_DEFAULT);
    $st = db()->prepare('INSERT INTO users (nama, email, no_telp, password, role) VALUES (?, ?, ?, ?, ?)');
    $st->execute([$nama, $email, $telp !== '' ? $telp : null, $hash, $role]);
    return ['id' => (int)db()->lastInsertId(), 'nama' => $nama, 'email' => $email, 'telp' => $telp, 'hash' => $hash, 'role' => $role, 'aktif' => 1];
}
function masuk_session(array $u): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id']; $_SESSION['nama'] = $u['nama']; $_SESSION['email'] = $u['email']; $_SESSION['role'] = $u['role'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function normalisasi_hp(string $v): string { return preg_replace('/[\s\-().]/', '', $v); }
function hp_valid(string $v): bool { return (bool)preg_match('/^(\+62|62|0)8[0-9]{8,12}$/', normalisasi_hp($v)); }

/* Sudah login? langsung ke dashboard sesuai peran */
if (!empty($_SESSION['user_id']) && isset(DASHBOARD[$_SESSION['role'] ?? ''])) {
    header('Location: ' . DASHBOARD[$_SESSION['role']]); exit;
}

/* ---------- Status awal ---------- */
$qrole  = $_GET['role'] ?? 'pembeli';
$rrole  = in_array($qrole, ['pembeli', 'penjual'], true) ? $qrole : 'pembeli';    /* peran di form daftar  */
$mode   = (($_GET['mode'] ?? '') === 'daftar') ? 'daftar' : 'login';

$email = ''; $error = ''; $info = ''; $errs = [];                                   /* form masuk  */
$rnama = ''; $remail = ''; $rhp = ''; $rerror = ''; $rerrs = [];                    /* form daftar */
if (isset($_GET['daftar'])) { $info = 'Akun berhasil dibuat. Silakan masuk.'; }
if (isset($_GET['keluar'])) { $info = 'Kamu sudah keluar dari akun.'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (($_POST['aksi'] ?? 'login') === 'daftar') ? 'daftar' : 'login';

    if ($aksi === 'login') {
        /* ===== PROSES MASUK ===== */
        $email = trim((string)($_POST['email'] ?? ''));
        $pass  = (string)($_POST['password'] ?? '');

        /* batas percobaan: 5 kali gagal -> tunggu 60 detik */
        $_SESSION['gagal'] = array_filter($_SESSION['gagal'] ?? [], fn($t) => $t > time() - 60);

        if (!csrf_ok()) {
            $error = 'Sesi formulir sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.';
        } elseif (count($_SESSION['gagal']) >= 5) {
            $error = 'Terlalu banyak percobaan gagal. Tunggu 1 menit lalu coba lagi.';
        } else {
            if ($email === '') { $errs['email'] = 'Email wajib diisi.'; }
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errs['email'] = 'Format email belum benar.'; }
            if ($pass === '') { $errs['password'] = 'Password wajib diisi.'; }

            if (!$errs) {
                try {
                    $u = cari_user($email);
                    if (!$u || !password_verify($pass, $u['hash'])) {
                        $_SESSION['gagal'][] = time();
                        $error = 'Email atau password salah.';
                    } elseif (!(int)$u['aktif']) {
                        $error = 'Akun ini dinonaktifkan oleh admin. Hubungi admin ReTaste untuk bantuan.';
                    } elseif (!isset(DASHBOARD[$u['role']])) {
                        error_log('Login rejected: invalid role for user ID ' . (int)$u['id']);
                        $error = 'Peran akun tidak valid. Hubungi admin ReTaste untuk bantuan.';
                    } else {
                        $_SESSION['gagal'] = [];
                        masuk_session($u);
                        header('Location: ' . DASHBOARD[$u['role']]); exit;
                    }
                } catch (Throwable $ex) {
                    error_log('Login database error: ' . $ex->getMessage());
                    $error = 'Login tidak dapat diproses karena koneksi database bermasalah. Coba lagi nanti.';
                }
            }
        }
    } else {
        /* ===== PROSES DAFTAR ===== */
        $mode   = 'daftar';
        $rrole  = in_array($_POST['role'] ?? '', ['pembeli', 'penjual'], true) ? $_POST['role'] : 'pembeli';
        $rnama  = trim((string)($_POST['nama'] ?? ''));
        $remail = trim((string)($_POST['email'] ?? ''));
        $rhp    = trim((string)($_POST['hp'] ?? ''));
        $p1     = (string)($_POST['password'] ?? '');
        $p2     = (string)($_POST['password2'] ?? '');

        if (!csrf_ok()) {
            $rerror = 'Sesi formulir sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.';
        } else {
            if ($rnama === '') { $rerrs['nama'] = 'Nama lengkap wajib diisi.'; }
            elseif (mb_strlen($rnama) < 3) { $rerrs['nama'] = 'Nama minimal 3 karakter.'; }
            elseif (mb_strlen($rnama) > 80) { $rerrs['nama'] = 'Nama maksimal 80 karakter.'; }

            if ($remail === '') { $rerrs['email'] = 'Email wajib diisi.'; }
            elseif (!filter_var($remail, FILTER_VALIDATE_EMAIL)) { $rerrs['email'] = 'Format email belum benar.'; }
            elseif (strlen($remail) > 100) { $rerrs['email'] = 'Email maksimal 100 karakter.'; }
            else {
                $remail = strtolower($remail);
                try {
                    if (cari_user($remail)) { $rerrs['email'] = 'Email ini sudah terdaftar. Silakan masuk.'; }
                } catch (Throwable $ex) {
                    error_log('Registration database lookup error: ' . $ex->getMessage());
                    $rerror = 'Pendaftaran tidak dapat diproses karena koneksi database bermasalah. Coba lagi nanti.';
                }
            }

            if ($rhp !== '' && !hp_valid($rhp)) { $rerrs['hp'] = 'Nomor HP belum benar (contoh 081234567890).'; }

            if ($p1 === '') { $rerrs['password'] = 'Password wajib diisi.'; }
            elseif (strlen($p1) < 8) { $rerrs['password'] = 'Password minimal 8 karakter.'; }

            if ($p2 === '') { $rerrs['password2'] = 'Ulangi password.'; }
            elseif ($p1 !== $p2) { $rerrs['password2'] = 'Password tidak sama.'; }

            if (!$rerrs) {
                try {
                    buat_user($rnama, $remail, $rhp !== '' ? normalisasi_hp($rhp) : '', $p1, $rrole);
                    header('Location: login.php?daftar=1&role=' . $rrole); exit;
                } catch (Throwable $ex) {
                    error_log('Registration database error: ' . $ex->getMessage());
                    $rerror = $ex instanceof PDOException && $ex->getCode() === '23000' && (int)($ex->errorInfo[1] ?? 0) === 1062
                        ? 'Email ini sudah terdaftar. Silakan masuk.'
                        : 'Pendaftaran tidak dapat diproses karena koneksi database bermasalah. Coba lagi nanti.';
                }
            }
        }
    }
}

$sub = 'Masuk ke ReTaste untuk melanjutkan.';
$rsub = [
    'pembeli' => 'Daftar gratis dan mulai berburu makanan hemat dari toko di sekitarmu.',
    'penjual' => 'Daftar gratis dan ubah sisa makanan tokomu jadi pemasukan, bukan sampah.',
];
$isReg = ($mode === 'daftar');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $isReg ? 'Daftar' : 'Masuk' ?> &ndash; ReTaste</title>
<meta name="description" content="Masuk atau daftar ke ReTaste sebagai pembeli atau penjual.">
<link rel="icon" type="image/png" href="assets/img/favicon.png">
<link rel="preload" href="assets/fonts/baloo-2-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>
<style>
@font-face{font-family:"Baloo 2";font-weight:700;font-display:swap;src:url("assets/fonts/baloo-2-latin-700-normal.woff2") format("woff2")}
@font-face{font-family:"Baloo 2";font-weight:800;font-display:swap;src:url("assets/fonts/baloo-2-latin-800-normal.woff2") format("woff2")}
@font-face{font-family:"Nunito";font-weight:400;font-display:swap;src:url("assets/fonts/nunito-latin-400-normal.woff2") format("woff2")}
@font-face{font-family:"Nunito";font-weight:600;font-display:swap;src:url("assets/fonts/nunito-latin-600-normal.woff2") format("woff2")}
@font-face{font-family:"Nunito";font-weight:700;font-display:swap;src:url("assets/fonts/nunito-latin-700-normal.woff2") format("woff2")}
:root{
  --amber:#F7A503;--wheat:#FFDA8C;--cream:#FFEBBC;--olive:#B3BC53;--moss:#768C3A;
  --dark:#2E3A12;--dark2:#4A5C22;--dark3:#1D2509;--ink:#2E3A12;--muted:#5E6B3F;--faint:#8A936A;
  --bg:#FFFBEF;--tint:#FBF1D2;--border:#E8DDB8;--red:#C8432B;--red-bg:#FCE9E4;--green:#4F6A1F;--green-bg:#E8F3D6;--amber-bg:#FFF1CC;
  --head:"Baloo 2",system-ui,sans-serif;--body:"Nunito",system-ui,sans-serif;
  --ease:cubic-bezier(.77,0,.18,1);--dur:.85s;
}
*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{font-family:var(--body);color:var(--ink);background:var(--bg);line-height:1.5;min-height:100vh}
a{color:var(--dark2)}
:focus-visible{outline:3px solid var(--amber);outline-offset:3px;border-radius:8px}

/* ===== KERANGKA: gambar 52% + form 48% (desktop, satu layar penuh tanpa scroll) ===== */
.auth{position:relative;height:100vh;height:100dvh;overflow:hidden;background:var(--bg)}

/* panel gambar: bergeser dari kiri (mode masuk) ke kanan (mode daftar) */
.side{position:absolute;top:0;bottom:0;left:0;width:52%;z-index:3;color:#fff;display:flex;flex-direction:column;justify-content:space-between;padding:30px 44px;overflow:hidden;background:var(--dark2);transition:transform var(--dur) var(--ease)}
.auth.reg .side{transform:translateX(92.3077%)}           /* 48 / 52 = 92.3077% -> tepat sebesar lebar panel form */
.side>img.bgimg{
  position:absolute;
  inset:0;
  width:100%;
  height:100%;
  object-fit:cover;
  object-position:60% 50%;
  z-index:0;
}.side::before{
  content:"";
  position:absolute;
  inset:0;
  background: rgba(0, 0, 0, 0.50);
  z-index: 1;
}
.side>*:not(.bgimg){
  position:relative;
  z-index:2;
}.brand{display:inline-flex;align-items:center;gap:12px;text-decoration:none;color:#fff;width:fit-content}
.brand .mk{width:52px;height:52px;border-radius:15px;background:#fff;display:grid;place-items:center;box-shadow:0 8px 20px -8px rgba(0,0,0,.5)}
.brand .mk img{width:38px;height:38px;object-fit:contain}
.brand b{font-family:var(--head);font-size:1.7rem;line-height:1;font-weight:800}
.brand b span{color:var(--amber)}
.brand small{display:block;font-size:.68rem;letter-spacing:1.4px;text-transform:uppercase;opacity:.85;font-weight:700;margin-top:2px}
.tagm{display:none;font-weight:700;margin-top:8px;font-size:.9rem;color:rgba(255,255,255,.9)}

/* dua teks di panel gambar (masuk / daftar) saling berganti */
.txts{display:grid}
.txts>div{grid-area:1/1;transition:opacity .5s ease,visibility 0s}
.txts .t-reg{opacity:0;visibility:hidden}
.auth.reg .txts .t-login{opacity:0;visibility:hidden}
.auth.reg .txts .t-reg{opacity:1;visibility:visible;transition-delay:.25s}
.txts .t-login{transition-delay:.25s}
.auth.reg .txts .t-login{transition-delay:0s}
.side h2{font-family:var(--head);font-size:clamp(1.9rem,3.2vw,2.9rem);line-height:1.05;font-weight:800;max-width:14ch}
.side h2 em{font-style:normal;color:var(--amber)}
.side p{margin-top:12px;max-width:44ch;color:rgba(255,255,255,.92);font-weight:600}
.pts{list-style:none;display:grid;gap:10px;margin-top:20px}
.pts li{display:flex;gap:10px;align-items:center;font-weight:700;font-size:.95rem}
.pts svg{width:26px;height:26px;padding:5px;border-radius:50%;background:rgba(247,165,3,.28);color:var(--amber);flex-shrink:0}
.side h2,.side p,.pts,.brand,.tagm{text-shadow:0 2px 12px rgba(0,0,0,.55),0 1px 2px rgba(0,0,0,.4)}

/* panel form: dua buah, yang aktif muncul, yang lain memudar */
.pane{position:absolute;top:0;bottom:0;width:48%;z-index:1;display:flex;padding:20px 28px;overflow-y:auto;
  background:radial-gradient(520px 320px at 100% 0%,rgba(255,218,140,.45),transparent 70%),var(--bg);
  opacity:0;visibility:hidden;transform:translateX(var(--shift));
  transition:opacity .4s ease,transform var(--dur) var(--ease),visibility 0s var(--dur)}
.p-login{left:52%;--shift:70px}
.p-reg{left:0;--shift:-70px}
.auth:not(.reg) .p-login,.auth.reg .p-reg{opacity:1;visibility:visible;transform:none;transition:opacity .55s ease .3s,transform var(--dur) var(--ease),visibility 0s}
.card{width:100%;max-width:440px;margin:auto}

.back{display:inline-flex;align-items:center;gap:6px;font-weight:800;font-size:.86rem;text-decoration:none;color:var(--muted);margin-bottom:12px}
.back svg{width:16px;height:16px}
.back:hover{color:var(--dark)}
.card h1{font-family:var(--head);font-weight:800;font-size:1.85rem;line-height:1.1;color:var(--dark)}
.sub{color:var(--muted);font-weight:600;font-size:.95rem;margin:4px 0 14px;min-height:2.9em}

.roles{display:grid;gap:8px;margin-bottom:14px}
.roles.r3{grid-template-columns:repeat(3,1fr)}.roles.r2{grid-template-columns:1fr 1fr}
.role{position:relative}
.role input{position:absolute;opacity:0;inset:0;cursor:pointer;width:100%;height:100%}
.role span{display:flex;flex-direction:column;align-items:center;gap:2px;text-align:center;padding:9px 8px;border-radius:14px;border:2px solid var(--border);background:#fff;font-weight:800;font-size:.92rem;color:var(--muted);transition:.15s}
.role span svg{width:22px;height:22px;color:var(--moss)}
.role span small{font-weight:600;font-size:.72rem;color:var(--faint);line-height:1.25}
.role:hover span{border-color:var(--olive)}
.role input:checked+span{border-color:var(--moss);background:var(--dark);color:#fff;box-shadow:0 10px 22px -12px rgba(46,58,18,.8)}
.role input:checked+span svg{color:var(--amber)}
.role input:checked+span small{color:rgba(255,255,255,.75)}
.role input:focus-visible+span{outline:3px solid var(--amber);outline-offset:2px}

.field{display:grid;gap:4px;margin-bottom:11px;align-content:start}
.field label{font-weight:800;font-size:.84rem;color:var(--dark)}
.field label i{font-style:normal;color:var(--faint);font-weight:700}
.in{position:relative}
.in>svg{position:absolute;left:13px;top:50%;transform:translateY(-50%);width:19px;height:19px;color:var(--moss);pointer-events:none}
.in input{width:100%;padding:11px 14px 11px 42px;border-radius:13px;border:2px solid var(--border);background:#fff;font:600 .98rem var(--body);color:var(--dark);transition:.15s}
.in input::placeholder{color:#B5B28F;font-weight:600}
.in input:hover{border-color:var(--olive)}
.in input:focus{outline:0;border-color:var(--moss);box-shadow:0 0 0 4px rgba(179,188,83,.28)}
.in input.pw{padding-right:48px}
.eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:36px;height:36px;border:0;background:transparent;border-radius:10px;color:var(--muted);cursor:pointer;display:grid;place-items:center}
.eye:hover{background:var(--tint)}
.eye svg{position:static;transform:none;width:20px;height:20px;color:inherit}
.field.err input{border-color:var(--red);box-shadow:0 0 0 4px rgba(200,67,43,.12)}
.em{font-size:.76rem;color:var(--red);font-weight:800;display:none;line-height:1.3}
.field.err .em{display:block}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start}
.alert{display:flex;gap:10px;align-items:flex-start;padding:10px 13px;border-radius:13px;font-weight:700;font-size:.86rem;margin-bottom:12px;border:1px solid transparent}
.alert svg{width:20px;height:20px;flex-shrink:0;margin-top:1px}
.alert.bad{background:var(--red-bg);border-color:#F0C3B9;color:var(--red)}
.alert.ok{background:var(--green-bg);border-color:#CDE3A8;color:var(--green)}

.btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:13px 22px;border-radius:999px;border:0;background:var(--amber);color:#2B1D00;font:800 1.02rem var(--body);cursor:pointer;box-shadow:0 12px 22px -12px rgba(247,165,3,.95);transition:transform .15s,background .15s;margin-top:6px}
.btn:hover{background:#FFB41F;transform:translateY(-2px)}
.btn:disabled{opacity:.7;cursor:progress;transform:none}
.btn svg{width:18px;height:18px}
.spin{width:18px;height:18px;border-radius:50%;border:3px solid rgba(43,29,0,.25);border-top-color:#2B1D00;animation:sp .7s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}
.alt{text-align:center;margin-top:14px;font-weight:700;color:var(--muted);font-size:.92rem}
.alt a{font-weight:800}

/* kotak akun contoh (mode demo), dipadatkan satu baris */
.demo{margin-top:14px;padding:10px 12px;border-radius:14px;background:#fff;border:1px dashed var(--olive)}
.demo h3{font-family:var(--head);font-size:.88rem;color:var(--dark);margin-bottom:6px}
.chips{display:flex;gap:6px}
.demo button{flex:1;padding:6px 8px;border-radius:10px;border:1px solid var(--border);background:var(--bg);font:800 .8rem var(--body);color:var(--dark);cursor:pointer}
.demo button:hover{border-color:var(--moss);background:var(--tint)}
.demo p{font-size:.72rem;color:var(--faint);font-weight:700;margin-top:6px}


@media(max-height:820px){
  .side{padding:24px 40px}
  .pane{padding:12px 28px}
  .back{margin-bottom:12px}
  .card h1{font-size:1.75rem}
  .sub{margin:5px 0 16px}
  .roles{margin-bottom:16px}
  .role span small{display:none}
  .field{margin-bottom:13px}
  .in input{padding:11px 14px 11px 42px}
  .btn{padding:13px 22px}
  .alt{margin-top:14px}
  .demo{margin-top:13px;padding:10px 12px}
}
@media(max-height:620px){.sub{display:none}.demo p{display:none}.pts{display:none}}

/* ===== HP / tablet: tanpa geser, form aktif saja, boleh scroll ===== */
@media(max-width:900px){
  .auth{height:auto;min-height:100vh;overflow:visible;display:flex;flex-direction:column}
  .side,.auth.reg .side{position:relative;width:auto;transform:none;transition:none;padding:20px 22px 22px}
  .txts{display:none}
  .tagm{display:block}
  .pane,.auth.reg .pane{position:relative;left:auto;width:auto;transform:none;transition:none;padding:26px 18px 40px;overflow:visible;opacity:1;visibility:visible;display:none}
  .auth:not(.reg) .p-login,.auth.reg .p-reg{display:flex}
  .card{margin:0 auto}
  .sub{display:block;min-height:0}
  .role span small{display:block}
  .pts{display:grid}
}
@media(max-width:480px){.row2{grid-template-columns:1fr}.roles.r3 .role span small{display:none}}
@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
/* Akun demo tidak ditampilkan pada halaman login berbasis SQL. */
.demo {
    display: none !important;
}
/* ANIMASI PINDAH LOGIN DAN REGISTER */
#auth {
    will-change: transform, opacity;
}

@keyframes masukDariKanan {
    from {
        opacity: 0;
        transform: translateX(70px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes masukDariKiri {
    from {
        opacity: 0;
        transform: translateX(-70px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes keluarKeKiri {
    to {
        opacity: 0;
        transform: translateX(-70px);
    }
}

@keyframes keluarKeKanan {
    to {
        opacity: 0;
        transform: translateX(70px);
    }
}

html[data-animasi="kanan"] #auth {
    animation: masukDariKanan .4s ease both;
}

html[data-animasi="kiri"] #auth {
    animation: masukDariKiri .4s ease both;
}

#auth.geser-kiri {
    animation: keluarKeKiri .35s ease forwards !important;
}

#auth.geser-kanan {
    animation: keluarKeKanan .35s ease forwards !important;
}
</style>
</head>
<body>
<div class="auth<?= $isReg ? ' reg' : '' ?>" id="auth">

  <!-- ===== PANEL GAMBAR: bergeser kiri <-> kanan ===== -->
  <aside class="side">
    <!-- foto latar disematkan langsung (tidak perlu file terpisah) -->
    <img class="bgimg" alt="" src="data:image/jpeg;base64,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">
    <div>
      <a href="index.php" class="brand" aria-label="ReTaste - ke halaman utama">
        <span class="mk"><img src="assets/img/favicon.png" alt="Logo ReTaste"></span>
        <span><b>Re<span>Taste</span></b><small>Good food. New chances.</small></span>
      </a>
      <div class="tagm">Selamatkan makanan, hemat uang.</div>
    </div>

    <div class="txts">
      <div class="t-login">
        <h2>Good Food. <em>New Chances.</em></h2>
        <p>Makanan layak dari toko sekitar yang belum terjual hari ini, kini dapat kesempatan kedua di mejamu.</p>
        <ul class="pts">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 12.5 4.5 4.5 10.5-10.5"/></svg>Diskon minimal 50% dari harga normal</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 12.5 4.5 4.5 10.5-10.5"/></svg>Ambil sendiri dengan kode pengambilan</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 12.5 4.5 4.5 10.5-10.5"/></svg>Kurangi sampah makanan bersama-sama</li>
        </ul>
      </div>
      <div class="t-reg">
        <h2>Bergabung, <em>selamatkan</em> makanan.</h2>
        <p>Satu akun untuk berburu paket hemat, atau untuk toko yang ingin hasil jualannya tidak terbuang.</p>
        <ul class="pts">
          <li><svg data-i="tag" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"></svg>Harga paket hemat, diskon minimal 50%</li>
          <li><svg data-i="pin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"></svg>Temukan toko terdekat dari lokasimu</li>
          <li><svg data-i="leaf" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"></svg>Setiap pesanan ikut mengurangi sampah makanan</li>
        </ul>
      </div>
    </div>
  </aside>

  <!-- ===== FORM MASUK (kanan) ===== -->
  <main class="pane p-login"<?= $isReg ? ' aria-hidden="true" inert' : '' ?>>
    <div class="card">
      <a class="back" href="index.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m11 18-6-6 6-6"/></svg>Kembali ke beranda</a>
      <h1>Selamat datang kembali</h1>
      <p class="sub" id="sub"><?= e($sub) ?></p>

      <?php if ($error): ?><div class="alert bad" role="alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4 2.5 20h19L12 4z"/><path d="M12 10v4M12 17.2v.1"/></svg><div><?= e($error) ?></div></div><?php endif; ?>
      <?php if ($info): ?><div class="alert ok" role="status"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 12.5 4.5 4.5 10.5-10.5"/></svg><div><?= e($info) ?></div></div><?php endif; ?>

      <form id="f" method="post" action="login.php" novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="aksi" value="login">

        <div class="field<?= isset($errs['email']) ? ' err' : '' ?>">
          <label for="email">Email</label>
          <div class="in"><svg data-i="mail" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></svg><input id="email" name="email" type="email" autocomplete="email" inputmode="email" placeholder="nama@email.com" value="<?= e($email) ?>" required></div>
          <span class="em" id="emailE"><?= e($errs['email'] ?? '') ?></span>
        </div>

        <div class="field<?= isset($errs['password']) ? ' err' : '' ?>">
          <label for="pw">Password</label>
          <div class="in"><svg data-i="lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></svg><input id="pw" class="pw" name="password" type="password" autocomplete="current-password" placeholder="Masukkan password" required><button type="button" class="eye" id="eye" aria-label="Tampilkan password" aria-pressed="false"><svg data-i="eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></svg></button></div>
          <span class="em" id="pwE"><?= e($errs['password'] ?? '') ?></span>
        </div>

        <button class="btn" type="submit" id="go">Masuk <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg></button>
      </form>

      <p class="alt" id="alt">
          Belum punya akun?
          <a href="register.php" id="toReg">Daftar sekarang</a>
      </p>
    </div>
  </main>

  <!-- ===== FORM DAFTAR (kiri) ===== -->
  <section class="pane p-reg"<?= $isReg ? '' : ' aria-hidden="true" inert' ?>>
    <div class="card">
      <a class="back" href="index.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m11 18-6-6 6-6"/></svg>Kembali ke beranda</a>
      <h1>Buat akun ReTaste</h1>
      <p class="sub" id="rsub"><?= e($rsub[$rrole]) ?></p>

      <?php if ($rerror): ?><div class="alert bad" role="alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4 2.5 20h19L12 4z"/><path d="M12 10v4M12 17.2v.1"/></svg><div><?= e($rerror) ?></div></div><?php endif; ?>

      <form id="fr" method="post" action="login.php" novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="aksi" value="daftar">

        <div class="roles r2" role="radiogroup" aria-label="Daftar sebagai">
          <?php foreach ([['pembeli','user','Cari & pesan paket hemat'],['penjual','store','Jual sisa makanan toko']] as $r): ?>
          <label class="role"><input type="radio" name="role" value="<?= $r[0] ?>" <?= $rrole === $r[0] ? 'checked' : '' ?>><span><svg data-i="<?= $r[1] ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></svg><?= ROLE_LABEL[$r[0]] ?><small><?= $r[2] ?></small></span></label>
          <?php endforeach; ?>
        </div>

        <div class="row2">
          <div class="field<?= isset($rerrs['nama']) ? ' err' : '' ?>">
            <label for="rn">Nama lengkap</label>
            <div class="in"><svg data-i="user" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></svg><input id="rn" name="nama" type="text" autocomplete="name" placeholder="Nama kamu" value="<?= e($rnama) ?>" required></div>
            <span class="em"><?= e($rerrs['nama'] ?? '') ?></span>
          </div>
          <div class="field<?= isset($rerrs['hp']) ? ' err' : '' ?>">
            <label for="rh">Nomor HP <i>(opsional)</i></label>
            <div class="in"><svg data-i="phone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></svg><input id="rh" name="hp" type="tel" autocomplete="tel" inputmode="tel" placeholder="081234567890" value="<?= e($rhp) ?>"></div>
            <span class="em"><?= e($rerrs['hp'] ?? '') ?></span>
          </div>
        </div>

        <div class="field<?= isset($rerrs['email']) ? ' err' : '' ?>">
          <label for="re">Email</label>
          <div class="in"><svg data-i="mail" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></svg><input id="re" name="email" type="email" autocomplete="email" inputmode="email" placeholder="nama@email.com" value="<?= e($remail) ?>" required></div>
          <span class="em"><?= e($rerrs['email'] ?? '') ?></span>
        </div>

        <div class="row2">
          <div class="field<?= isset($rerrs['password']) ? ' err' : '' ?>">
            <label for="rp">Password</label>
            <div class="in"><svg data-i="lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></svg><input id="rp" class="pw" name="password" type="password" autocomplete="new-password" placeholder="Min. 8 karakter" required><button type="button" class="eye" id="eye2" aria-label="Tampilkan password" aria-pressed="false"><svg data-i="eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></svg></button></div>
            <span class="em"><?= e($rerrs['password'] ?? '') ?></span>
          </div>
          <div class="field<?= isset($rerrs['password2']) ? ' err' : '' ?>">
            <label for="rp2">Ulangi password</label>
            <div class="in"><svg data-i="lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></svg><input id="rp2" name="password2" type="password" autocomplete="new-password" placeholder="Ketik ulang" required></div>
            <span class="em"><?= e($rerrs['password2'] ?? '') ?></span>
          </div>
        </div>

        <button class="btn" type="submit" id="go2">Buat akun <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg></button>
      </form>

      <p class="alt">Sudah punya akun? <a href="login.php?role=<?= e($rrole) ?>" id="toLogin">Masuk</a></p>
    </div>
  </section>
</div>

<script>
(function(){
'use strict';
var IC={
 user:'<circle cx="12" cy="8" r="4"/><path d="M4 21c.8-4 4-6 8-6s7.2 2 8 6"/>',
 store:'<path d="M4 9.5 5.5 4h13L20 9.5"/><path d="M4 9.5a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0 2.7 2.7 0 0 0 5.3 0"/><path d="M5.5 12.5V20h13v-7.5"/>',
 shield:'<path d="M12 3.5 5 6v5.5c0 4.2 2.9 7.4 7 9 4.1-1.6 7-4.8 7-9V6l-7-2.5z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
 mail:'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
 lock:'<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
 phone:'<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
 eye:'<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
 eyeoff:'<path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4M6.6 6.6A17 17 0 0 0 2 12s3.6 7 10 7a9.5 9.5 0 0 0 4.2-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
 leaf:'<path d="M5 19c0-8 5-13 14-14 0 9-5 14-13 14"/><path d="M5 19c2-4 5-7 9-9"/>',
 tag:'<path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
 pin:'<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>'
};
var RSUB={
  pembeli:'Daftar gratis dan mulai berburu makanan hemat dari toko di sekitarmu.',
  penjual:'Daftar gratis dan ubah sisa makanan tokomu jadi pemasukan, bukan sampah.'
};
var $=function(s,r){return(r||document).querySelector(s)},$$=function(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s))};
function svg(n){return IC[n]||''}
$$('[data-i]').forEach(function(el){el.innerHTML=svg(el.getAttribute('data-i'))});

var A=$('#auth'),PL=$('.p-login'),PR=$('.p-reg');
var f=$('#f'),em=$('#email'),pw=$('#pw');
var fr=$('#fr'),rn=$('#rn'),rh=$('#rh'),re=$('#re'),rp=$('#rp'),rp2=$('#rp2');
function rrole(){return $('#fr input[name=role]:checked').value}
function isReg(){return A.classList.contains('reg')}

/* ---------- pindah masuk <-> daftar (panel gambar bergeser, form bertukar tempat) ---------- */
function urlFor(m){return m==='daftar'?'login.php?mode=daftar&role='+rrole():'login.php'}
function setMode(m,push){
  var reg=m==='daftar';
  if(reg===isReg())return;
  A.classList.toggle('reg',reg);
  PL.inert=reg;PR.inert=!reg;
  PL.setAttribute('aria-hidden',reg);PR.setAttribute('aria-hidden',!reg);
  document.title=(reg?'Daftar':'Masuk')+' \u2013 ReTaste';
  try{history[push?'pushState':'replaceState'](null,'',urlFor(m))}catch(e){}
  var wide=!matchMedia('(max-width:900px)').matches;
  setTimeout(function(){try{(reg?rn:em).focus({preventScroll:true})}catch(e){}},wide?650:50);
}
$('#toReg').addEventListener('click',function(e){
  if(e.ctrlKey||e.metaKey||e.shiftKey||e.button!==0)return;
  e.preventDefault();setMode('daftar',true);
});
$('#toLogin').addEventListener('click',function(e){
  if(e.ctrlKey||e.metaKey||e.shiftKey||e.button!==0)return;
  e.preventDefault();
  setMode('login',true);
});
window.addEventListener('popstate',function(){
  var m=new URLSearchParams(location.search).get('mode')==='daftar'?'daftar':'login';
  setMode(m,false);
});

function applyRRole(skipUrl){
  var r=rrole();
  $('#rsub').textContent=RSUB[r];
  $('#toLogin').href='login.php?role='+r;
  if(skipUrl!==true&&isReg()){try{history.replaceState(null,'','login.php?mode=daftar&role='+r)}catch(e){}}
}
$$('#fr input[name=role]').forEach(function(i){i.addEventListener('change',function(){applyRRole()})});
applyRRole(true);

/* ---------- tampil / sembunyikan password ---------- */
function eyeToggle(btn,inputs){
  btn.addEventListener('click',function(){
    var show=inputs[0].type==='password';
    inputs.forEach(function(i){i.type=show?'text':'password'});
    this.setAttribute('aria-pressed',show);this.setAttribute('aria-label',show?'Sembunyikan password':'Tampilkan password');
    this.firstElementChild.innerHTML=svg(show?'eyeoff':'eye');
  });
}
eyeToggle($('#eye'),[pw]);
eyeToggle($('#eye2'),[rp,rp2]);

/* ---------- validasi inline ---------- */
function setErr(inp,msg){
  var fld=inp.closest('.field');fld.classList.toggle('err',!!msg);
  fld.querySelector('.em').textContent=msg||'';
  inp.setAttribute('aria-invalid',msg?'true':'false');
  return !msg;
}
var RE_MAIL=/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
function vMail(inp){var v=inp.value.trim();return setErr(inp,!v?'Email wajib diisi.':!RE_MAIL.test(v)?'Format email belum benar.':'')}
function vPw(){return setErr(pw,pw.value?'':'Password wajib diisi.')}
function vNama(){var v=rn.value.trim();return setErr(rn,!v?'Nama lengkap wajib diisi.':v.length<3?'Nama minimal 3 karakter.':'')}
function vHp(){var v=rh.value.replace(/[\s\-().]/g,'');return setErr(rh,v&&!/^(\+62|62|0)8[0-9]{8,12}$/.test(v)?'Nomor HP belum benar (contoh 081234567890).':'')}
function vRp(){return setErr(rp,!rp.value?'Password wajib diisi.':rp.value.length<8?'Password minimal 8 karakter.':'')}
function vRp2(){return setErr(rp2,!rp2.value?'Ulangi password.':rp2.value!==rp.value?'Password tidak sama.':'')}
function live(inp,fn){
  inp.addEventListener('blur',fn);
  inp.addEventListener('input',function(){if(inp.closest('.field').classList.contains('err'))fn()});
}
live(em,function(){return vMail(em)});live(pw,vPw);
live(rn,vNama);live(rh,vHp);live(re,function(){return vMail(re)});live(rp,vRp);live(rp2,vRp2);
rp.addEventListener('input',function(){if(rp2.value)vRp2()});

function lock(btn,txt){btn.disabled=true;btn.innerHTML='<span class="spin"></span> '+txt}
f.addEventListener('submit',function(e){
  var a=vMail(em),b=vPw();
  if(!a||!b){e.preventDefault();(!a?em:pw).focus();return}
  lock($('#go'),'Memeriksa...');
});
fr.addEventListener('submit',function(e){
  var chk=[[vNama(),rn],[vMail(re),re],[vHp(),rh],[vRp(),rp],[vRp2(),rp2]];
  for(var i=0;i<chk.length;i++){if(!chk[i][0]){e.preventDefault();chk[i][1].focus();return}}
  lock($('#go2'),'Membuat akun...');
});
/* tombol kembali (bfcache): pulihkan tombol yang sempat terkunci */
window.addEventListener('pageshow',function(e){
  if(!e.persisted)return;
  var g=$('#go'),g2=$('#go2');
  g.disabled=false;g.innerHTML='Masuk <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>';
  g2.disabled=false;g2.innerHTML='Buat akun <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>';
});

})();
</script>
</body>
<script>
(function () {
    const arah = sessionStorage.getItem('rt-animasi');

    if (arah) {
        document.documentElement.dataset.animasi = arah;
        sessionStorage.removeItem('rt-animasi');
    }

    const halaman = document.getElementById('auth');

    document.querySelectorAll(
        'a[href*="register.php"], a[href*="login.php"]'
    ).forEach(link => {
        link.addEventListener('click', function (e) {
            if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;

            e.preventDefault();

            const keRegister = link.href.includes('register.php');

            sessionStorage.setItem(
                'rt-animasi',
                keRegister ? 'kanan' : 'kiri'
            );

            halaman.classList.add(
                keRegister ? 'geser-kiri' : 'geser-kanan'
            );

            setTimeout(() => {
                window.location.href = link.href;
            }, 350);
        });
    });
})();
</script>
</html>