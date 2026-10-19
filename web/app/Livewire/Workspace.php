<?php

namespace App\Livewire;

use App\Jobs\CreateRecoveryBackup;
use App\Jobs\InspectWorkbook;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Audit;
use App\Models\Category;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\Entry;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\MeterChange;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\SourceRow;
use App\Models\Tariff;
use App\Models\User;
use App\Services\MembershipHistory;
use App\Services\OperationalReadiness;
use App\Services\Operations;
use App\Services\PrivateFiles;
use App\Services\Reports;
use App\Services\SourceStaging;
use App\Support\BusinessClock;
use App\Support\CloudNotReady;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class Workspace extends Component
{
    use WithFileUploads,WithPagination;

    #[Locked]
    public string $page = 'dashboard';

    #[Locked]
    public string $key;

    #[Locked]
    public ?int $editId = null;

    #[Locked]
    public string $editingType = '';

    public string $search = '';

    public string $period = '';

    #[Locked]
    public bool $automaticPeriod = true;

    public string $status = '';

    public string $notice = '';

    public string $meterHint = '';

    public string $reason = '';

    public array $form = [];

    public $photo;

    public $workbook;

    public ?int $batchId = null;

    public int $sheetIndex = 0;

    public string $membershipSearch = '';

    public string $membershipStatus = '';

    public function updatedMembershipSearch(): void
    {
        $this->resetPage('membershipPage');
    }

    public function updatedMembershipStatus(): void
    {
        $this->resetPage('membershipPage');
    }

    public array $mapping = ['name' => 'C', 'address' => 'D', 'status' => 'J', 'phone' => 'H'];

    public function mount(string $page = 'dashboard'): void
    {
        abort_unless(in_array($page, ['dashboard', 'customers', 'meters', 'invoices', 'payments', 'finance', 'reports', 'imports', 'settings', 'audit', 'help']), 404);
        $this->page = $page;
        $this->access();
        $this->period = now('Asia/Jakarta')->format('Y-m');
        $this->resetForm();
    }

    private function access(): void
    {
        abort_unless(auth()->check() && auth()->user()->active, 403);
        if (! in_array($this->page, ['dashboard', 'meters', 'help'])) {
            Gate::authorize('finance');
        }
        if ($this->page === 'settings') {
            Gate::authorize('admin');
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPeriod(): void
    {
        $this->automaticPeriod = false;
        BusinessClock::period($this->period);
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function resetForm(): void
    {
        $this->meterHint = '';
        if ($this->photo instanceof TemporaryUploadedFile) {
            $this->photo->delete();
        }
        $this->editId = null;
        $this->editingType = '';
        $this->photo = null;
        $this->key = (string) Str::uuid();
        $this->resetValidation();
        $this->form = ['name' => '', 'phone' => '', 'address' => '', 'customer_id' => '', 'connection_id' => '', 'status' => 'active', 'assigned_to' => '', 'serial' => '', 'opening' => '', 'baseline_on' => '', 'read_on' => now('Asia/Jakarta')->toDateString(), 'due_date' => '', 'zero_confirmed' => false, 'current' => '', 'version' => 1, 'invoice_id' => '', 'amount' => '', 'account_id' => '', 'method' => 'cash', 'paid_on' => now('Asia/Jakarta')->toDateString(), 'kind' => 'expense', 'category_id' => '', 'entry_date' => now('Asia/Jakarta')->toDateString(), 'description' => '', 'from' => '', 'to' => '', 'effective_from' => now('Asia/Jakarta')->startOfMonth()->toDateString(), 'rate' => '10000', 'fee' => '5000', 'email' => '', 'role' => 'petugas', 'active' => true, 'password' => '', 'fee_override' => '', 'reason' => ''];
    }

    public function startOpeningBalance(): void
    {
        Gate::authorize('admin');
        $this->resetForm();
        $this->form['kind'] = 'opening';
        $this->notice = 'Pilih kas atau rekening, lalu isi jumlah uang yang benar-benar tersedia dan tanggal pemeriksaannya.';
    }

    private function perform(callable $fn, string $message = 'Perubahan berhasil disimpan.'): void
    {
        $this->access();
        $this->notice = '';
        $this->resetValidation();
        try {
            $fn();
            $this->notice = $message;
            $this->resetForm();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key, $messages[0]);
            }
        } catch (HttpExceptionInterface $e) {
            if (! in_array($e->getStatusCode(), [409, 422])) {
                throw $e;
            }$this->addError('operation', $e->getMessage() ?: 'Data berubah atau transaksi tidak dapat diproses. Muat ulang.');
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }$this->addError('operation', 'Data sudah tercatat. Periksa daftar sebelum mengulangi.');
        } catch (CloudNotReady $e) {
            $this->addError('operation', $e->getMessage());
        } catch (FilesystemException $e) {
            $this->addError('operation', 'Dokumen belum tersimpan. Periksa koneksi internet, lalu coba unggah kembali. Jika masih gagal, hubungi pengelola aplikasi.');
        }
    }

    public function saveCustomer(): void
    {
        $saved = null;
        $this->perform(function () use (&$saved) {
            $saved = app(Operations::class)->saveCustomer(auth()->user(), $this->form, $this->editingType === 'customer' ? $this->editId : null);
        }, 'Data pelanggan sudah disimpan. Selanjutnya isi Sambungan air rumah.');
        if ($saved) {
            $this->form['customer_id'] = (string) $saved->id;
            $this->form['address'] = $saved->address;
        }
    }

    public function editCustomer(int $id): void
    {
        Gate::authorize('finance');
        $this->resetForm();
        $r = Customer::findOrFail($id);
        $this->editId = $id;
        $this->editingType = 'customer';
        $this->form = array_merge($this->form, $r->only(['name', 'phone', 'address', 'version']));
    }

    public function saveConnection(): void
    {
        $this->perform(fn () => app(Operations::class)->saveConnection(auth()->user(), $this->form, $this->editingType === 'connection' ? $this->editId : null), 'Sambungan air sudah disimpan. Buka Catat meter untuk mengisi angka meter pertama atau mencatat pembacaan berikutnya.');
    }

    public function editConnection(int $id): void
    {
        Gate::authorize('finance');
        $this->resetForm();
        $r = Connection::findOrFail($id);
        $this->editId = $id;
        $this->editingType = 'connection';
        $this->form = array_merge($this->form, $r->only(['customer_id', 'address', 'status', 'assigned_to', 'version']));
    }

    public function selectConnection(): void
    {
        $c = app(Operations::class)->connection(auth()->user(), (int) $this->form['connection_id']);
        $m = Meter::where('connection_id', $c->id)->where('active', true)->firstOrFail();
        $r = Reading::where('meter_id', $m->id)->where('period', $this->period)->first();
        $this->form['current'] = $r?->current ?? '';
        $this->meterHint = $m->last_value === null
            ? (Gate::allows('finance') ? 'Angka meter pertama rumah ini belum diisi. Isi bagian Angka meter pertama sebelum mencatat pembacaan berikutnya.' : 'Angka meter pertama belum diisi. Minta pengurus mencatat angka pertama rumah ini dahulu.')
            : 'Angka meter yang terakhir disimpan: '.$m->last_value.' m³ pada '.($m->last_read_on ?? 'tanggal yang belum dicatat').'. Cocokkan dengan angka terbaru yang terlihat pada meter.';
        $this->form['version'] = $r?->version ?? 1;
    }

    public function saveReading(): void
    {
        $this->perform(function () {
            $this->validate(['photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120']);
            app(Operations::class)->recordReading(auth()->user(), (int) $this->form['connection_id'], $this->period, (string) $this->form['current'], $this->photo, (int) $this->form['version'], $this->form['read_on']);
        });
    }

    public function review(int $id, int $version): void
    {
        $this->perform(fn () => app(Operations::class)->review(auth()->user(), $id, $version), 'Catatan meter sudah diperiksa. Selanjutnya buat rancangan tagihan air.');
    }

    public function saveBaseline(): void
    {
        $this->perform(function () {
            $this->validate(['photo' => 'required|image|max:5120']);
            app(Operations::class)->baseline(auth()->user(), (int) $this->form['connection_id'], (string) $this->form['opening'], (string) $this->form['baseline_on'], (string) $this->form['reason'], $this->photo);
        }, 'Angka meter pertama sudah disimpan beserta tanggalnya. Tidak ada tagihan masa lalu yang dibuat dari pencatatan ini.');
    }

    public function reviewSource(int $id, string $action): void
    {
        $this->perform(fn () => app(SourceStaging::class)->review(auth()->user(), $id, $action, $this->reason), 'Hasil pemeriksaan catatan lama sudah disimpan.');
    }

    public function backup(): void
    {
        $this->perform(function () {
            Gate::authorize('admin');
            PrivateFiles::disk();
            DB::transaction(function () {
                DB::statement('SELECT pg_advisory_xact_lock(6052027)');
                abort_if(app(OperationalReadiness::class)->backupPending(), 409, 'Salinan cadangan sedang dibuat. Tunggu sampai selesai sebelum meminta salinan berikutnya.');
                CreateRecoveryBackup::dispatch(auth()->id());
            });
        }, 'Pembuatan salinan cadangan sudah diminta. Tunggu sampai ada keterangan selesai sebelum menutup aplikasi.');
    }

    public function requestMeterChange(): void
    {
        $this->perform(fn () => app(Operations::class)->requestMeterChange(auth()->user(), (int) $this->form['connection_id'], $this->period, (string) $this->form['serial'], (string) $this->form['opening'], (string) $this->form['reason']), 'Usulan penggantian meter menunggu persetujuan pengurus utama.');
    }

    public function rejectMeterChange(int $id): void
    {
        $this->perform(fn () => app(Operations::class)->rejectMeterChange(auth()->user(), $id, $this->reason));
    }

    public function approveMeterChange(int $id): void
    {
        $this->perform(fn () => app(Operations::class)->approveMeterChange(auth()->user(), $id));
    }

    public function draftInvoice(): void
    {
        $this->perform(fn () => app(Operations::class)->draftInvoice(auth()->user(), (int) $this->form['connection_id'], $this->period, $this->key, $this->form['due_date'] ?: null, (bool) $this->form['zero_confirmed']), 'Rancangan tagihan sudah dibuat. Buka Lihat tagihan, periksa rincian, lalu terbitkan jika sudah benar.');
    }

    public function issue(int $id, int $version): void
    {
        $this->perform(fn () => app(Operations::class)->issue(auth()->user(), $id, $version), 'Tagihan sudah diterbitkan. Berkas untuk dicetak sedang disiapkan.');
    }

    public function voidInvoice(int $id, int $version): void
    {
        $this->perform(fn () => app(Operations::class)->voidInvoice(auth()->user(), $id, $version, $this->reason));
    }

    public function selectInvoice(): void
    {
        Gate::authorize('finance');
        $this->form['amount'] = Invoice::findOrFail((int) $this->form['invoice_id'])->total;
    }

    public function pay(): void
    {
        $this->perform(fn () => app(Operations::class)->pay(auth()->user(), (int) $this->form['invoice_id'], $this->form, $this->photo, $this->key), 'Pembayaran dicatat. Transfer memerlukan konfirmasi penerimaan.');
    }

    public function confirmPayment(int $id): void
    {
        $this->perform(fn () => app(Operations::class)->confirmPayment(auth()->user(), $id));
    }

    public function rejectPayment(int $id): void
    {
        $this->perform(fn () => app(Operations::class)->rejectPayment(auth()->user(), $id, $this->reason));
    }

    public function reversePayment(int $id): void
    {
        $this->perform(fn () => app(Operations::class)->reversePayment(auth()->user(), $id, $this->reason));
    }

    public function saveEntry(): void
    {
        $this->perform(fn () => app(Operations::class)->addEntry(auth()->user(), $this->form, $this->key, $this->photo));
    }

    public function reverseEntry(int $id): void
    {
        $this->perform(fn () => app(Operations::class)->reverseEntry(auth()->user(), $id, $this->reason));
    }

    public function transfer(): void
    {
        $this->perform(fn () => app(Operations::class)->transfer(auth()->user(), (int) $this->form['from'], (int) $this->form['to'], (int) $this->form['amount'], $this->form['entry_date'], $this->key));
    }

    public function saveTariff(): void
    {
        $this->perform(fn () => app(Operations::class)->addTariff(auth()->user(), $this->form));
    }

    public function overrideFee(): void
    {
        $this->perform(fn () => app(Operations::class)->overrideFee(auth()->user(), (int) $this->form['connection_id'], $this->form['fee_override'] === '' ? null : (int) $this->form['fee_override'], $this->form['reason']));
    }

    public function saveCategory(): void
    {
        $this->perform(function () {
            Gate::authorize('finance');
            $v = Validator::make($this->form, ['name' => 'required|string|max:80|unique:categories,name'])->validate();
            $c = Category::create($v);
            app(Operations::class)->audit(auth()->user(), 'category.created', 'category:'.$c->id);
        });
    }

    public function editUser(int $id): void
    {
        Gate::authorize('admin');
        $u = User::findOrFail($id);
        $this->editId = $id;
        $this->form = array_merge($this->form, $u->only(['name', 'email', 'role', 'active']), ['password' => '']);
    }

    public function saveUser(): void
    {
        $this->perform(function () {
            Gate::authorize('admin');
            $v = Validator::make($this->form, ['name' => 'required|string|max:100', 'email' => 'required|email|max:150|unique:users,email,'.$this->editId, 'role' => 'required|in:superadmin,keuangan,petugas', 'active' => 'required|boolean', 'password' => ($this->editId ? 'nullable' : 'required').'|string|min:12|max:128'])->validate();
            DB::transaction(function () use ($v) {
                $admins = User::where('role', 'superadmin')->where('active', true)->lockForUpdate()->get();
                $u = $this->editId ? User::lockForUpdate()->findOrFail($this->editId) : new User;
                abort_if($u->id === auth()->id() && (! $v['active'] || $v['role'] !== 'superadmin'), 422, 'Akun sendiri tidak boleh dinonaktifkan atau diturunkan.');
                abort_if($u->role === 'superadmin' && $u->active && $admins->count() === 1 && (! $v['active'] || $v['role'] !== 'superadmin'), 422, 'Minimal satu superadmin aktif.');
                $u->fill(collect($v)->except('password')->all());
                if (! empty($v['password'])) {
                    $u->password = Hash::make($v['password']);
                }
                if (! empty($v['password']) || ! $u->active) {
                    $u->setRememberToken(Str::random(60));
                }
                $u->save();
                if (! empty($v['password']) || ! $u->active) {
                    DB::table('sessions')->where('user_id', $u->id)->delete();
                }
                app(Operations::class)->audit(auth()->user(), 'user.saved', 'user:'.$u->id, ['role' => $u->role, 'active' => $u->active]);
            });
        });
    }

    public function uploadWorkbook(): void
    {
        $this->access();
        Gate::authorize('finance');
        $this->validate(['workbook' => 'required|file|extensions:xlsx|max:61440']);
        $hash = PrivateFiles::withUpload($this->workbook, fn ($path) => hash_file('sha256', $path));
        $batch = ImportBatch::where('hash', $hash)->first();
        if ($batch) {
            $this->batchId = $batch->id;
            $this->notice = 'Berkas Excel ini sudah ada. Menampilkan catatan yang sama agar tidak terduplikasi.';
            $this->workbook->delete();
            $this->workbook = null;

            return;
        }
        $path = PrivateFiles::withUpload($this->workbook, fn ($source) => PrivateFiles::store($source, 'imports/'.Str::uuid().'.xlsx'));
        try {
            $batch = ImportBatch::create(['hash' => $hash, 'name' => mb_substr(basename($this->workbook->getClientOriginalName()), 0, 200), 'path' => $path, 'operator_id' => auth()->id()]);
        } catch (\Throwable $e) {
            Storage::disk(PrivateFiles::disk())->delete($path);
            throw $e;
        }
        $this->batchId = $batch->id;
        $this->workbook->delete();
        $this->workbook = null;
        InspectWorkbook::dispatch($batch->id);
        $this->notice = 'Berkas Excel sedang menunggu pemeriksaan. Catatannya belum dipakai untuk tagihan atau buku kas.';
    }

    public function showBatch(int $id): void
    {
        Gate::authorize('finance');
        ImportBatch::findOrFail($id);
        $this->batchId = $id;
        $this->sheetIndex = 0;
    }

    public function retryBatch(int $id): void
    {
        Gate::authorize('finance');
        $b = ImportBatch::findOrFail($id);
        abort_unless($b->status === 'failed', 409);
        $b->update(['status' => 'queued']);
        InspectWorkbook::dispatch($id);
        $this->notice = 'Pemeriksaan berkas Excel akan dicoba kembali.';
    }

    public function saveMapping(): void
    {
        $this->perform(function () {
            Gate::authorize('finance');
            $this->validate(['mapping.name' => 'required|regex:/^[A-Z]{1,3}$/', 'mapping.address' => 'required|regex:/^[A-Z]{1,3}$/', 'mapping.status' => 'nullable|regex:/^[A-Z]{1,3}$/', 'mapping.phone' => 'nullable|regex:/^[A-Z]{1,3}$/']);
            $b = ImportBatch::findOrFail($this->batchId);
            abort_unless($b->status === 'ready' && isset($b->summary['sheets'][$this->sheetIndex]), 422);
            $s = $b->summary;
            $s['mapping'] = ['sheet' => $s['sheets'][$this->sheetIndex]['name'], 'columns' => $this->mapping];
            $b->update(['summary' => $s]);
            app(Operations::class)->audit(auth()->user(), 'import.mapping.saved', 'import:'.$b->id);
        }, 'Pilihan kolom Excel disimpan. Periksa catatan sebelum digunakan.');
    }

    public function render()
    {
        $this->access();
        if ($this->automaticPeriod) {
            $this->period = BusinessClock::today()->format('Y-m');
        }
        BusinessClock::period($this->period);
        $finance = Gate::allows('finance');
        $readiness = $finance ? app(OperationalReadiness::class)->data() : null;
        $q = mb_substr($this->search, 0, 100);
        $connections = Connection::query()->when(! $finance, fn ($b) => $b->where('assigned_to', auth()->id()));
        $filtered = (clone $connections)->join('customers', 'customers.id', '=', 'connections.customer_id')->select('connections.*', 'customers.name as customer_name')->when($q, fn ($b) => $b->where(fn ($s) => $s->where('connections.code', 'ilike', '%'.$q.'%')->orWhere('customers.name', 'ilike', '%'.$q.'%')));
        $ids = (clone $connections)->select('id');
        $rows = null;
        $data = [];
        if ($this->page === 'customers') {
            $rows = (clone $filtered)->when($this->status, fn ($b) => $b->where('connections.status', $this->status))->orderBy('connections.id')->paginate(15);
            $data['customers'] = Customer::when($q, fn ($b) => $b->where('name', 'ilike', '%'.$q.'%'))->orderBy('id')->limit(100)->get();
        }
        if ($this->page === 'meters') {
            $rows = BusinessClock::history(Reading::join('connections', 'connections.id', '=', 'readings.connection_id')->join('customers', 'customers.id', '=', 'connections.customer_id')->join('meters', 'meters.id', '=', 'readings.meter_id')->select('readings.*', 'connections.code as connection_code', 'customers.name as customer_name', 'meters.serial as meter_serial')->whereIn('readings.connection_id', $ids)->where('readings.period', $this->period), 'readings.read_on')->when($q, fn ($query) => $query->where(fn ($query) => $query->where('customers.name', 'ilike', '%'.$q.'%')->orWhere('connections.code', 'ilike', '%'.$q.'%')))->orderByDesc('readings.id')->paginate(15);
            $data['changes'] = MeterChange::whereIn('connection_id', (clone $connections)->select('id'))->orderByDesc('id')->limit(20)->get();
        }
        if ($this->page === 'invoices') {
            $data['reviewedReadingCount'] = Reading::where('period', $this->period)->where('status', 'reviewed')->count();
            $rows = BusinessClock::history(Invoice::where('period', $this->period), 'created_at')->when($this->status, fn ($b) => $b->where('status', $this->status))->when($q, fn ($b) => $b->where(fn ($b) => $b->where('number', 'ilike', '%'.$q.'%')->orWhere('snapshot->customer', 'ilike', '%'.$q.'%')))->orderByDesc('id')->paginate(15);
        }
        if ($this->page === 'payments') {
            $rows = BusinessClock::history(Payment::join('invoices', 'invoices.id', '=', 'payments.invoice_id')->select('payments.*', 'invoices.number as invoice_number')->selectRaw("invoices.snapshot->>'customer' as customer_name")->whereBetween('payments.paid_on', [$this->period.'-01', CarbonImmutable::parse($this->period.'-01')->endOfMonth()->toDateString()]), 'payments.paid_on')->when($this->status, fn ($b) => $b->where('payments.status', $this->status))->when($q, fn ($b) => $b->where(fn ($b) => $b->where('invoices.number', 'ilike', '%'.$q.'%')->orWhere('invoices.snapshot->customer', 'ilike', '%'.$q.'%')))->orderByDesc('payments.id')->paginate(15);
            $data['unpaid'] = Invoice::where('status', 'issued')->orderByDesc('id')->limit(500)->get();
        }
        if (in_array($this->page, ['finance', 'reports'])) {
            $data['report'] = app(Reports::class)->data($this->period);
            $rows = BusinessClock::history(Entry::whereBetween('entry_date', [$this->period.'-01', CarbonImmutable::parse($this->period.'-01')->endOfMonth()->toDateString()]), 'entry_date')->when($this->status, fn ($b) => $b->where('kind', $this->status))->when($q, fn ($b) => $b->where('description', 'ilike', '%'.$q.'%'))->orderByDesc('id')->paginate(20);
        }
        if ($this->page === 'audit') {
            $rows = BusinessClock::history(Audit::leftJoin('users', 'users.id', '=', 'audits.actor_id')->select('audits.*', 'users.name as actor_name'), 'audits.created_at')->when($q, fn ($b) => $b->where('users.name', 'ilike', '%'.$q.'%'))->orderByDesc('audits.id')->paginate(25);
        }
        if ($this->page === 'settings') {
            $data['recovery'] = DB::table('recovery_copies')->orderByDesc('id')->first();
            $data['backupPending'] = app(OperationalReadiness::class)->backupPending();
            $data['backupFailures'] = app(OperationalReadiness::class)->backupFailures();
            $data['users'] = User::orderBy('id')->get();
            $data['tariffs'] = Tariff::orderByDesc('effective_from')->get();
        }
        if ($this->page === 'imports') {
            $data['membershipHistory'] = app(MembershipHistory::class)->visible();
            $data['membershipRows'] = null;
            if ($data['membershipHistory']) {
                $membershipQuery = SourceRow::where('import_batch_id', $data['membershipHistory']['batch_id'])->where('sheet', MembershipHistory::SHEET)->where('kind', 'membership_history')->where('status', 'recorded_history');
                $membershipSearch = mb_substr(trim($this->membershipSearch), 0, 100);
                $data['membershipRows'] = $membershipQuery
                    ->when($membershipSearch !== '', fn ($query) => $query->where(function ($query) use ($membershipSearch) {
                        foreach (['C', 'D', 'E', 'G'] as $column) {
                            $query->orWhereRaw("cells->'{$column}'->>'value' ILIKE ?", ['%'.$membershipSearch.'%']);
                        }
                    }))
                    ->when(in_array($this->membershipStatus, ['Aktif', 'Pasif'], true), fn ($query) => $query->whereRaw("cells->'F'->>'value' = ?", [$this->membershipStatus]))
                    ->orderBy('row_number')->paginate(15, ['*'], 'membershipPage');
            }
            $data['sourceInvoices'] = Attachment::where('owner_type', 'source_invoice')->get();
            $data['batches'] = ImportBatch::orderByDesc('id')->limit(30)->get();
            $data['batch'] = $this->batchId ? ImportBatch::find($this->batchId) : null;
            $data['sourceRows'] = SourceRow::where('kind', '<>', 'membership_history')->when($this->batchId, fn ($q) => $q->where('import_batch_id', $this->batchId))->orderBy('id')->paginate(20);
        }
        if ($this->page === 'dashboard') {
            $data['connectionCount'] = (clone $connections)->where('status', 'active')->count();
            $data['readingCount'] = Reading::whereIn('connection_id', $ids)->where('period', $this->period)->where('status', 'reviewed')->distinct()->count('connection_id');
            $data['recent'] = BusinessClock::history(Reading::join('connections', 'connections.id', '=', 'readings.connection_id')->select('readings.*', 'connections.code as connection_code')->whereIn('readings.connection_id', (clone $connections)->select('id')), 'readings.read_on')->orderByDesc('readings.id')->limit(6)->get();
            if ($finance) {
                $data['readiness'] = $readiness;
                $data['report'] = app(Reports::class)->data($this->period);
                $data['invoiceCount'] = Invoice::where('period', $this->period)->where('status', 'issued')->count();
            }
        }

        $data['attachmentMap'] = collect();
        $data['reversedIds'] = collect();
        $data['balances'] = collect();
        $owner = match ($this->page) {
            'meters' => 'reading','payments' => 'payment','finance','reports' => 'entry',default => null
        };
        if ($owner && $rows) {
            $data['attachmentMap'] = Attachment::where('owner_type', $owner)->whereIn('owner_id', $rows->pluck('id'))->get()->groupBy('owner_id');
        }
        if (in_array($this->page, ['finance', 'reports'])) {
            $data['reversedIds'] = Entry::whereIn('reverses_id', $rows->pluck('id'))->pluck('reverses_id');
            $data['balances'] = Entry::where('entry_date', '<=', CarbonImmutable::parse($this->period.'-01')->endOfMonth()->toDateString())->selectRaw('account_id, SUM(amount) as balance')->groupBy('account_id')->pluck('balance', 'account_id');
        }

        return view('livewire.workspace', $data + [
            'readiness' => $readiness,
            'cloudReady' => config('airbersih.mode') !== 'operational' || (filled(config('filesystems.disks.cloud.key')) && filled(config('filesystems.disks.cloud.secret'))),
            'historyWindow' => BusinessClock::window(),
            'baselineMeters' => $this->page === 'meters' ? Meter::whereIn('connection_id', (clone $connections)->select('id'))->where('active', true)->get() : collect(),
            'rows' => $rows, 'finance' => $finance,
            'connectionOptions' => in_array($this->page, ['meters', 'invoices', 'settings']) ? (clone $connections)->join('customers', 'customers.id', '=', 'connections.customer_id')->select('connections.*', 'customers.name as customer_name')->orderByDesc('connections.id')->limit(1000)->get() : collect(),
            'customerOptions' => $this->page === 'customers' ? Customer::orderByDesc('id')->limit(1000)->get() : collect(),
            'officers' => $this->page === 'customers' ? User::where('role', 'petugas')->where('active', true)->get() : collect(),
            'accounts' => in_array($this->page, ['payments', 'finance', 'reports']) ? Account::all() : collect(),
            'categories' => $this->page === 'finance' ? Category::all() : collect(),
        ])->layout('layouts.app');
    }
}
