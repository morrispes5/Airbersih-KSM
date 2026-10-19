<?php

// One-use loopback form: lets browser credentials enter private configuration without appearing in chat, argv or logs.
declare(strict_types=1);

$root = dirname(__DIR__);
ini_set('display_errors', '0');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
if (($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8087' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || is_file($root.'/private/runtime/cloud-config-complete')) {
    http_response_code(403); exit('Form konfigurasi tidak aktif.');
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== 'http://127.0.0.1:8087' || ! hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403); exit('Permintaan ditolak.');
    }
    $key = $_POST['access_key'] ?? '';
    $secret = $_POST['secret_key'] ?? '';
    if (! preg_match('/^[A-Za-z0-9_-]{12,128}$/D', $key) || ! preg_match('~^[A-Za-z0-9/+=_-]{20,200}$~D', $secret)) {
        http_response_code(422); exit('Format kredensial tidak sesuai.');
    }
    foreach (['operational', 'operational-migration', 'testing', 'e2e'] as $environment) {
        $path = $root.'/web/.env.'.$environment;
        $env = file_get_contents($path);
        foreach (['CLOUD_ACCESS_KEY_ID' => $key, 'CLOUD_SECRET_ACCESS_KEY' => $secret, 'CLOUD_BUCKET' => str_starts_with($environment, 'operational') ? 'airbersih-rt05-private' : 'airbersih-rt05-test', 'CLOUD_ENDPOINT' => 'https://vbxlskqqkvvaconrftqs.storage.supabase.co/storage/v1/s3'] as $name => $value) {
            if (preg_match('/^'.$name.'=.*$/m', $env)) { $env = preg_replace('/^'.$name.'=.*$/m', $name.'='.$value, $env); }
            else { $env .= "\n".$name.'='.$value; }
        }
        file_put_contents($path, $env."\n");
    }
    file_put_contents($root.'/private/runtime/cloud-config-complete', gmdate('c'));
    session_destroy();
    exit('<h1>Koneksi disimpan secara privat</h1><p>Kredensial tidak ditampilkan. Form ini sudah dinonaktifkan.</p>');
}
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Konfigurasi storage Air Bersih RT05</title><style>body{font:16px system-ui;max-width:600px;margin:4rem auto}label,input{display:block}input{width:100%;margin:8px 0 24px;padding:10px}button{padding:12px}</style></head><body><h1>Koneksi storage privat</h1><p>Supabase Free khusus Air Bersih. Data hanya dikirim ke komputer ini dan disimpan pada konfigurasi privat.</p><form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>"><label>Access Key ID<input name="access_key" autocomplete="off" required></label><label>Secret Access Key<input name="secret_key" type="password" autocomplete="off" required></label><button>Simpan konfigurasi privat</button></form></body></html>
