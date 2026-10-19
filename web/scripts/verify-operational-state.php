<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\ImportBatch;
use App\Services\PrivateFiles;
use App\Support\DatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.operational');
    $app->make(Kernel::class)->bootstrap();
    DatabaseGuard::check();
    $grants = DB::selectOne("SELECT has_schema_privilege(current_user,'public','CREATE') AS ddl, has_table_privilege(current_user,'audits','UPDATE') AS audit_update, has_table_privilege(current_user,'audits','DELETE') AS audit_delete, (SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls");
    if ($grants->ddl || $grants->audit_update || $grants->audit_delete || ! $grants->tls) {
        throw new RuntimeException('Runtime privileges or TLS mismatch.');
    }
    $counts = [];
    foreach (['users', 'customers', 'connections', 'meters', 'readings', 'invoices', 'payments', 'entries', 'import_batches', 'source_rows', 'recovery_copies'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }
    $demoAccounts = DB::table('users')->where('email', 'like', '%@example.test')->count();
    $demoInvoices = DB::table('invoices')->where('number', 'like', 'SIM/%')->count();
    if ($demoAccounts || $demoInvoices) {
        throw new RuntimeException('Example records reached operational database.');
    }
    $result = ['checked_utc' => gmdate('c'), 'database' => 'airbersih_operasional', 'runtime_ddl' => false, 'runtime_audit_update_delete' => false, 'tls' => true, 'example_accounts' => 0, 'example_invoice_numbers' => 0, 'counts' => $counts, 'unconfirmed_opening_accounts' => DB::table('accounts')->whereNull('opening_by')->count(), 'cloud_configuration_ready' => filled(config('filesystems.disks.cloud.key')) && filled(config('filesystems.disks.cloud.secret'))];
    if (in_array('--files', $argv, true)) {
        foreach (Attachment::all() as $file) {
            if (! hash_equals($file->checksum, PrivateFiles::hash($file->path))) {
                throw new RuntimeException('Attachment checksum mismatch.');
            }
        }
        foreach (ImportBatch::all() as $batch) {
            PrivateFiles::temporary($batch->path, function ($temp) use ($batch) {
                if (! hash_equals($batch->hash, hash_file('sha256', $temp))) {
                    throw new RuntimeException('Source checksum mismatch.');
                }
            });
        }
        $result['operational_cloud_files_verified'] = true;
        $result['staging_kinds'] = DB::table('source_rows')->selectRaw('kind, count(*) AS rows')->groupBy('kind')->orderBy('kind')->get()->all();
        $result['held_invoice_conflict_rows'] = DB::table('source_rows')->whereRaw('issues::text LIKE ?', ['%CONFLICT:%'])->count();
    }
    file_put_contents(dirname(__DIR__, 2).'/docs/evidence/operational-state.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: operational state verification ('.$e::class.' ['.$e->getCode()."])\n");
    exit(1);
}
