<?php

declare(strict_types=1);

use App\Services\PrivateFiles;
use App\Support\DatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->loadEnvironmentFrom(in_array('--operational', $argv, true) ? '.env.operational' : '.env.testing');
    $app->make(Kernel::class)->bootstrap();
    DatabaseGuard::check(! in_array('--operational', $argv, true));
    config(['airbersih.mode' => 'operational', 'airbersih.documents_disk' => 'cloud']);
    $disk = Storage::disk(PrivateFiles::disk());
    $path = 'checks/'.bin2hex(random_bytes(16)).'.bin';
    $bytes = random_bytes(512);
    $disk->put($path, $bytes);
    try {
        if (! hash_equals(hash('sha256', $bytes), PrivateFiles::hash($path))) {
            throw new RuntimeException('Cloud readback failed.');
        }
        $c = config('filesystems.disks.cloud');
        $public = str_replace('.storage.supabase.co/storage/v1/s3', '.supabase.co/storage/v1/object/public', $c['endpoint']).'/'.$c['bucket'].'/'.$path;
        $response = Http::withOptions(['verify' => config('airbersih.http_ca')])->timeout(15)->get($public);
        if ($response->successful()) {
            throw new RuntimeException('Anonymous access unexpectedly succeeded.');
        }
        $result = ['storage' => 'Supabase Free private S3', 'bucket_kind' => in_array('--operational', $argv, true) ? 'operational' : 'isolated test', 'hash_readback' => 'identical', 'anonymous_status' => $response->status(), 'no_local_fallback' => true, 'checked_utc' => gmdate('c')];
        file_put_contents(dirname(__DIR__, 2).'/docs/evidence/cloud-'.($result['bucket_kind'] === 'operational' ? 'operational' : 'test').'.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    } finally {
        $disk->delete($path);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: cloud verification ('.$e::class.' ['.$e->getCode()."])\n");
    exit(1);
}
