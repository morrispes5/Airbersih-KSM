<?php

namespace Tests\Feature;

use App\Livewire\Workspace;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Audit;
use App\Models\Category;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\Entry;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\User;
use App\Services\Operations;
use App\Services\Reports;
use App\Support\DatabaseGuard;
use App\Support\Volume;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $finance;

    private User $officer;

    private Connection $connection;

    private Operations $ops;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseGuard::check(true);
        Queue::fake();
        Storage::fake('local');
        $this->ops = app(Operations::class);
        $this->admin = User::create(['name' => 'Admin Dummy', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('dummy-password-123'), 'role' => 'superadmin', 'active' => true]);
        $this->finance = User::create(['name' => 'Keuangan Dummy', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('dummy-password-123'), 'role' => 'keuangan', 'active' => true]);
        $this->officer = User::create(['name' => 'Petugas Dummy', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('dummy-password-123'), 'role' => 'petugas', 'active' => true]);
        $c = $this->ops->saveCustomer($this->admin, ['name' => 'Pelanggan Uji', 'address' => 'Alamat dummy', 'phone' => '00123']);
        $this->connection = $this->ops->saveConnection($this->admin, ['customer_id' => $c->id, 'address' => 'Alamat dummy', 'status' => 'active', 'assigned_to' => $this->officer->id, 'serial' => 'M-TEST', 'opening' => '227']);
        $this->ops->addTariff($this->admin, ['effective_from' => '2026-01-01', 'rate' => 10000, 'fee' => 5000]);
        Account::create(['name' => 'Kas Uji', 'kind' => 'cash']);
        Account::create(['name' => 'Bank Uji', 'kind' => 'bank']);
    }

    private function invoice(): Invoice
    {
        $r = $this->ops->recordReading($this->officer, $this->connection->id, '2026-10', '277', UploadedFile::fake()->image('dummy.png'));
        $this->ops->review($this->finance, $r->id, $r->version);
        $i = $this->ops->draftInvoice($this->finance, $this->connection->id, '2026-10', (string) Str::uuid());

        return $this->ops->issue($this->finance, $i->id, $i->version);
    }

    private function payment(Invoice $i, string $method = 'cash', ?string $key = null): Payment
    {
        return $this->ops->pay($this->finance, $i->id, ['account_id' => Account::where('kind', $method === 'cash' ? 'cash' : 'bank')->first()->id, 'method' => $method, 'amount' => $i->total, 'paid_on' => '2026-10-16'], $method === 'transfer' ? UploadedFile::fake()->image('proof.png') : null, $key ?? (string) Str::uuid());
    }

    public function test_complete_cash_flow_snapshot_and_reversal(): void
    {
        $i = $this->invoice();
        $this->assertSame(505000, $i->total);
        $this->assertSame('50.000', $i->snapshot['volume']);
        $this->assertSame('2026-11-20', $i->due_date->toDateString());
        $this->assertSame(0, (int) Entry::sum('amount'));
        $snapshot = $i->snapshot;
        $this->ops->addTariff($this->admin, ['effective_from' => '2026-10-02', 'rate' => 20000, 'fee' => 10000]);
        Customer::whereKey($this->connection->customer_id)->update(['name' => 'Nama berubah']);
        $this->assertSame($snapshot, $i->fresh()->snapshot);
        $p = $this->payment($i);
        $this->assertSame('paid', $i->fresh()->status);
        $this->assertSame(505000, (int) Entry::sum('amount'));
        $this->ops->reversePayment($this->admin, $p->id, 'Koreksi penerimaan dummy');
        $this->assertSame('issued', $i->fresh()->status);
        $this->assertSame(0, (int) Entry::sum('amount'));
        $this->assertSame(2, Entry::count());
        $this->assertTrue(Audit::where('action', 'payment.reversed')->exists());
    }

    public function test_transfer_is_not_cash_until_confirmed_and_confirmation_is_idempotent(): void
    {
        $i = $this->invoice();
        $p = $this->payment($i, 'transfer');
        $this->assertSame('pending', $p->status);
        $this->assertSame('issued', $i->fresh()->status);
        $this->assertSame(0, Entry::count());
        $this->ops->confirmPayment($this->finance, $p->id);
        $this->ops->confirmPayment($this->finance, $p->id);
        $this->assertSame(1, Entry::count());
        $this->assertSame('paid', $i->fresh()->status);
    }

    public function test_payment_retry_does_not_double_post(): void
    {
        $i = $this->invoice();
        $key = (string) Str::uuid();
        $first = $this->payment($i, 'cash', $key);
        $second = $this->payment($i, 'cash', $key);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Entry::count());
    }

    public function test_changed_payload_for_same_payment_key_is_rejected(): void
    {
        $i = $this->invoice();
        $key = (string) Str::uuid();
        $this->payment($i, 'cash', $key);
        $this->expectException(HttpException::class);
        $this->ops->pay($this->finance, $i->id, ['account_id' => Account::where('kind', 'cash')->first()->id, 'method' => 'cash', 'amount' => 1, 'paid_on' => '2026-10-16'], null, $key);
    }

    public function test_partial_payment_is_rejected_without_ledger_entries(): void
    {
        $i = $this->invoice();
        try {
            $this->ops->pay($this->finance, $i->id, ['account_id' => Account::first()->id, 'method' => 'cash', 'amount' => 5000, 'paid_on' => '2026-10-16'], null, (string) Str::uuid());
            $this->fail('Partial accepted');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }$this->assertSame(0, Entry::count());
        $this->assertSame('issued', $i->fresh()->status);
    }

    public function test_negative_meter_and_non_image_file_are_rejected(): void
    {
        foreach ([['226', UploadedFile::fake()->image('dummy.png')], ['277', UploadedFile::fake()->create('bad.php', 1, 'application/x-php')]] as [$number,$photo]) {
            try {
                $this->ops->recordReading($this->officer, $this->connection->id, '2026-10', $number, $photo);
                $this->fail('Invalid reading accepted');
            } catch (HttpException|ValidationException $e) {
                $this->assertSame(0, Reading::count());
            }
        }
    }

    public function test_operator_scope_and_financial_pages_are_protected(): void
    {
        $other = User::create(['name' => 'Other Dummy', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('dummy-password-123'), 'role' => 'petugas', 'active' => true]);
        $this->actingAs($other)->get('/app/invoices')->assertForbidden();
        $this->get('/app/settings')->assertForbidden();
        try {
            $this->ops->recordReading($other, $this->connection->id, '2026-10', '277', UploadedFile::fake()->image('dummy.png'));
            $this->fail('Scope bypass');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->actingAs($this->officer);
        Livewire::test(Workspace::class, ['page' => 'meters'])->call('issue', 1, 1)->assertForbidden();
    }

    public function test_stale_review_and_duplicate_invoice_are_rejected(): void
    {
        $i = $this->invoice();
        try {
            $this->ops->draftInvoice($this->finance, $this->connection->id, '2026-10', (string) Str::uuid());
            $this->fail('Duplicate invoice');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->getCode());
        }
        $r = Reading::first();
        try {
            $this->ops->review($this->finance, $r->id, 1);
            $this->fail('Stale review accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }$this->assertSame(1, Invoice::count());
    }

    public function test_meter_change_adds_segments_without_negative_usage(): void
    {
        $r = $this->ops->recordReading($this->officer, $this->connection->id, '2026-10', '250', UploadedFile::fake()->image('dummy.png'));
        $this->ops->review($this->finance, $r->id, 1);
        $change = $this->ops->requestMeterChange($this->officer, $this->connection->id, '2026-10', 'NEW-METER', '0', 'Penggantian meter simulasi');
        $this->ops->approveMeterChange($this->admin, $change->id);
        $next = $this->ops->recordReading($this->officer, $this->connection->id, '2026-10', '27', UploadedFile::fake()->image('dummy.png'));
        $this->ops->review($this->finance, $next->id, 1);
        $i = $this->ops->draftInvoice($this->finance, $this->connection->id, '2026-10', (string) Str::uuid());
        $this->assertSame(505000, $i->total);
        $this->assertCount(2, $i->snapshot['segments']);
        $this->assertSame(1, Meter::where('active', true)->count());
    }

    public function test_zero_usage_and_fee_waiver_close_without_cash(): void
    {
        $this->ops->overrideFee($this->admin, $this->connection->id, 0, 'Pengecualian simulasi admin');
        $r = $this->ops->recordReading($this->officer, $this->connection->id, '2026-10', '227', UploadedFile::fake()->image('dummy.png'));
        $this->ops->review($this->finance, $r->id, 1);
        $i = $this->ops->draftInvoice($this->finance, $this->connection->id, '2026-10', (string) Str::uuid());
        $i = $this->ops->issue($this->finance, $i->id, $i->version);
        $this->assertSame(0, $i->total);
        $this->assertSame('paid', $i->status);
        $this->assertSame(0, Entry::count());
    }

    public function test_internal_transfer_balances_and_does_not_inflate_revenue(): void
    {
        $a = Account::first();
        $b = Account::orderByDesc('id')->first();
        $this->ops->addEntry($this->admin, ['account_id' => $a->id, 'kind' => 'opening', 'amount' => 1000000, 'entry_date' => '2026-10-01', 'description' => 'Saldo awal dummy'], (string) Str::uuid());
        $key = (string) Str::uuid();
        $this->ops->transfer($this->finance, $a->id, $b->id, 100000, '2026-10-16', $key);
        $this->ops->transfer($this->finance, $a->id, $b->id, 100000, '2026-10-16', $key);
        $report = app(Reports::class)->data('2026-10');
        $this->assertSame(1000000, $report['closing']);
        $this->assertSame(0, $report['income']);
        $this->assertSame(100000, $report['transfer_in']);
        $this->assertSame(3, Entry::count());
    }

    public function test_pdf_and_exports_are_private_and_use_snapshot_amounts(): void
    {
        $i = $this->invoice();
        $this->get('/documents/invoices/'.$i->id)->assertRedirect('/login');
        $this->actingAs($this->officer)->get('/documents/invoices/'.$i->id)->assertForbidden();
        $response = $this->actingAs($this->finance)->get('/documents/invoices/'.$i->id);
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->get('/documents/report?period=2026-10&format=csv')->assertOk();
        $this->get('/documents/report?period=invalid')->assertSessionHasErrors('period');
        $a = Attachment::first();
        $other = User::create(['name' => 'Other', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('dummy-password-123'), 'role' => 'petugas', 'active' => true]);
        $this->actingAs($other)->get('/documents/attachments/'.$a->id)->assertForbidden();
    }

    public function test_database_guard_rejects_wrong_target_before_query(): void
    {
        config(['database.connections.pgsql.database' => 'morrizstore_production']);
        $this->expectException(\RuntimeException::class);
        DatabaseGuard::check(true);
    }

    public function test_fractional_volume_and_rounding_are_exact(): void
    {
        $this->assertSame(1234, Volume::units('1.234'));
        $this->assertSame('1.234', Volume::decimal(1234));
        $this->assertSame(1, Volume::charge(1, 500));
        $this->assertSame(12340, Volume::charge(1234, 10000));
    }

    public function test_destructive_migration_command_rejects_development_target_before_query(): void
    {
        $argv = $_SERVER['argv'];
        $environment = app()['env'];
        $connection = config('database.connections.pgsql');
        try {
            $_SERVER['argv'] = ['artisan', 'migrate:fresh'];
            app()['env'] = 'migration';
            config(['database.connections.pgsql.database' => 'airbersih_dev', 'database.connections.pgsql.username' => 'airbersih_dev_migrator']);
            try {
                DatabaseGuard::check();
                $this->fail('Development fresh migration was allowed.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Target database ditolak', $e->getMessage());
            }
        } finally {
            $_SERVER['argv'] = $argv;
            app()['env'] = $environment;
            config(['database.connections.pgsql' => $connection]);
        }
    }

    public function test_all_workspace_pages_render_for_admin(): void
    {
        $this->actingAs($this->admin);
        foreach (['dashboard', 'customers', 'meters', 'invoices', 'payments', 'finance', 'reports', 'imports', 'settings', 'audit'] as $page) {
            $this->get('/app/'.$page)->assertOk()->assertSee('SIMULASI');
        }
    }

    public function test_previous_draft_blocks_later_meter_period(): void
    {
        $this->ops->recordReading($this->officer, $this->connection->id, '2026-09', '250', UploadedFile::fake()->image('dummy.png'));
        try {
            $this->ops->recordReading($this->officer, $this->connection->id, '2026-10', '277', UploadedFile::fake()->image('dummy.png'));
            $this->fail('Later draft accepted');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(1, Reading::count());
    }

    public function test_rejected_meter_change_does_not_block_billing(): void
    {
        $r = $this->ops->recordReading($this->officer, $this->connection->id, '2026-10', '277', UploadedFile::fake()->image('dummy.png'));
        $this->ops->review($this->finance, $r->id, 1);
        $change = $this->ops->requestMeterChange($this->officer, $this->connection->id, '2026-10', 'NEW', '0', 'Usulan penggantian salah');
        $this->ops->rejectMeterChange($this->admin, $change->id, 'Penggantian tidak diperlukan');
        $i = $this->ops->draftInvoice($this->finance, $this->connection->id, '2026-10', (string) Str::uuid());
        $this->assertSame(505000, $i->total);
        $this->assertSame(1, Meter::where('active', true)->count());
    }

    public function test_expense_proof_and_reversal_keep_cash_reconcilable(): void
    {
        $category = Category::create(['name' => 'Operasional dummy']);
        $e = $this->ops->addEntry($this->finance, ['account_id' => Account::first()->id, 'category_id' => $category->id, 'kind' => 'expense', 'amount' => 5000, 'entry_date' => '2026-10-16', 'description' => 'Biaya simulasi'], (string) Str::uuid(), UploadedFile::fake()->image('proof.png'));
        $this->assertTrue(Attachment::where('owner_type', 'entry')->where('owner_id', $e->id)->exists());
        $this->ops->reverseEntry($this->admin, $e->id, 'Koreksi biaya simulasi');
        $this->assertSame(0, (int) Entry::sum('amount'));
    }

    public function test_inactive_account_cannot_use_workspace(): void
    {
        $this->admin->update(['active' => false]);
        $this->actingAs($this->admin)->get('/app/dashboard')->assertRedirect('/login');
        $this->expectException(AuthorizationException::class);
        $this->ops->finance($this->admin);
    }

    public function test_database_failure_has_clear_message_without_query_details(): void
    {
        $this->mock(Reports::class, function ($m) {
            $m->shouldReceive('data')->andThrow(new QueryException('pgsql', 'SELECT private_query_details', [], new \PDOException('connection failed')));
        });
        $this->actingAs($this->finance)->get('/documents/report?period=2026-10')->assertStatus(503)->assertSee('Penyimpanan data belum dapat dihubungi')->assertDontSee('private_query_details');
    }

    public function test_business_period_uses_jakarta_at_utc_month_boundary(): void
    {
        $this->travelTo(Carbon::parse('2026-10-31 18:00:00 UTC'));
        $this->actingAs($this->admin);
        Livewire::test(Workspace::class, ['page' => 'meters'])->assertSet('period', '2026-11')->assertSet('form.paid_on', '2026-11-01');
    }
}
