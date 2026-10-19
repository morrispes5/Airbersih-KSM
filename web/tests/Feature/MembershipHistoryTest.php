<?php

namespace Tests\Feature;

use App\Livewire\Workspace;
use App\Models\Audit;
use App\Models\ImportBatch;
use App\Models\SourceRow;
use App\Models\User;
use App\Services\MembershipHistory;
use App\Services\SourceStaging;
use App\Support\DatabaseGuard;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MembershipHistoryTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private ImportBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseGuard::check(true);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Jakarta'));
        config(['airbersih.mode' => 'operational']);
        $this->admin = User::create(['name' => 'Pengurus Uji', 'email' => Str::uuid().'@example.test', 'password' => 'password-only-for-testing', 'role' => 'superadmin', 'active' => true]);
        $this->batch = ImportBatch::create(['name' => 'Sumber Uji', 'hash' => MembershipHistory::SOURCE_HASH, 'path' => 'test-source.xlsx', 'status' => 'ready', 'summary' => [], 'operator_id' => $this->admin->id]);
        $rows = [3 => ['B' => 'Update  : 08 Dec 2025'], 5 => ['B' => 'No', 'C' => 'Nama Anggota', 'D' => 'Alamat Lengkap', 'E' => 'Jabatan', 'F' => 'Status', 'G' => 'No HP']];
        for ($i = 1; $i <= 51; $i++) {
            $rows[$i + 5] = ['B' => $i, 'C' => 'Anggota Uji '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'D' => 'Alamat Uji', 'E' => $i === 1 ? 'Ketua Uji' : 'Anggota', 'F' => $i <= 41 ? 'Aktif' : 'Pasif', 'G' => 'Telepon belum diisi'];
        }
        $insert = [];
        foreach ($rows as $row => $values) {
            $cells = array_map(fn ($value) => ['value' => $value, 'formula' => null], $values);
            $insert[] = ['import_batch_id' => $this->batch->id, 'sheet' => MembershipHistory::SHEET, 'row_number' => $row, 'kind' => 'reference', 'cells' => json_encode($cells), 'issues' => '[]', 'status' => 'review', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('source_rows')->insert($insert);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function reject(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Pencatatan seharusnya ditolak.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_complete_snapshot_preserves_cells_without_operational_posting(): void
    {
        $before = SourceRow::orderBy('row_number')->get()->pluck('cells', 'row_number')->all();
        $result = app(MembershipHistory::class)->record($this->admin);
        $this->assertSame(51, $result['newly_recorded']);
        $this->assertSame(41, $result['active']);
        $this->assertSame(10, $result['passive']);
        $this->assertSame('2025-12-08', $result['as_of']);
        $this->assertSame(51, SourceRow::where('status', 'recorded_history')->where('period', '2025-12')->count());
        $this->assertSame($before, SourceRow::orderBy('row_number')->get()->pluck('cells', 'row_number')->all());
        foreach (['customers', 'connections', 'meters', 'readings', 'invoices', 'payments', 'entries'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    public function test_repeated_import_of_identical_source_is_idempotent(): void
    {
        app(MembershipHistory::class)->record($this->admin);
        $result = app(MembershipHistory::class)->record($this->admin);
        $this->assertSame(0, $result['newly_recorded']);
        $this->assertSame(53, SourceRow::count());
        $this->assertSame(1, Audit::where('action', 'history.membership.recorded')->count());
    }

    public function test_wrong_date_and_incomplete_source_are_rejected_atomically(): void
    {
        $header = SourceRow::where('row_number', 3)->firstOrFail();
        $original = $header->cells;
        $header->update(['cells' => ['B' => ['value' => 'Update : 08 Dec 2026', 'formula' => null]]]);
        $this->reject(fn () => app(MembershipHistory::class)->record($this->admin), 422);
        $header->update(['cells' => $original]);
        SourceRow::where('row_number', 56)->delete();
        $this->reject(fn () => app(MembershipHistory::class)->record($this->admin), 422);
        $this->assertSame(0, SourceRow::where('status', 'recorded_history')->count());
        $this->assertNull($this->batch->fresh()->summary['membership_history'] ?? null);
    }

    public function test_existing_hold_decision_is_not_overwritten(): void
    {
        SourceRow::where('row_number', 6)->update(['decision' => 'Perlu klarifikasi sumber']);
        $this->reject(fn () => app(MembershipHistory::class)->record($this->admin), 409);
        $this->assertSame(0, SourceRow::where('status', 'recorded_history')->count());
    }

    public function test_invalid_status_is_not_inferred_and_no_rows_are_posted(): void
    {
        $row = SourceRow::where('row_number', 6)->firstOrFail();
        $cells = $row->cells;
        $cells['F']['value'] = 'Tidak diketahui';
        $row->update(['cells' => $cells]);
        $this->reject(fn () => app(MembershipHistory::class)->record($this->admin), 422);
        $this->assertSame(0, SourceRow::where('status', 'recorded_history')->count());
    }

    public function test_officers_cannot_record_or_view_private_history(): void
    {
        $officer = User::create(['name' => 'Petugas Uji', 'email' => Str::uuid().'@example.test', 'password' => 'password-only-for-testing', 'role' => 'petugas', 'active' => true]);
        $this->actingAs($officer)->get('/app/imports')->assertForbidden()->assertDontSee('Anggota Uji');
        $this->actingAs($officer)->get('/documents/sources/'.$this->batch->id)->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(MembershipHistory::class)->record($officer);
    }

    public function test_history_search_status_and_pagination_are_separate_from_staging(): void
    {
        app(MembershipHistory::class)->record($this->admin);
        Livewire::actingAs($this->admin)->test(Workspace::class, ['page' => 'imports'])
            ->assertSee('Riwayat anggota Desember 2025')->assertSee('8 Desember 2025')
            ->assertViewHas('membershipRows', fn ($rows) => $rows->total() === 51 && $rows->count() === 15)
            ->call('gotoPage', 4, 'membershipPage')
            ->assertViewHas('membershipRows', fn ($rows) => $rows->count() === 6)
            ->set('membershipStatus', 'Pasif')
            ->assertViewHas('membershipRows', fn ($rows) => $rows->total() === 10 && $rows->currentPage() === 1)
            ->set('membershipSearch', 'Uji 51')
            ->assertViewHas('membershipRows', fn ($rows) => $rows->total() === 1)
            ->assertSee('Anggota Uji 51');
    }

    public function test_recorded_history_cannot_be_reclassified_as_current_master(): void
    {
        app(MembershipHistory::class)->record($this->admin);
        $row = SourceRow::where('row_number', 6)->firstOrFail();
        $this->reject(fn () => app(SourceStaging::class)->review($this->admin, $row->id, 'hold', 'Uji perubahan status historis'), 422);
        $this->assertSame('recorded_history', $row->fresh()->status);
    }

    public function test_rolling_year_hides_old_snapshot_without_deleting_it(): void
    {
        app(MembershipHistory::class)->record($this->admin);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-08 23:59:00', 'Asia/Jakarta'));
        $this->assertNotNull(app(MembershipHistory::class)->visible());
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-09 00:01:00', 'Asia/Jakarta'));
        $this->assertNull(app(MembershipHistory::class)->visible());
        $this->assertSame(51, SourceRow::where('status', 'recorded_history')->count());
    }
}
