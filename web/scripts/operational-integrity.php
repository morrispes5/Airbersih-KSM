<?php

declare(strict_types=1);

use App\Support\DatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.operational');
    $app->make(Kernel::class)->bootstrap();
    DatabaseGuard::check();
    abort_unless(DB::connection()->getDatabaseName() === 'airbersih_operasional', 403);
    $root = dirname(__DIR__, 2);
    $baseline = $root.'/private/runtime/m10-business-before.json';
    $tables = ['users', 'customers', 'connections', 'meters', 'readings', 'meter_changes', 'tariffs', 'invoices', 'accounts', 'categories', 'payments', 'entries', 'attachments', 'import_batches', 'source_rows'];
    $snapshot = DB::transaction(function () use ($tables) {
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $result = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->all();
            $result[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
        }

        return $result;
    });
    if (in_array('--capture', $argv, true)) {
        file_put_contents($baseline, json_encode($snapshot, JSON_THROW_ON_ERROR));
        echo "PASS: operational business baseline captured privately (15 tables).\n";
    } elseif (in_array('--compare', $argv, true)) {
        $before = json_decode(file_get_contents($baseline), true, flags: JSON_THROW_ON_ERROR);
        $changed = array_values(array_filter($tables, fn ($table) => ($before[$table] ?? null) !== $snapshot[$table]));
        $result = ['checked_utc' => gmdate('c'), 'database' => 'airbersih_operasional', 'business_tables_checked' => count($tables), 'business_tables_identical' => ! $changed, 'changed_tables' => $changed, 'allowed_changes' => 'sessions, recovery completion audit and new encrypted recovery copies only'];
        file_put_contents($root.'/docs/evidence/operational-integrity.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        if ($changed) {
            throw new RuntimeException('Operational business tables changed; inspect privately before claiming a clean test.');
        }
        echo "PASS: all 15 operational business tables unchanged.\n";
    } else {
        throw new InvalidArgumentException('Choose --capture or --compare.');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: operational integrity ('.$e::class.' ['.$e->getCode()."])\n");
    exit(1);
}
