<?php

use App\Models\Account;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Operations;
use App\Support\DatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();
    DatabaseGuard::check(true);
    DB::statement("SET application_name = 'airbersih-race-worker'");
    $invoice = Invoice::findOrFail((int) $argv[1]);
    $actor = User::where('role', 'superadmin')->where('active', true)->firstOrFail();
    $account = Account::where('kind', 'cash')->firstOrFail();
    $p = app(Operations::class)->pay($actor, $invoice->id, ['account_id' => $account->id, 'method' => 'cash', 'amount' => $invoice->total, 'paid_on' => now()->toDateString()], null, $argv[2]);
    echo json_encode(['status' => 'ok', 'payment_id' => $p->id]);
} catch (Throwable $e) {
    if ($e instanceof HttpExceptionInterface) {
        echo json_encode(['status' => 'rejected', 'code' => $e->getStatusCode()]);
        exit(0);
    }
    file_put_contents(dirname(__DIR__, 2).'/private/audit/race-worker-'.getmypid().'.log', get_class($e)."\n".$e->getMessage());
    echo json_encode(['status' => 'error', 'type' => get_class($e), 'code' => $e->getCode()]);
    exit(1);
}
