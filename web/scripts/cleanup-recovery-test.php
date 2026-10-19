<?php

declare(strict_types=1);

use App\Services\PrivateFiles;
use App\Support\DatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();
    DatabaseGuard::check(true);
    $root = dirname(__DIR__, 2);
    $hashes = array_unique(array_column(json_decode(file_get_contents($root.'/docs/evidence/operational-sources.json'), true, flags: JSON_THROW_ON_ERROR), 'hash'));
    if (DB::table('import_batches')->whereIn('hash', $hashes)->exists()) {
        throw new RuntimeException('Restore synthetic baseline before cleaning recovery test files.');
    }
    config(['airbersih.mode' => 'operational', 'airbersih.documents_disk' => 'cloud']);
    if (config('filesystems.disks.cloud.bucket') !== 'airbersih-rt05-test') {
        throw new RuntimeException('Unexpected cleanup bucket.');
    }
    $disk = Storage::disk(PrivateFiles::disk());
    $manifestFile = $root.'/private/runtime/recovery-test-files.json';
    $manifest = json_decode(file_get_contents($manifestFile), true, flags: JSON_THROW_ON_ERROR);
    if (($manifest['database'] ?? null) !== 'airbersih_test' || ($manifest['bucket'] ?? null) !== 'airbersih-rt05-test') {
        throw new RuntimeException('Unexpected restore cleanup manifest.');
    }
    $objects = [];
    foreach ($manifest['paths'] as $path) {
        if (! preg_match('~^(attachments|invoices|imports|sources)/[A-Za-z0-9._-]+$~D', $path) || str_contains($path, '..')) {
            throw new RuntimeException('Unexpected restore object path.');
        }
        $objects[] = $path;
        if (str_ends_with($path, '.parts.json')) {
            $prefix = substr($path, 0, -11).'.part';
            foreach ($disk->allFiles(dirname($path)) as $part) {
                if (str_starts_with($part, $prefix)) {
                    $objects[] = $part;
                }
            }
        }
    }
    $deleted = 0;
    foreach (array_unique($objects) as $file) {
        if ($disk->exists($file)) {
            $disk->delete($file);
            $deleted++;
        }
        if ($disk->exists($file)) {
            throw new RuntimeException('Restored test object remains after cleanup.');
        }
    }
    unlink($manifestFile);
    $result = ['database' => 'airbersih_test', 'real_source_batches_remaining' => 0, 'test_copies_deleted' => $deleted, 'restored_objects_remaining' => 0, 'scope' => 'recorded restore objects and matching multipart chunks only', 'operational_database_and_bucket_modified' => false, 'checked_utc' => gmdate('c')];
    file_put_contents($root.'/docs/evidence/recovery-test-cleanup.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: recovery test cleanup ('.$e::class.' ['.$e->getCode()."])\n");
    exit(1);
}
