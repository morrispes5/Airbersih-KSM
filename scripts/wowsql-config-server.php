<?php

// Loopback-only, one-use form. Never prints the submitted database password.
declare(strict_types=1);

$root = dirname(__DIR__);
ini_set('display_errors', '0');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
$dest = $root.'/private/config/wowsql-deployment.json';
if (($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8094' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || is_file($dest)) {
    http_response_code(403);
    exit('Form sudah selesai atau tidak aktif. Password tidak ditampilkan.');
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== 'http://127.0.0.1:8094' || ! hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Permintaan ditolak.');
    }
    $password = $_POST['password'] ?? '';
    if (! is_string($password) || strlen($password) < 8 || strlen($password) > 512) {
        http_response_code(422);
        exit('Periksa password database.');
    }
    $config = ['project' => 'airbersih-ksm-6bc263eb', 'host' => 'ap-south-1.pooler.wowsql.com', 'port' => 6543, 'database' => 'db_airbersih_ksm_6bc263eb', 'username' => 'u_airbersih_ksm_6bc263eb', 'password' => $password];
    if (file_put_contents($dest, json_encode($config, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        http_response_code(500);
        exit('Penyimpanan konfigurasi gagal.');
    }
    session_destroy();
    exit('<!doctype html><html lang="id"><meta charset="utf-8"><title>Konfigurasi tersimpan</title><h1>Konfigurasi tersimpan secara privat</h1><p>Password tidak ditampilkan. Kamu boleh menutup tab ini. Koneksi akan diperiksa sebelum dipakai.</p></html>');
}
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Koneksi WoWSQL Airbersih KSM</title><style>body{font:16px/1.6 system-ui;max-width:620px;margin:50px auto;padding:20px;color:#173b43}label,input{display:block}input{box-sizing:border-box;width:100%;padding:14px;margin:12px 0 24px;font:inherit}button{padding:14px 22px;background:#075b63;color:white;border:0;font:inherit}small{display:block;margin-top:24px}</style></head><body><h1>Hubungkan aplikasi ke WoWSQL</h1><p>Masukkan password database proyek <strong>Airbersih KSM</strong> yang tadi kamu buat. Ini password database, bukan password akun ChatGPT atau WoWSQL.</p><form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>"><label>Password database<input type="password" name="password" required minlength="8" autocomplete="off"></label><button type="submit">Simpan koneksi secara privat</button></form><small>Form hanya berjalan di komputer ini. Password disimpan pada konfigurasi privat dan akan dipakai sebagai rahasia server Vercel; tidak masuk GitHub atau halaman publik.</small></body></html>
