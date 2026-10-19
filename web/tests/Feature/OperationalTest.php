<?php

namespace Tests\Feature;

use App\Jobs\CreateRecoveryBackup;
use App\Livewire\Workspace;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\Entry;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\User;
use App\Services\OperationalReadiness;
use App\Services\Operations;
use App\Services\PdfDocuments;
use App\Services\PrivateFiles;
use App\Services\Reports;
use App\Support\BusinessClock;
use App\Support\DatabaseGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OperationalTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Operations $ops;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseGuard::check(true);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16 12:00:00', 'Asia/Jakarta'));
        config(['airbersih.mode' => 'operational', 'airbersih.documents_disk' => 'cloud', 'filesystems.disks.cloud.bucket' => 'airbersih-rt05-test', 'filesystems.disks.cloud.key' => 'test-only', 'filesystems.disks.cloud.secret' => 'test-only', 'filesystems.disks.cloud.endpoint' => 'https://testproject.storage.supabase.co/storage/v1/s3']);
        Storage::fake('cloud');
        Queue::fake();
        $this->ops = app(Operations::class);
        $this->admin = User::create(['name' => 'Operator Uji', 'email' => Str::uuid().'@example.test', 'password' => 'temporary-test-password', 'role' => 'superadmin', 'active' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function connection(?string $opening = null, ?string $date = null): Connection
    {
        $customer = $this->ops->saveCustomer($this->admin, ['name' => 'Identitas Uji', 'address' => 'Alamat Uji']);

        return $this->ops->saveConnection($this->admin, ['customer_id' => $customer->id, 'address' => 'Alamat Uji', 'status' => 'active', 'serial' => 'M-UJI', 'opening' => $opening, 'baseline_on' => $date]);
    }

    private function reject(callable $action, int $status = 422): void
    {
        try {
            $action();
            $this->fail('Operasi seharusnya ditolak.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_unknown_meter_never_becomes_zero_or_an_invoice(): void
    {
        $c = $this->connection();
        $this->assertNull(Meter::where('connection_id', $c->id)->first()->last_value);
        $this->reject(fn () => $this->ops->recordReading($this->admin, $c->id, '2026-10', '30', UploadedFile::fake()->image('meter.png'), null, '2026-10-16'));
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Entry::count());
        $this->ops->baseline($this->admin, $c->id, '0', '2026-10-16', 'Hasil pemeriksaan fisik', UploadedFile::fake()->image('baseline.png'));
        $this->assertSame('0.000', Meter::where('connection_id', $c->id)->first()->last_value);
    }

    public function test_old_meter_gap_cannot_be_charged_in_current_month(): void
    {
        $c = $this->connection('227', '2025-05-25');
        $this->reject(fn () => $this->ops->recordReading($this->admin, $c->id, '2026-10', '277', UploadedFile::fake()->image('meter.png'), null, '2026-10-16'));
        $this->assertSame(0, Reading::count());
    }

    public function test_new_invoice_contract_and_waiver_snapshot(): void
    {
        $this->ops->addTariff($this->admin, ['effective_from' => '2026-10-01', 'rate' => 10000, 'fee' => 5000]);
        foreach (['30' => 305000, '50' => 505000, '100' => 1005000, 'FREE' => 500000] as $volume => $expected) {
            $c = $this->connection('0', '2026-10-01');
            if ($volume === 'FREE') {
                $this->ops->overrideFee($this->admin, $c->id, 0, 'Pembebasan disetujui pengurus');
            }
            $r = $this->ops->recordReading($this->admin, $c->id, '2026-10', $volume === 'FREE' ? '50' : (string) $volume, UploadedFile::fake()->image('meter.png'), null, '2026-10-16');
            $this->ops->review($this->admin, $r->id, $r->version);
            $i = $this->ops->draftInvoice($this->admin, $c->id, '2026-10', (string) Str::uuid(), '2026-10-31');
            $this->assertSame($expected, $i->total);
            $this->assertSame('2026-10-01', $i->snapshot['segments'][0]['read_from']);
            $this->assertSame('2026-10-16', $i->snapshot['segments'][0]['read_on']);
            $this->ops->issue($this->admin, $i->id, $i->version);
            $this->assertStringStartsWith('BB/', $i->fresh()->number);
            if ($volume === 'FREE') {
                $bytes = app(PdfDocuments::class)->invoice($i->fresh());
                $this->assertStringStartsWith('%PDF-', $bytes);
                file_put_contents(dirname(base_path()).'/private/audit/operational-invoice-test.pdf', $bytes);
            }
        }
    }

    public function test_zero_consumption_requires_review_and_due_date_is_explicit(): void
    {
        $this->ops->addTariff($this->admin, ['effective_from' => '2026-10-01', 'rate' => 10000, 'fee' => 5000]);
        $c = $this->connection('0', '2026-10-01');
        $r = $this->ops->recordReading($this->admin, $c->id, '2026-10', '0', UploadedFile::fake()->image('meter.png'), null, '2026-10-16');
        $this->ops->review($this->admin, $r->id, $r->version);
        $this->reject(fn () => $this->ops->draftInvoice($this->admin, $c->id, '2026-10', (string) Str::uuid(), '2026-10-31'));
        $i = $this->ops->draftInvoice($this->admin, $c->id, '2026-10', (string) Str::uuid(), '2026-10-31', true);
        $this->assertSame(5000, $i->total);
        $this->assertSame('2026-10-31', $i->due_date->toDateString());
    }

    public function test_unknown_cash_and_verified_zero_are_distinct(): void
    {
        $a = Account::create(['name' => 'Kas Uji', 'kind' => 'cash']);
        $this->assertNull(app(Reports::class)->data('2026-10')['closing']);
        $this->reject(fn () => $this->ops->addEntry($this->admin, ['account_id' => $a->id, 'amount' => 10000, 'kind' => 'income', 'entry_date' => '2026-10-16', 'description' => 'Penerimaan diperiksa'], (string) Str::uuid()));
        $this->ops->addEntry($this->admin, ['account_id' => $a->id, 'amount' => 0, 'kind' => 'opening', 'entry_date' => '2026-10-16', 'description' => 'Saldo fisik nol sudah diperiksa'], (string) Str::uuid());
        $this->assertSame(0, app(Reports::class)->data('2026-10')['closing']);
    }

    public function test_clock_rolls_at_wib_midnight_and_leap_day(): void
    {
        $this->assertSame(['2025-10-16', '2026-10-17'], BusinessClock::window());
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 17:01:00', 'UTC'));
        $this->assertSame('2026-11', BusinessClock::today()->format('Y-m'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2028-02-29 12:00:00', 'Asia/Jakarta'));
        $this->assertSame(['2027-02-28', '2028-03-01'], BusinessClock::window());
    }

    public function test_history_filter_preserves_opening_balance_and_master(): void
    {
        $a = Account::create(['name' => 'Kas Uji', 'kind' => 'cash']);
        $this->ops->addEntry($this->admin, ['account_id' => $a->id, 'amount' => 100000, 'kind' => 'opening', 'entry_date' => '2025-10-01', 'description' => 'Saldo awal terkonfirmasi'], (string) Str::uuid());
        $this->ops->addEntry($this->admin, ['account_id' => $a->id, 'amount' => 50000, 'kind' => 'income', 'entry_date' => '2025-10-10', 'description' => 'Penerimaan sebelum jendela'], (string) Str::uuid());
        $report = app(Reports::class)->data('2025-10');
        $this->assertCount(0, $report['rows']);
        $this->assertSame(150000, $report['closing']);
        $this->connection();
        $this->assertSame(1, Customer::count());
    }

    public function test_cloud_failure_cannot_fallback_to_local_or_operational_bucket(): void
    {
        config(['airbersih.documents_disk' => 'local']);
        try {
            PrivateFiles::disk();
            $this->fail('Local fallback should fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tidak disimpan', $e->getMessage());
        }
        config(['airbersih.documents_disk' => 'cloud', 'filesystems.disks.cloud.bucket' => 'airbersih-rt05-private']);
        try {
            PrivateFiles::disk();
            $this->fail('Test must reject operational bucket');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('-test', $e->getMessage());
        }
    }

    public function test_large_source_is_preserved_as_verified_cloud_parts(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'air-test-');
        $out = fopen($temp, 'wb');
        for ($n = 0; $n < 41; $n++) {
            fwrite($out, str_repeat((string) ($n % 10), 1024 * 1024));
        }
        fclose($out);
        try {
            $path = PrivateFiles::store($temp, 'imports/large-source.xlsx');
            $this->assertStringEndsWith('.parts.json', $path);
            $this->assertCount(4, PrivateFiles::paths($path));
            PrivateFiles::temporary($path, fn ($full) => $this->assertSame(hash_file('sha256', $temp), hash_file('sha256', $full)));
        } finally {
            unlink($temp);
        }
    }

    public function test_operational_ui_has_no_example_labels_and_exposes_unknown_balance(): void
    {
        $response = $this->actingAs($this->admin)->get('/app/dashboard');
        $response->assertOk()->assertSee('Belum dikonfirmasi')->assertDontSee('SIMULASI')->assertDontSee('dummy')->assertDontSee('Data contoh');
    }

    public function test_browser_form_can_save_unknown_baseline_without_assignment(): void
    {
        $customer = $this->ops->saveCustomer($this->admin, ['name' => 'Pelanggan Uji Form', 'address' => 'Alamat Uji']);
        Livewire::actingAs($this->admin)->test(Workspace::class, ['page' => 'customers'])
            ->set('form.customer_id', (string) $customer->id)->set('form.address', 'Alamat Uji')->set('form.serial', 'M-FORM')
            ->set('form.opening', '')->set('form.baseline_on', '')->set('form.assigned_to', '')->call('saveConnection')->assertHasNoErrors();
        $meter = Meter::where('serial', 'M-FORM')->firstOrFail();
        $this->assertNull($meter->last_value);
        $this->assertNull($meter->last_read_on);
        $this->assertNull(Connection::findOrFail($meter->connection_id)->assigned_to);
    }

    public function test_browser_form_accepts_verified_zero_opening_without_category(): void
    {
        $a = Account::create(['name' => 'Kas Uji', 'kind' => 'cash']);
        Livewire::actingAs($this->admin)->test(Workspace::class, ['page' => 'finance'])
            ->set('form.account_id', (string) $a->id)->set('form.kind', 'opening')->set('form.amount', '0')
            ->set('form.category_id', '')->set('form.description', 'Saldo fisik nol diperiksa')->call('saveEntry')->assertHasNoErrors();
        $this->assertNull(Entry::firstOrFail()->category_id);
        $this->assertSame(0, app(Reports::class)->data('2026-10')['closing']);
    }

    public function test_preparation_distinguishes_missing_and_confirmed_zero_without_mutation(): void
    {
        $a = Account::create(['name' => 'Kas Uji', 'kind' => 'cash']);
        $this->connection();
        $this->connection('0', '2026-10-16');
        $this->connection('10', '2025-05-25');
        $before = app(OperationalReadiness::class)->data();
        $this->assertSame(1, $before['verified_baselines']);
        $this->assertSame(3, $before['active_connections']);
        $this->assertSame(0, $before['confirmed_accounts']);
        $this->assertSame('pending', $before['human_uat']);
        $this->assertSame(0, Entry::count());
        $this->ops->addEntry($this->admin, ['account_id' => $a->id, 'amount' => 0, 'kind' => 'opening', 'entry_date' => '2026-10-16', 'description' => 'Saldo fisik nol terkonfirmasi'], (string) Str::uuid());
        $this->assertSame(1, app(OperationalReadiness::class)->data()['confirmed_accounts']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_pending_backup_cannot_be_queued_twice(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => json_encode(['displayName' => CreateRecoveryBackup::class]), 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        Livewire::actingAs($this->admin)->test(Workspace::class, ['page' => 'settings'])->call('backup')->assertHasErrors(['operation']);
        Queue::assertNotPushed(CreateRecoveryBackup::class);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_preparation_is_visible_to_finance_but_not_officers(): void
    {
        $this->actingAs($this->admin)->get('/app/dashboard')->assertOk()->assertSee('Mulai dari sini');
        $officer = User::create(['name' => 'Petugas Uji', 'email' => Str::uuid().'@example.test', 'password' => 'temporary-test-password', 'role' => 'petugas', 'active' => true]);
        $this->actingAs($officer)->get('/app/dashboard')->assertOk()->assertDontSee('Mulai dari sini');
    }

    public function test_failed_backup_has_visible_status_without_exception_details(): void
    {
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => CreateRecoveryBackup::class]), 'exception' => 'private diagnostic detail must remain server side', 'failed_at' => now()]);
        $this->actingAs($this->admin)->get('/app/settings')->assertOk()->assertSee('Salinan terakhir belum berhasil dibuat')->assertDontSee('private diagnostic detail');
    }
}
