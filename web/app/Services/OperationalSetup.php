<?php

namespace App\Services;

use App\Jobs\InspectWorkbook;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\SourceRow;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

final class OperationalSetup
{
    public function bootstrap(): void
    {
        abort_unless(app()->environment('operational') && config('airbersih.mode') === 'operational', 403);
        DB::transaction(function () {
            DB::statement('SELECT pg_advisory_xact_lock(6052026)');
            if (User::exists()) {
                return;
            }
            $password = bin2hex(random_bytes(14));
            $email = config('airbersih.initial_admin_email');
            Validator::make(compact('email'), ['email' => 'required|email'])->validate();
            $user = User::create(['name' => 'Pengurus RT 05', 'email' => $email, 'password' => Hash::make($password), 'role' => 'superadmin', 'active' => true]);
            Account::create(['name' => 'Kas KSM', 'kind' => 'cash']);
            Account::create(['name' => 'Bank KSM', 'kind' => 'bank']);
            foreach (['Listrik', 'Pemeliharaan', 'Operasional'] as $category) {
                Category::create(['name' => $category]);
            }
            app(Operations::class)->addTariff($user, ['effective_from' => BusinessClock::today()->toDateString(), 'rate' => 10000, 'fee' => 5000]);
            file_put_contents(dirname(base_path()).'/private/operational-account.md', "# Akun operasional Air Bersih RT05\n\nURL: http://127.0.0.1:8085/login\nEmail: {$user->email}\nPassword: {$password}\n\nGanti password di Pengaturan setelah masuk. Berkas ini privat; jangan masuk Git/Drive publik.\n");
            app(Operations::class)->audit($user, 'operational.initialized', 'operational', ['tariff_rate' => 10000, 'admin_per_invoice' => 5000, 'balances' => 'unconfirmed', 'meters' => 'unconfirmed']);
        });
    }

    public function sources(): array
    {
        abort_unless(app()->environment('operational'), 403);
        $user = User::where('role', 'superadmin')->where('active', true)->firstOrFail();
        $result = [];
        foreach (['excel-A', 'excel-B', 'excel-C'] as $alias) {
            $source = config('airbersih.sources').'/'.$alias.'.xlsx';
            abort_unless(is_file($source), 422, 'Sumber asli belum tersedia.');
            $hash = hash_file('sha256', $source);
            $batch = ImportBatch::where('hash', $hash)->first();
            if (! $batch) {
                $path = PrivateFiles::store($source, 'imports/'.$hash.'.xlsx');
                $batch = ImportBatch::create(['hash' => $hash, 'name' => $alias.'.xlsx', 'path' => $path, 'operator_id' => $user->id]);
            }
            if ($batch->status !== 'ready') {
                app(InspectWorkbook::class, ['batchId' => $batch->id])->handle(app(WorkbookPreview::class));
            }
            $result[] = ['alias' => $alias, 'hash' => $hash, 'batch' => $batch->id, 'rows' => SourceRow::where('import_batch_id', $batch->id)->count()];
        }
        $image = config('airbersih.sources').'/invoice-reference-2025-05.png';
        if (is_file($image) && ! Attachment::where('owner_type', 'source_invoice')->exists()) {
            $path = PrivateFiles::store($image, 'sources/'.hash_file('sha256', $image).'.png');
            Attachment::create(['owner_type' => 'source_invoice', 'owner_id' => 0, 'path' => $path, 'mime' => 'image/png', 'bytes' => filesize($image), 'checksum' => hash_file('sha256', $image)]);
        }

        return $result;
    }
}
