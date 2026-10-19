<?php

// Vercel entrypoint. Only temporary buffers live in /tmp; committed files use cloud storage.
declare(strict_types=1);
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));
$root = dirname(__DIR__);
$runtime = sys_get_temp_dir().'/airbersih';
foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/private', 'bootstrap', 'private/backups'] as $directory) {
    $path = $runtime.'/'.$directory;
    if (! is_dir($path)) {
        mkdir($path, 0700, true);
    }
}
$certificate = base64_decode(getenv('AIR_CA_BUNDLE_BASE64') ?: '', true);
if (! is_string($certificate) || ! str_contains($certificate, '-----BEGIN CERTIFICATE-----')) {
    http_response_code(503);
    exit('Koneksi penyimpanan belum siap. Hubungi pengelola.');
}
$ca = $runtime.'/ca-bundle.pem';
file_put_contents($ca, $certificate, LOCK_EX);
chmod($ca, 0600);
foreach ([
    'AIR_DB_CA_PATH' => $ca,
    'AIR_HTTP_CA_PATH' => $root.'/api/certs/http-ca.pem',
    'AIR_PRIVATE_PATH' => $runtime.'/private',
    'PGSSLROOTCERT' => $ca,
    'VIEW_COMPILED_PATH' => $runtime.'/framework/views',
    'APP_CONFIG_CACHE' => $runtime.'/bootstrap/config.php',
    'APP_PACKAGES_CACHE' => $runtime.'/bootstrap/packages.php',
    'APP_EVENTS_CACHE' => $runtime.'/bootstrap/events.php',
    'APP_ROUTES_CACHE' => $runtime.'/bootstrap/routes.php',
    'APP_SERVICES_CACHE' => $runtime.'/bootstrap/services.php',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($runtime);
$app->handleRequest(Request::capture());
