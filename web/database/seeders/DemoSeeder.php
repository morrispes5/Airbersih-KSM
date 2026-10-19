<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\Entry;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Operations;
use App\Support\DatabaseGuard;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DatabaseGuard::check();
        abort_if(config('airbersih.mode') === 'operational' || app()->environment(['operational', 'operational-migration']), 403, 'Seed contoh dilarang pada data operasional.');
        $password = env('DEMO_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            throw new \RuntimeException('DEMO_PASSWORD privat harus disiapkan.');
        }
        $hash = Hash::make($password);
        foreach (['admin' => 'superadmin', 'keuangan' => 'keuangan', 'petugas' => 'petugas'] as $name => $role) {
            User::firstOrCreate(['email' => $name.'@example.test'], ['name' => ucfirst($name).' Demo', 'password' => $hash, 'role' => $role, 'active' => true]);
        }
        $admin = User::where('role', 'superadmin')->firstOrFail();
        $officer = User::where('role', 'petugas')->firstOrFail();
        $cash = Account::firstOrCreate(['name' => 'Kas tunai'], ['kind' => 'cash']);
        $bank = Account::firstOrCreate(['name' => 'Bank simulasi'], ['kind' => 'bank']);
        foreach (['Listrik pompa', 'Perawatan jaringan', 'Operasional', 'Lain-lain'] as $name) {
            Category::firstOrCreate(compact('name'));
        }
        $ops = app(Operations::class);
        if (! Tariff::exists()) {
            $ops->addTariff($admin, ['effective_from' => '2026-01-01', 'rate' => 10000, 'fee' => 5000]);
        }
        if (! Customer::exists()) {
            DB::transaction(function () use ($officer) {
                $now = now();
                $customers = [];
                $connections = [];
                $meters = [];
                for ($i = 1; $i <= 50; $i++) {
                    $customers[] = ['id' => $i, 'code' => 'DEMO-C'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'name' => 'Pelanggan Demo '.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'phone' => null, 'address' => 'Alamat simulasi blok '.ceil($i / 10).' nomor '.$i, 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
                    $connections[] = ['id' => $i, 'customer_id' => $i, 'code' => 'DEMO-SR'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'address' => 'Alamat simulasi blok '.ceil($i / 10).' nomor '.$i, 'status' => $i <= 40 ? 'active' : ($i <= 45 ? 'waiting' : 'inactive'), 'assigned_to' => $officer->id, 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
                    $meters[] = ['id' => $i, 'connection_id' => $i, 'serial' => 'DEMO-M'.$i, 'last_value' => $i === 1 ? '227.000' : (string) (100 + $i), 'active' => true, 'created_at' => $now, 'updated_at' => $now];
                }
                Customer::insert($customers);
                Connection::insert($connections);
                Meter::insert($meters);
                foreach (['customers', 'connections', 'meters'] as $table) {
                    DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), (SELECT MAX(id) FROM {$table}))");
                }
            });
        }
        $period = now('Asia/Jakarta')->format('Y-m');
        if (! Entry::where('source_key', 'opening:'.$cash->id)->exists()) {
            $ops->addEntry($admin, ['account_id' => $cash->id, 'kind' => 'opening', 'amount' => 1000000, 'entry_date' => $period.'-01', 'description' => 'Saldo awal simulasi kas'], (string) Str::uuid());
        }
        if (! Entry::where('source_key', 'opening:'.$bank->id)->exists()) {
            $ops->addEntry($admin, ['account_id' => $bank->id, 'kind' => 'opening', 'amount' => 2000000, 'entry_date' => $period.'-01', 'description' => 'Saldo awal simulasi bank'], (string) Str::uuid());
        }
        foreach ([1 => 277, 2 => 117, 3 => 113] as $id => $current) {
            $r = Reading::where('connection_id', $id)->where('period', $period)->first() ?? $ops->recordReading($officer, $id, $period, (string) $current, UploadedFile::fake()->image('meter-dummy.png', 200, 120));
            if ($r->status === 'draft') {
                $ops->review($admin, $r->id, $r->version);
            }$i = Invoice::where('connection_id', $id)->where('period', $period)->where('status', '<>', 'void')->first() ?? $ops->draftInvoice($admin, $id, $period, (string) Str::uuid());
            if ($i->status === 'draft') {
                $ops->issue($admin, $i->id, $i->version);
            }
            if ($id === 2 && ! Payment::where('invoice_id', $i->id)->exists()) {
                $ops->pay($admin, $i->id, ['account_id' => $cash->id, 'method' => 'cash', 'amount' => $i->total, 'paid_on' => now('Asia/Jakarta')->toDateString()], null, (string) Str::uuid());
            }
            if ($id === 3 && ! Payment::where('invoice_id', $i->id)->exists()) {
                $ops->pay($admin, $i->id, ['account_id' => $bank->id, 'method' => 'transfer', 'amount' => $i->total, 'paid_on' => now('Asia/Jakarta')->toDateString()], UploadedFile::fake()->image('bukti-dummy.png', 200, 120), (string) Str::uuid());
            }
        }
        if (! Entry::where('description', 'Listrik pompa — simulasi')->exists()) {
            $ops->addEntry($admin, ['account_id' => $cash->id, 'category_id' => Category::first()->id, 'kind' => 'expense', 'amount' => 125000, 'entry_date' => now('Asia/Jakarta')->toDateString(), 'description' => 'Listrik pompa — simulasi'], (string) Str::uuid());
        }
    }
}
