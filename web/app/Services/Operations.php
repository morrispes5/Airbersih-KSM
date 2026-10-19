<?php

namespace App\Services;

use App\Jobs\GenerateInvoicePdf;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Audit;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\Entry;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\MeterChange;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Tariff;
use App\Models\User;
use App\Support\BusinessClock;
use App\Support\Volume;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class Operations
{
    public function finance(User $u): void
    {
        Gate::forUser($u)->authorize('finance');
    }

    public function admin(User $u): void
    {
        Gate::forUser($u)->authorize('admin');
    }

    public function connection(User $u, int $id): Connection
    {
        $c = Connection::findOrFail($id);
        abort_unless($u->active && (in_array($u->role, ['superadmin', 'keuangan']) || $c->assigned_to === $u->id), 403);

        return $c;
    }

    public function audit(User $u, string $action, string $subject, array $details = []): void
    {
        Audit::create(['actor_id' => $u->id, 'action' => $action, 'subject' => $subject, 'details' => $details]);
    }

    private function stale($r, int $v): void
    {
        abort_unless($r->version === $v, 409, 'Form kedaluwarsa. Muat ulang data.');
    }

    public function period(string $p): string
    {
        Validator::make(['period' => $p], ['period' => 'required|date_format:Y-m'])->validate();

        return $p;
    }

    private function reason(string $r): void
    {
        Validator::make(['reason' => $r], ['reason' => 'required|string|min:8|max:500'])->validate();
    }

    private function key(string $key): void
    {
        Validator::make(compact('key'), ['key' => 'required|uuid'])->validate();
    }

    private function accountReady(int $id, string $date): void
    {
        if (config('airbersih.mode') === 'operational') {
            BusinessClock::date($date);
            $account = Account::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($account->opening_by && $account->opening_on <= $date, 422, 'Konfirmasikan saldo awal akun (termasuk bila benar-benar nol) sebelum mencatat transaksi.');
        }
    }

    private function attach(UploadedFile $f, string $type, int $id): void
    {
        Validator::make(['file' => $f], ['file' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120'])->validate();
        $path = $f->store('attachments', PrivateFiles::disk());
        try {
            Attachment::create(['owner_type' => $type, 'owner_id' => $id, 'path' => $path, 'mime' => $f->getMimeType(), 'bytes' => $f->getSize(), 'checksum' => hash_file('sha256', $f->getRealPath())]);
        } catch (\Throwable $e) {
            Storage::disk(PrivateFiles::disk())->delete($path);
            throw $e;
        }
    }

    public function saveCustomer(User $u, array $d, ?int $id = null): Customer
    {
        $this->finance($u);
        $v = Validator::make($d, ['name' => 'required|string|max:150', 'phone' => 'nullable|string|max:30', 'address' => 'required|string|max:500', 'version' => 'nullable|integer'])->validate();

        return DB::transaction(function () use ($u, $v, $id) {
            $c = $id ? Customer::lockForUpdate()->findOrFail($id) : new Customer;
            if ($id) {
                $this->stale($c, (int) ($v['version'] ?? 0));
                $c->version++;
            } else {
                $c->code = 'C-'.strtoupper(bin2hex(random_bytes(5)));
            }
            $c->fill(collect($v)->except('version')->all());
            $c->verified_at = now();
            $c->save();
            $this->audit($u, 'customer.saved', 'customer:'.$c->id);

            return $c;
        });
    }

    public function saveConnection(User $u, array $d, ?int $id = null): Connection
    {
        $this->finance($u);
        $v = Validator::make($d, ['customer_id' => 'required|exists:customers,id', 'address' => 'required|string|max:500', 'status' => 'required|in:active,waiting,inactive', 'assigned_to' => 'nullable|exists:users,id', 'serial' => ($id ? 'nullable' : 'required').'|string|max:80', 'opening' => 'nullable|string', 'baseline_on' => 'nullable|date_format:Y-m-d', 'version' => 'nullable|integer'])->validate();
        if (! empty($v['assigned_to'])) {
            abort_unless(User::whereKey($v['assigned_to'])->where('active', true)->where('role', 'petugas')->exists(), 422, 'Pilih petugas aktif.');
        }
        $v['assigned_to'] = empty($v['assigned_to']) ? null : $v['assigned_to'];
        $v['baseline_on'] = empty($v['baseline_on']) ? null : $v['baseline_on'];

        return DB::transaction(function () use ($u, $v, $id) {
            $c = $id ? Connection::lockForUpdate()->findOrFail($id) : new Connection;
            if ($id) {
                $this->stale($c, (int) ($v['version'] ?? 0));
                $c->version++;
            } else {
                $c->code = 'SR-'.strtoupper(bin2hex(random_bytes(5)));
            }
            $c->fill(collect($v)->only(['customer_id', 'address', 'status', 'assigned_to'])->all());
            $c->save();
            if (! $id) {
                $known = isset($v['opening']) && $v['opening'] !== '';
                if ($known && config('airbersih.mode') === 'operational') {
                    abort_unless(! empty($v['baseline_on']), 422, 'Tanggal baseline wajib untuk angka awal yang diketahui.');
                    BusinessClock::date($v['baseline_on']);
                }
                Meter::create(['connection_id' => $c->id, 'serial' => $v['serial'], 'last_value' => $known ? Volume::decimal(Volume::units($v['opening'])) : null, 'last_read_on' => $v['baseline_on'] ?? null, 'baseline_by' => $known ? $u->id : null, 'active' => true]);
            }
            $this->audit($u, 'connection.saved', 'connection:'.$c->id);

            return $c;
        });
    }

    public function baseline(User $u, int $cid, string $value, string $date, string $reason, UploadedFile $photo): void
    {
        $this->finance($u);
        BusinessClock::date($date);
        $this->reason($reason);
        $value = Volume::decimal(Volume::units($value));
        DB::transaction(function () use ($u, $cid, $value, $date, $reason, $photo) {
            Connection::whereKey($cid)->lockForUpdate()->firstOrFail();
            $m = Meter::where('connection_id', $cid)->where('active', true)->lockForUpdate()->firstOrFail();
            abort_if(Reading::where('meter_id', $m->id)->where(function ($q) use ($cid) {
                $q->where('status', 'draft')->orWhereNotIn('period', Invoice::where('connection_id', $cid)->whereIn('status', ['issued', 'paid'])->select('period'));
            })->exists(), 422, 'Selesaikan bacaan yang belum ditagihkan sebelum menetapkan baseline baru.');
            abort_if($m->last_read_on && $date < $m->last_read_on, 422, 'Tanggal baseline tidak boleh mundur.');
            abort_if($m->last_value !== null && Volume::units($value) < Volume::units($m->last_value), 422, 'Angka turun memerlukan workflow penggantian meter.');
            $before = $m->only(['last_value', 'last_read_on']);
            $m->update(['last_value' => $value, 'last_read_on' => $date, 'baseline_by' => $u->id]);
            $this->attach($photo, 'baseline', $m->id);
            $this->audit($u, 'meter.baseline.verified', 'meter:'.$m->id, compact('before', 'value', 'date', 'reason'));
        });
    }

    public function recordReading(User $u, int $cid, string $period, string $current, UploadedFile $photo, ?int $version = null, ?string $readOn = null): Reading
    {
        $this->connection($u, $cid);
        BusinessClock::period($period);
        $readOn ??= config('airbersih.mode') === 'operational' ? null : BusinessClock::today()->toDateString();
        if (config('airbersih.mode') === 'operational') {
            abort_unless($readOn, 422, 'Tanggal pemeriksaan wajib diisi.');
            BusinessClock::date($readOn);
            abort_unless(substr($readOn, 0, 7) === $period, 422, 'Tanggal baca harus berada pada periode yang dipilih.');
        }
        $units = Volume::units($current);

        return DB::transaction(function () use ($u, $cid, $period, $units, $photo, $version, $readOn) {
            $c = Connection::lockForUpdate()->findOrFail($cid);
            $this->connection($u, $cid);
            abort_unless($c->status === 'active', 422, 'Sambungan belum aktif.');
            abort_if(Invoice::where('connection_id', $cid)->where('period', $period)->where('status', '<>', 'void')->exists(), 409, 'Batalkan invoice sebelum mengubah bacaan.');
            abort_if(Reading::where('connection_id', $cid)->where('period', '>', $period)->exists(), 422, 'Pencatatan harus berurutan.');
            abort_if(Reading::where('connection_id', $cid)->where('period', '<', $period)->where('status', 'draft')->exists(), 422, 'Review bacaan periode sebelumnya sebelum memulai periode baru.');
            $m = Meter::where('connection_id', $cid)->where('active', true)->lockForUpdate()->firstOrFail();
            $r = Reading::where('meter_id', $m->id)->where('period', $period)->first();
            if ($r) {
                $this->stale($r, $version ?? 0);
                abort_unless($r->status === 'draft', 409, 'Bacaan sudah final.');
            }
            abort_if($m->last_value === null, 422, 'Angka awal meter belum dikonfirmasi. Catat baseline lebih dahulu.');
            if (config('airbersih.mode') === 'operational') {
                $from = $r?->read_from ?? $m->last_read_on;
                abort_unless($from && $m->baseline_by, 422, 'Baseline bertanggal belum diverifikasi.');
                $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($readOn), false);
                abort_unless($days >= 0 && $days <= 62, 422, 'Jeda pembacaan melebihi 62 hari atau tanggal mundur. Periksa dan tetapkan baseline baru tanpa menagih selisih lama.');
            }
            $prev = $r ? Volume::units($r->previous) : Volume::units($m->last_value);
            abort_if($units < $prev, 422, 'Angka akhir lebih kecil. Gunakan workflow ganti/reset meter.');
            $r ??= new Reading(['connection_id' => $cid, 'meter_id' => $m->id, 'period' => $period, 'previous' => Volume::decimal($prev), 'status' => 'draft']);
            $r->read_from = $r->read_from ?? $m->last_read_on;
            $r->read_on = $readOn;
            $r->current = Volume::decimal($units);
            $r->operator_id = $u->id;
            $r->version = $r->exists ? $r->version + 1 : 1;
            $r->save();
            $this->attach($photo, 'reading', $r->id);
            $this->audit($u, 'reading.saved', 'reading:'.$r->id);

            return $r;
        });
    }

    public function review(User $u, int $id, int $version): Reading
    {
        $this->finance($u);
        $initial = Reading::findOrFail($id);

        return DB::transaction(function () use ($u, $initial, $version) {
            Connection::whereKey($initial->connection_id)->lockForUpdate()->firstOrFail();
            $r = Reading::lockForUpdate()->findOrFail($initial->id);
            $this->stale($r, $version);
            abort_unless($r->status === 'draft', 409, 'Bacaan sudah direview.');
            $m = Meter::lockForUpdate()->findOrFail($r->meter_id);
            abort_unless($m->active && Volume::units($m->last_value) === Volume::units($r->previous), 409, 'Meter sudah berubah.');
            abort_unless(Attachment::where('owner_type', 'reading')->where('owner_id', $r->id)->exists(), 422, 'Foto wajib ada.');
            $r->update(['status' => 'reviewed', 'reviewer_id' => $u->id, 'version' => $r->version + 1]);
            $m->update(['last_value' => $r->current, 'last_read_on' => $r->read_on]);
            $this->audit($u, 'reading.reviewed', 'reading:'.$r->id);

            return $r;
        });
    }

    public function requestMeterChange(User $u, int $cid, string $period, string $serial, string $opening, string $reason): MeterChange
    {
        $this->connection($u, $cid);
        $this->period($period);
        $this->reason($reason);
        Validator::make(compact('serial'), ['serial' => 'required|string|max:80'])->validate();
        $start = Volume::decimal(Volume::units($opening));

        return DB::transaction(function () use ($u, $cid, $period, $serial, $start, $reason) {
            Connection::whereKey($cid)->lockForUpdate()->firstOrFail();
            $this->connection($u, $cid);
            $m = Meter::where('connection_id', $cid)->where('active', true)->firstOrFail();
            abort_unless(Reading::where('meter_id', $m->id)->where('period', $period)->where('status', 'reviewed')->exists(), 422, 'Review bacaan penutup meter lama dahulu.');
            abort_if(Invoice::where('connection_id', $cid)->where('period', $period)->where('status', '<>', 'void')->exists(), 409, 'Invoice berjalan sudah ada.');
            $r = MeterChange::create(['connection_id' => $cid, 'meter_id' => $m->id, 'period' => $period, 'serial' => $serial, 'opening' => $start, 'reason' => $reason, 'operator_id' => $u->id]);
            $this->audit($u, 'meter.change.requested', 'meter_change:'.$r->id);

            return $r;
        });
    }

    public function approveMeterChange(User $u, int $id): void
    {
        $this->admin($u);
        $initial = MeterChange::findOrFail($id);
        DB::transaction(function () use ($u, $initial) {
            Connection::whereKey($initial->connection_id)->lockForUpdate()->firstOrFail();
            $r = MeterChange::lockForUpdate()->findOrFail($initial->id);
            $m = Meter::lockForUpdate()->findOrFail($r->meter_id);
            abort_unless($r->status === 'pending' && $m->active, 409);
            abort_if(Invoice::where('connection_id', $m->connection_id)->where('period', '>=', $r->period)->where('status', '<>', 'void')->exists() || Reading::where('meter_id', $m->id)->where('period', '>', $r->period)->exists(), 409, 'Ada transaksi sesudah periode penggantian.');
            $m->update(['active' => false]);
            $closed = Reading::where('meter_id', $m->id)->where('period', $r->period)->where('status', 'reviewed')->firstOrFail();
            Meter::create(['connection_id' => $m->connection_id, 'serial' => $r->serial, 'last_value' => $r->opening, 'last_read_on' => $closed->read_on, 'baseline_by' => $u->id, 'active' => true]);
            $r->update(['status' => 'approved', 'approver_id' => $u->id]);
            $this->audit($u, 'meter.change.approved', 'meter_change:'.$r->id);
        });
    }

    public function rejectMeterChange(User $u, int $id, string $reason): void
    {
        $this->admin($u);
        $this->reason($reason);
        DB::transaction(function () use ($u, $id, $reason) {
            $r = MeterChange::lockForUpdate()->findOrFail($id);
            abort_unless($r->status === 'pending', 409);
            $r->update(['status' => 'rejected', 'approver_id' => $u->id]);
            $this->audit($u, 'meter.change.rejected', 'meter_change:'.$id, compact('reason'));
        });
    }

    public function addTariff(User $u, array $d): Tariff
    {
        $this->admin($u);
        $v = Validator::make($d, ['effective_from' => 'required|date_format:Y-m-d', 'rate' => 'required|integer|min:1|max:100000000', 'fee' => 'required|integer|min:0|max:1000000000'])->validate();

        return DB::transaction(function () use ($u, $v) {
            User::whereKey($u->id)->lockForUpdate()->firstOrFail();
            abort_if(Tariff::where('effective_from', '>=', $v['effective_from'])->exists(), 422, 'Tanggal harus sesudah tarif terakhir.');
            $t = Tariff::create($v + ['approved_by' => $u->id]);
            $this->audit($u, 'tariff.approved', 'tariff:'.$t->id, $v);

            return $t;
        });
    }

    public function overrideFee(User $u, int $id, ?int $fee, string $reason): void
    {
        $this->admin($u);
        $this->reason($reason);
        Validator::make(compact('fee'), ['fee' => 'nullable|integer|min:0|max:1000000000'])->validate();
        DB::transaction(function () use ($u, $id, $fee, $reason) {
            $c = Connection::lockForUpdate()->findOrFail($id);
            $c->update(['fee_override' => $fee, 'fee_reason' => $reason, 'version' => $c->version + 1]);
            $this->audit($u, 'connection.fee.approved', 'connection:'.$id, compact('fee', 'reason'));
        });
    }

    public function draftInvoice(User $u, int $cid, string $period, string $key, ?string $dueDate = null, bool $zeroConfirmed = false): Invoice
    {
        $this->finance($u);
        BusinessClock::period($period);
        $this->key($key);
        if (config('airbersih.mode') === 'operational') {
            Validator::make(['due_date' => $dueDate], ['due_date' => 'required|date_format:Y-m-d'])->validate();
        }

        return DB::transaction(function () use ($u, $cid, $period, $key, $dueDate, $zeroConfirmed) {
            $c = Connection::lockForUpdate()->findOrFail($cid);
            if ($i = Invoice::where('idempotency_key', $key)->first()) {
                abort_unless($i->connection_id === $cid && $i->period === $period, 409);

                return $i;
            }
            abort_unless($c->status === 'active', 422, 'Sambungan tidak aktif.');
            abort_if(MeterChange::where('connection_id', $cid)->where('status', 'pending')->exists(), 422, 'Selesaikan usulan ganti meter.');
            $rs = Reading::where('connection_id', $cid)->where('period', $period)->orderBy('id')->get();
            $m = Meter::where('connection_id', $cid)->where('active', true)->firstOrFail();
            abort_unless($rs->isNotEmpty() && $rs->every(fn ($r) => $r->status === 'reviewed') && $rs->contains('meter_id', $m->id), 422, 'Semua segmen termasuk meter aktif harus direview.');
            $tariffDate = config('airbersih.mode') === 'operational' ? $rs->min('read_from') : $period.'-01';
            if (config('airbersih.mode') === 'operational') {
                abort_unless($rs->every(fn ($r) => $r->read_from && $r->read_on), 422, 'Rentang baca belum diverifikasi.');
                abort_unless($dueDate >= $rs->max('read_on') && $dueDate >= BusinessClock::today()->toDateString(), 422, 'Jatuh tempo harus pada/setelah tanggal baca dan tanggal hari ini.');
                abort_if(Tariff::where('effective_from', '>', $tariffDate)->where('effective_from', '<=', $rs->max('read_on'))->exists(), 422, 'Rentang baca melewati perubahan tarif. Review pembagian tarif sebelum menerbitkan tagihan.');
            }
            $t = Tariff::where('effective_from', '<=', $tariffDate)->orderByDesc('effective_from')->first();
            abort_unless($t, 422, 'Tarif periode belum tersedia.');
            $units = $rs->sum(fn ($r) => Volume::units($r->current) - Volume::units($r->previous));
            abort_if(config('airbersih.mode') === 'operational' && $units === 0 && ! $zeroConfirmed, 422, 'Pemakaian nol: konfirmasikan angka fisik dan biaya admin pada preview.');
            $subtotal = Volume::charge($units, $t->rate);
            $fee = $c->fee_override ?? $t->fee;
            $customer = Customer::findOrFail($c->customer_id);
            $snap = ['customer' => $customer->name, 'customer_code' => $customer->code, 'address' => $c->address, 'connection_code' => $c->code, 'tariff_id' => $t->id, 'rate' => $t->rate, 'fee' => $fee, 'fee_reason' => $c->fee_reason, 'volume' => Volume::decimal($units), 'subtotal' => $subtotal, 'segments' => $rs->map(fn ($r) => ['meter' => Meter::findOrFail($r->meter_id)->serial, 'read_from' => $r->read_from, 'read_on' => $r->read_on, 'previous' => $r->previous, 'current' => $r->current, 'volume' => Volume::decimal(Volume::units($r->current) - Volume::units($r->previous))])->all()];
            $i = Invoice::create(['connection_id' => $cid, 'period' => $period, 'idempotency_key' => $key, 'snapshot' => $snap, 'total' => $subtotal + $fee, 'due_date' => $dueDate ?? CarbonImmutable::createFromFormat('!Y-m', $period)->addMonth()->day(20)->toDateString()]);
            $this->audit($u, 'invoice.drafted', 'invoice:'.$i->id);

            return $i;
        });
    }

    public function issue(User $u, int $id, int $version): Invoice
    {
        $this->finance($u);

        return DB::transaction(function () use ($u, $id, $version) {
            $i = Invoice::lockForUpdate()->findOrFail($id);
            $this->stale($i, $version);
            abort_unless($i->status === 'draft', 409);
            $i->update(['number' => (config('airbersih.mode') === 'operational' ? 'BB/' : 'SIM/').str_replace('-', '', $i->period).'/'.str_pad((string) $i->id, 6, '0', STR_PAD_LEFT), 'issued_at' => now(), 'status' => $i->total === 0 ? 'paid' : 'issued', 'version' => $i->version + 1]);
            $this->audit($u, 'invoice.issued', 'invoice:'.$id);
            GenerateInvoicePdf::dispatch($id)->afterCommit();

            return $i;
        });
    }

    public function voidInvoice(User $u, int $id, int $version, string $reason): void
    {
        $this->admin($u);
        $this->reason($reason);
        DB::transaction(function () use ($u, $id, $version, $reason) {
            $i = Invoice::lockForUpdate()->findOrFail($id);
            $this->stale($i, $version);
            abort_if($i->status === 'void' || Payment::where('invoice_id', $id)->whereIn('status', ['pending', 'confirmed'])->exists(), 409, 'Selesaikan atau reversal pembayaran dahulu.');
            $i->update(['status' => 'void', 'void_reason' => $reason, 'version' => $i->version + 1]);
            $this->audit($u, 'invoice.void', 'invoice:'.$id, compact('reason'));
        });
    }

    public function pay(User $u, int $iid, array $d, ?UploadedFile $proof, string $key): Payment
    {
        $this->finance($u);
        $this->key($key);
        $v = Validator::make($d, ['amount' => 'required|integer|min:1', 'method' => 'required|in:cash,transfer', 'paid_on' => 'required|date_format:Y-m-d', 'account_id' => 'required|exists:accounts,id'])->validate();

        return DB::transaction(function () use ($u, $iid, $v, $proof, $key) {
            $i = Invoice::lockForUpdate()->findOrFail($iid);
            if ($p = Payment::where('idempotency_key', $key)->first()) {
                abort_unless($p->invoice_id === $iid && $p->amount === (int) $v['amount'] && $p->method === $v['method'] && $p->account_id === (int) $v['account_id'] && $p->paid_on->toDateString() === $v['paid_on'], 409, 'Permintaan yang sama berisi data berbeda.');

                return $p;
            }
            abort_unless($i->status === 'issued' && (int) $v['amount'] === $i->total, 422, 'Hanya pelunasan penuh invoice terbit yang diterima.');
            if (config('airbersih.mode') === 'operational') {
                BusinessClock::date($v['paid_on']);
                abort_if($v['paid_on'] < $i->issued_at->timezone('Asia/Jakarta')->toDateString(), 422, 'Tanggal pembayaran tidak boleh mendahului penerbitan invoice.');
            }
            abort_if(Payment::where('invoice_id', $iid)->whereIn('status', ['pending', 'confirmed'])->exists(), 409, 'Pembayaran sudah tercatat.');
            abort_unless(Account::findOrFail($v['account_id'])->kind === ($v['method'] === 'cash' ? 'cash' : 'bank'), 422, 'Akun tidak sesuai metode pembayaran.');
            $this->accountReady((int) $v['account_id'], $v['paid_on']);
            abort_if($v['method'] === 'transfer' && ! $proof, 422, 'Bukti transfer wajib diunggah.');
            $p = Payment::create($v + ['invoice_id' => $iid, 'idempotency_key' => $key, 'operator_id' => $u->id, 'status' => 'pending']);
            if ($proof) {
                $this->attach($proof, 'payment', $p->id);
            }if ($p->method === 'cash') {
                $this->postPayment($u, $p, $i);
            }$this->audit($u, 'payment.recorded', 'payment:'.$p->id);

            return $p;
        });
    }

    private function postPayment(User $u, Payment $p, Invoice $i): void
    {
        abort_unless($i->status === 'issued' && $p->status === 'pending', 409);
        Account::whereKey($p->account_id)->lockForUpdate()->firstOrFail();
        Entry::create(['account_id' => $p->account_id, 'kind' => 'payment', 'amount' => $p->amount, 'entry_date' => $p->paid_on, 'description' => 'Pelunasan '.$i->number, 'source_key' => 'payment:'.$p->id, 'operator_id' => $u->id]);
        $p->update(['status' => 'confirmed', 'confirmed_by' => $u->id]);
        $i->update(['status' => 'paid', 'version' => $i->version + 1]);
    }

    public function confirmPayment(User $u, int $id): void
    {
        $this->finance($u);
        $initial = Payment::findOrFail($id);
        DB::transaction(function () use ($u, $initial) {
            $i = Invoice::lockForUpdate()->findOrFail($initial->invoice_id);
            $p = Payment::lockForUpdate()->findOrFail($initial->id);
            if ($p->status === 'confirmed') {
                return;
            }$this->postPayment($u, $p, $i);
            $this->audit($u, 'payment.confirmed', 'payment:'.$p->id);
        });
    }

    public function rejectPayment(User $u, int $id, string $reason): void
    {
        $this->finance($u);
        $this->reason($reason);
        $initial = Payment::findOrFail($id);
        DB::transaction(function () use ($u, $initial, $reason) {
            Invoice::whereKey($initial->invoice_id)->lockForUpdate()->firstOrFail();
            $p = Payment::lockForUpdate()->findOrFail($initial->id);
            abort_unless($p->status === 'pending', 409);
            $p->update(['status' => 'rejected', 'reason' => $reason]);
            $this->audit($u, 'payment.rejected', 'payment:'.$p->id, compact('reason'));
        });
    }

    public function reversePayment(User $u, int $id, string $reason): void
    {
        $this->admin($u);
        $this->reason($reason);
        $initial = Payment::findOrFail($id);
        DB::transaction(function () use ($u, $initial, $reason) {
            $i = Invoice::lockForUpdate()->findOrFail($initial->invoice_id);
            $p = Payment::lockForUpdate()->findOrFail($initial->id);
            abort_unless($p->status === 'confirmed', 409);
            $e = Entry::where('source_key', 'payment:'.$p->id)->firstOrFail();
            Entry::create(['account_id' => $e->account_id, 'kind' => 'reversal', 'amount' => -$e->amount, 'entry_date' => now('Asia/Jakarta')->toDateString(), 'description' => $reason, 'source_key' => 'reversal:payment:'.$p->id, 'operator_id' => $u->id, 'reverses_id' => $e->id]);
            $p->update(['status' => 'reversed', 'reason' => $reason]);
            $i->update(['status' => 'issued', 'version' => $i->version + 1]);
            $this->audit($u, 'payment.reversed', 'payment:'.$p->id, compact('reason'));
        });
    }

    public function addEntry(User $u, array $d, string $key, ?UploadedFile $proof = null): Entry
    {
        $this->finance($u);
        $this->key($key);
        $v = Validator::make($d, ['account_id' => 'required|exists:accounts,id', 'category_id' => 'nullable|exists:categories,id', 'kind' => 'required|in:income,expense,opening', 'amount' => 'required|integer|min:0|max:100000000000', 'entry_date' => 'required|date_format:Y-m-d', 'description' => 'required|string|min:3|max:500'])->validate();
        $v['category_id'] = empty($v['category_id']) ? null : $v['category_id'];
        abort_if($v['kind'] !== 'opening' && (int) $v['amount'] === 0, 422, 'Transaksi harus lebih dari nol.');
        if ($v['kind'] === 'opening') {
            $this->admin($u);
        }abort_if($v['kind'] === 'expense' && empty($v['category_id']), 422, 'Pilih kategori pengeluaran.');

        return DB::transaction(function () use ($u, $v, $key, $proof) {
            Account::whereKey($v['account_id'])->lockForUpdate()->firstOrFail();
            if ($v['kind'] !== 'opening') {
                $this->accountReady((int) $v['account_id'], $v['entry_date']);
            } elseif (config('airbersih.mode') === 'operational') {
                BusinessClock::date($v['entry_date']);
            }
            $source = $v['kind'] === 'opening' ? 'opening:'.$v['account_id'] : 'manual:'.$key;
            $amount = $v['kind'] === 'expense' ? -(int) $v['amount'] : (int) $v['amount'];
            if ($e = Entry::where('source_key', $source)->first()) {
                abort_unless($v['kind'] !== 'opening' && $e->account_id === (int) $v['account_id'] && $e->amount === $amount && $e->kind === $v['kind'] && ($e->category_id ?: null) === (empty($v['category_id']) ? null : (int) $v['category_id']) && $e->description === $v['description'] && $e->entry_date->toDateString() === $v['entry_date'], 409);

                return $e;
            }
            abort_if($v['kind'] === 'opening' && Entry::where('account_id', $v['account_id'])->exists(), 422, 'Saldo awal hanya sebelum transaksi akun.');
            $v['amount'] = $amount;
            $e = Entry::create($v + ['source_key' => $source, 'operator_id' => $u->id]);
            if ($v['kind'] === 'opening') {
                Account::whereKey($v['account_id'])->update(['opening_on' => $v['entry_date'], 'opening_by' => $u->id]);
            }
            if ($proof) {
                $this->attach($proof, 'entry', $e->id);
            }$this->audit($u, 'entry.posted', 'entry:'.$e->id);

            return $e;
        });
    }

    public function reverseEntry(User $u, int $id, string $reason): void
    {
        $this->admin($u);
        $this->reason($reason);
        DB::transaction(function () use ($u, $id, $reason) {
            $e = Entry::lockForUpdate()->findOrFail($id);
            abort_unless(in_array($e->kind, ['income', 'expense', 'opening']), 422, 'Gunakan koreksi pembayaran atau transfer.');
            abort_if(config('airbersih.mode') === 'operational' && $e->kind === 'opening', 422, 'Koreksi saldo awal membutuhkan rekonsiliasi pengurus; tidak dapat menghapus kepastian saldo melalui reversal biasa.');
            Entry::create(['account_id' => $e->account_id, 'category_id' => $e->category_id, 'kind' => 'reversal', 'amount' => -$e->amount, 'entry_date' => now('Asia/Jakarta')->toDateString(), 'description' => $reason, 'source_key' => 'reversal:entry:'.$id, 'operator_id' => $u->id, 'reverses_id' => $id]);
            $this->audit($u, 'entry.reversed', 'entry:'.$id, compact('reason'));
        });
    }

    public function transfer(User $u, int $from, int $to, int $amount, string $date, string $key): void
    {
        $this->finance($u);
        $this->key($key);
        Validator::make(compact('from', 'to', 'amount', 'date'), ['from' => 'required|exists:accounts,id', 'to' => 'required|different:from|exists:accounts,id', 'amount' => 'required|integer|min:1|max:100000000000', 'date' => 'required|date_format:Y-m-d'])->validate();
        DB::transaction(function () use ($u, $from, $to, $amount, $date, $key) {
            Account::whereIn('id', [$from, $to])->orderBy('id')->lockForUpdate()->get();
            $this->accountReady($from, $date);
            $this->accountReady($to, $date);
            if ($e = Entry::where('source_key', 'transfer:'.$key.':out')->first()) {
                abort_unless($e->account_id === $from && $e->amount === -$amount && $e->entry_date->toDateString() === $date && Entry::where('source_key', 'transfer:'.$key.':in')->where('account_id', $to)->exists(), 409);

                return;
            }foreach ([[$from, -$amount, 'out'], [$to, $amount, 'in']] as [$account,$value,$side]) {
                Entry::create(['account_id' => $account, 'kind' => 'transfer', 'amount' => $value, 'entry_date' => $date, 'description' => 'Transfer internal kas/bank', 'source_key' => 'transfer:'.$key.':'.$side, 'operator_id' => $u->id]);
            }$this->audit($u, 'accounts.transferred', 'transfer:'.$key);
        });
    }
}
