<?php
/**
 * ReTaste - Keluar: hapus data login dari session lalu kembali ke halaman login.
 * (Akun demo hasil pendaftaran di session sengaja dipertahankan supaya bisa dites masuk lagi;
 *  dengan database sungguhan, data akun ada di tabel users sehingga tidak terpengaruh.)
 */
session_start();
foreach (['user_id', 'nama', 'email', 'role', 'baru'] as $k) { unset($_SESSION[$k]); }
session_regenerate_id(true);
header('Location: login.php?keluar=1'); exit;