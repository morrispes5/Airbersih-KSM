<?php

// Read the independent encrypted Aiven copy, restore only into the allowlisted test database / test bucket.
declare(strict_types=1);

use App\Services\Backups;
use App\Support\DatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$temp = null;
try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $root = dirname(__DIR__, 2);
    $source = Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__).'/.env.operational'));
    if ($source['DB_DATABASE'] !== 'airbersih_operasional' || $source['DB_USERNAME'] !== 'airbersih_ops_app' || $source['DB_SSLMODE'] !== 'verify-full') {
        throw new RuntimeException('Unexpected recovery source.');
    }
    putenv('PGSSLROOTCERT='.str_replace('\\', '/', $root.'/private/config/aiven-ca.pem'));
    $pdo = new PDO("pgsql:host={$source['DB_HOST']};port={$source['DB_PORT']};dbname=airbersih_operasional;sslmode=verify-full;connect_timeout=15", $source['DB_USERNAME'], $source['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->beginTransaction();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $copy = $pdo->query('SELECT id, checksum, bytes, chunks FROM recovery_copies ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (! $copy) {
        throw new RuntimeException('No independent recovery copy.');
    }
    $temp = tempnam(sys_get_temp_dir(), 'air-restore-');
    $out = fopen($temp, 'wb');
    $q = $pdo->prepare("SELECT sequence, encode(content, 'base64') AS encoded FROM recovery_chunks WHERE recovery_copy_id=? ORDER BY sequence");
    $q->execute([$copy['id']]);
    $count = 0;
    while ($part = $q->fetch(PDO::FETCH_ASSOC)) {
        if ((int) $part['sequence'] !== $count++) {
            throw new RuntimeException('Missing backup chunk.');
        }
        fwrite($out, base64_decode($part['encoded'], true));
    }
    fclose($out);
    $pdo->commit();
    $pdo = null;
    if ($count !== (int) $copy['chunks'] || filesize($temp) !== (int) $copy['bytes'] || ! hash_equals($copy['checksum'], hash_file('sha256', $temp))) {
        throw new RuntimeException('Independent copy verification failed.');
    }
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();
    DatabaseGuard::check(true);
    config(['airbersih.mode' => 'operational', 'airbersih.documents_disk' => 'cloud']);
    try {
        $result = app(Backups::class)->restoreTest($temp, $source['APP_KEY']);
    } finally {
        // Record exact restored references even when file readback fails after DB commit.
        // Cleanup must also cover future meter photos and invoice PDFs, not only initial Excel sources.
        $paths = array_values(array_unique(array_merge(
            DB::table('attachments')->pluck('path')->all(),
            DB::table('invoices')->whereNotNull('pdf_path')->pluck('pdf_path')->all(),
            DB::table('import_batches')->pluck('path')->all()
        )));
        file_put_contents($root.'/private/runtime/recovery-test-files.json', json_encode(['database' => 'airbersih_test', 'bucket' => config('filesystems.disks.cloud.bucket'), 'paths' => $paths], JSON_THROW_ON_ERROR));
    }
    $result += ['source' => 'independent encrypted Aiven copy', 'destination_storage' => 'separate private cloud test bucket', 'verified_utc' => gmdate('c')];
    file_put_contents($root.'/docs/evidence/operational-recovery.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    DB::disconnect();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: operational recovery verification ('.$e::class.' ['.$e->getCode()."])\n");
    exit(1);
} finally {
    if ($temp && is_file($temp)) {
        unlink($temp);
    }
}
