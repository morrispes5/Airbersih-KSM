<?php

use App\Models\Entry;
use App\Models\Payment;
use App\Models\User;
use App\Services\Operations;
use App\Support\DatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

try {
    if (in_array('--verify-failure-exit', $argv, true)) {
        throw new RuntimeException('Intentional harness failure self-check.');
    }
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();
    DatabaseGuard::check(true);
    DB::statement("SET application_name = 'airbersih-race-parent'");
    $otherConnections = DB::select('SELECT pid, state, application_name FROM pg_stat_activity WHERE usename = current_user AND pid <> pg_backend_pid()');
    file_put_contents(dirname(__DIR__, 2).'/private/audit/race-connection-baseline.json', json_encode($otherConnections, JSON_PRETTY_PRINT));
    if (count($otherConnections) > 0) {
        throw new RuntimeException('Test database still has other clients; stop E2E before running concurrency tests.');
    }
    $ops = app(Operations::class);
    $actor = User::where('role', 'superadmin')->where('active', true)->firstOrFail();
    $results = [];
    foreach (['same_key', 'different_keys'] as $scenario) {
        $c = $ops->saveCustomer($actor, ['name' => 'Race Dummy', 'address' => 'Alamat pengujian paralel']);
        $connection = $ops->saveConnection($actor, ['customer_id' => $c->id, 'address' => 'Alamat pengujian paralel', 'status' => 'active', 'serial' => 'RACE', 'opening' => '0']);
        $r = $ops->recordReading($actor, $connection->id, '2026-10', '10', UploadedFile::fake()->image('dummy.png'));
        $ops->review($actor, $r->id, 1);
        $i = $ops->draftInvoice($actor, $connection->id, '2026-10', (string) Str::uuid());
        $i = $ops->issue($actor, $i->id, $i->version);
        $key = (string) Str::uuid();
        DB::purge();
        gc_collect_cycles();
        usleep(500000);
        $processes = [];
        for ($n = 0; $n < 2; $n++) {
            $p = new Process([PHP_BINARY, __DIR__.'/payment-race-worker.php', (string) $i->id, $scenario === 'same_key' ? $key : (string) Str::uuid()], dirname(__DIR__), ['APP_ENV' => 'testing']);
            $p->setTimeout(45);
            $p->start();
            $processes[] = $p;
        }
        $output = [];
        foreach ($processes as $p) {
            $p->wait();
            $parsed = json_decode($p->getOutput(), true);
            if (! $p->isSuccessful() || ! is_array($parsed)) {
                file_put_contents(dirname(__DIR__, 2).'/private/audit/race-process.log', $p->getOutput()."\n".$p->getErrorOutput());
                throw new RuntimeException('Race worker failed; see private/audit/race-process.log.');
            }$output[] = $parsed;
        }
        $payments = Payment::where('invoice_id', $i->id)->count();
        $posted = Entry::whereIn('source_key', Payment::where('invoice_id', $i->id)->get()->map(fn ($p) => 'payment:'.$p->id))->count();
        if ($payments !== 1 || $posted !== 1 || $i->fresh()->status !== 'paid') {
            throw new RuntimeException('Payment concurrency invariant failed.');
        }
        if ($scenario === 'same_key' && count(array_filter($output, fn ($r) => $r['status'] === 'ok')) !== 2) {
            throw new RuntimeException('Idempotent replay failed.');
        }
        if ($scenario === 'different_keys' && (count(array_filter($output, fn ($r) => $r['status'] === 'ok')) !== 1 || count(array_filter($output, fn ($r) => ($r['code'] ?? null) === 422)) !== 1)) {
            throw new RuntimeException('Competing payment was not rejected correctly.');
        }
        $results[] = ['scenario' => $scenario, 'workers' => $output, 'payments' => $payments, 'ledger_postings' => $posted, 'result' => 'passed'];
    }
    file_put_contents(dirname(__DIR__, 2).'/docs/evidence/payment-concurrency.json', json_encode($results, JSON_PRETTY_PRINT));
    echo json_encode($results, JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: '.$e->getMessage().PHP_EOL);
    exit(1);
}
