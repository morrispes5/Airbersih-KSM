<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Attachment;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\PrivateFiles;
use App\Services\Reports;
use App\Support\BusinessClock;
use App\Support\DatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.e2e');
    $app->make(Kernel::class)->bootstrap();
    DatabaseGuard::check(true);
    config(['airbersih.mode' => 'operational', 'airbersih.documents_disk' => 'cloud']);
    PrivateFiles::disk();
    $root = dirname(__DIR__, 2);
    if (in_array('--create', $argv, true)) {
        abort_if(User::exists() || Invoice::exists(), 409, 'Fixture needs a fresh isolated test database.');
        $password = bin2hex(random_bytes(16));
        foreach (['admin' => 'superadmin', 'finance' => 'keuangan', 'officer' => 'petugas'] as $suffix => $role) {
            User::create(['name' => 'Acceptance '.$suffix, 'email' => 'acceptance-'.$suffix.'@example.test', 'password' => $password, 'role' => $role, 'active' => true]);
        }
        Account::create(['name' => 'Kas Uji Penerimaan', 'kind' => 'cash']);
        Account::create(['name' => 'Bank Uji Penerimaan', 'kind' => 'bank']);
        DB::table('tariffs')->insert(['effective_from' => BusinessClock::today()->toDateString(), 'rate' => 10000, 'fee' => 5000, 'approved_by' => User::where('role', 'superadmin')->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        file_put_contents($root.'/private/runtime/operational-e2e-account.json', json_encode(['password' => $password, 'date' => BusinessClock::today()->toDateString(), 'period' => BusinessClock::today()->format('Y-m'), 'due_date' => BusinessClock::today()->addDays(7)->toDateString()], JSON_THROW_ON_ERROR));
        echo "PASS: isolated operational-mode fixture ready.\n";
    } else {
        abort_unless(User::count() === 3 && User::where('email', 'like', 'acceptance-%@example.test')->count() === 3, 403, 'Unexpected fixture users; refuse test file access.');
        $paths = array_merge(Attachment::pluck('path')->all(), Invoice::whereNotNull('pdf_path')->pluck('pdf_path')->all(), ImportBatch::pluck('path')->all());
        if (in_array('--assert', $argv, true)) {
            $report = app(Reports::class)->data(BusinessClock::today()->format('Y-m'));
            abort_unless(Invoice::where('status', 'paid')->where('total', 305000)->count() === 1 && DB::table('payments')->where('status', 'confirmed')->count() === 1 && $report['closing'] === 305000 && $report['receivables'] === 0 && $report['income'] === 305000 && DB::table('entries')->count() === 3, 422, 'Operational-mode settlement mismatch.');
            abort_unless(Invoice::whereNotNull('pdf_path')->count() === 1, 422, 'Invoice PDF worker has not completed.');
            foreach (Attachment::all() as $file) {
                abort_unless(hash_equals($file->checksum, PrivateFiles::hash($file->path)), 422);
            }
            foreach ($paths as $path) {
                abort_unless(Storage::disk(PrivateFiles::disk())->exists($path), 422);
            }
            $result = ['checked_utc' => gmdate('c'), 'database' => 'airbersih_test', 'mode' => 'operational', 'storage' => 'actual private isolated cloud test bucket', 'cash_received' => 305000, 'income' => 305000, 'receivables' => 0, 'payments' => 1, 'entries_including_two_confirmed_zero_openings' => 3, 'cloud_files_verified' => count($paths), 'synthetic_fixture_only' => true];
            file_put_contents($root.'/docs/evidence/operational-flow-ledger.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            echo "PASS: operational-mode browser settlement, ledger and cloud files match.\n";
        } elseif (in_array('--cleanup', $argv, true)) {
            $deleted = 0;
            foreach ($paths as $path) {
                foreach (PrivateFiles::paths($path) as $object) {
                    Storage::disk(PrivateFiles::disk())->delete($object);
                    $deleted++;
                }
            }
            file_put_contents($root.'/docs/evidence/operational-flow-cleanup.json', json_encode(['checked_utc' => gmdate('c'), 'bucket_kind' => 'isolated test', 'fixture_objects_deleted' => $deleted, 'operational_bucket_modified' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            echo "PASS: isolated browser fixture files removed.\n";
        } else {
            throw new InvalidArgumentException('Choose --create, --assert or --cleanup.');
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: operational flow fixture ('.$e::class.' ['.$e->getCode()."])\n");
    exit(1);
}
