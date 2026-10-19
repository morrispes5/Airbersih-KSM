<?php

use App\Support\DatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();
    DatabaseGuard::check(true);
    $start = microtime(true);
    DB::transaction(function () {
        $count = DB::table('connections')->count();
        $customerId = (int) DB::table('customers')->max('id');
        $connectionId = (int) DB::table('connections')->max('id');
        $meterId = (int) DB::table('meters')->max('id');
        $officer = DB::table('users')->where('role', 'petugas')->value('id');
        $customers = $connections = $meters = [];
        $now = now();
        for ($n = 1; $n <= max(0, 1000 - $count); $n++) {
            $customers[] = ['id' => $customerId + $n, 'code' => 'PERF-C-'.($customerId + $n), 'name' => 'Pelanggan Dummy Kinerja '.($customerId + $n), 'phone' => '00001234', 'address' => 'Alamat simulasi kinerja', 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
            $connections[] = ['id' => $connectionId + $n, 'customer_id' => $customerId + $n, 'code' => 'PERF-S-'.($connectionId + $n), 'address' => 'Alamat simulasi kinerja', 'status' => 'active', 'assigned_to' => $officer, 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
            $meters[] = ['id' => $meterId + $n, 'connection_id' => $connectionId + $n, 'serial' => 'DUMMY-'.($meterId + $n), 'last_value' => 0, 'active' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (['customers' => $customers, 'connections' => $connections, 'meters' => $meters] as $table => $rows) {
            foreach (array_chunk($rows, 250) as $chunk) {
                DB::table($table)->insert($chunk);
            }
            DB::statement("SELECT setval(pg_get_serial_sequence(?, 'id'), (SELECT MAX(id) FROM {$table}), true)", [$table]);
        }
    });
    $result = ['database' => DB::selectOne('SELECT current_database() AS name')->name, 'connections' => DB::table('connections')->count(), 'data' => 'synthetic only', 'seed_seconds' => round(microtime(true) - $start, 2)];
    file_put_contents(dirname(__DIR__, 2).'/docs/evidence/dataset-check.json', json_encode($result, JSON_PRETTY_PRINT));
    echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: benchmark fixture could not be prepared ('.get_class($e).').'.PHP_EOL);
    exit(1);
}
