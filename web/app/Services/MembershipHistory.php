<?php

namespace App\Services;

use App\Models\ImportBatch;
use App\Models\SourceRow;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MembershipHistory
{
    public const SOURCE_HASH = 'f538298286132c4232b95b3bb90c8318c77d1f514c18f083f03712a88e4a06ba';

    public const SHEET = 'Data_Pelanggan KSM Banyu Bening';

    public const AS_OF = '2025-12-08';

    public function inspect(): array
    {
        $batch = ImportBatch::where('hash', self::SOURCE_HASH)->firstOrFail();

        return $this->validate($batch, $this->sourceRows($batch)->get());
    }

    public function record(User $actor): array
    {
        app(Operations::class)->finance($actor);

        return DB::transaction(function () use ($actor) {
            $batch = ImportBatch::where('hash', self::SOURCE_HASH)->lockForUpdate()->firstOrFail();
            $rows = $this->sourceRows($batch)->lockForUpdate()->get();
            $metadata = $this->validate($batch, $rows);
            $existing = $batch->summary['membership_history'] ?? null;
            if ($existing) {
                abort_unless(($existing['rows_digest'] ?? '') === $metadata['rows_digest'] && $rows->every(fn ($row) => $row->kind === 'membership_history' && $row->status === 'recorded_history' && $row->period === '2025-12' && $row->date_precision === 'day'), 409, 'Riwayat yang tercatat berubah; perlu pemeriksaan sumber.');

                return $existing + ['newly_recorded' => 0];
            }
            abort_unless($rows->every(fn ($row) => $row->kind === 'reference' && in_array($row->status, ['review', 'reference'], true) && ! $row->customer_id && ($row->status === 'reference' || ! $row->decision)), 409, 'Ada keputusan sumber lain; periksa sebelum mencatat riwayat.');
            SourceRow::whereIn('id', $rows->pluck('id'))->update([
                'kind' => 'membership_history', 'period' => '2025-12', 'date_precision' => 'day', 'status' => 'recorded_history',
                'decision' => 'Riwayat daftar anggota per 8 Desember 2025, dicatat atas permintaan pemilik dari Excel yang diterima dari pengurus RT. Status mengikuti sumber saat itu; bukan verifikasi pelanggan atau transaksi air sekarang.',
                'reviewed_by' => $actor->id, 'updated_at' => now(),
            ]);
            $metadata += ['recorded_at' => now()->toIso8601String(), 'recorded_by' => $actor->id];
            $summary = $batch->summary ?? [];
            $summary['membership_history'] = $metadata;
            $batch->update(['summary' => $summary]);
            app(Operations::class)->audit($actor, 'history.membership.recorded', 'import:'.$batch->id, [
                'source_hash' => self::SOURCE_HASH, 'sheet' => self::SHEET, 'as_of' => self::AS_OF,
                'rows' => $metadata['count'], 'active_in_source' => $metadata['active'], 'passive_in_source' => $metadata['passive'],
                'financial_posting' => false, 'current_customers_created' => false,
            ]);

            return $metadata + ['newly_recorded' => $rows->count()];
        });
    }

    public function visible(): ?array
    {
        $batch = ImportBatch::where('hash', self::SOURCE_HASH)->first();
        $metadata = $batch?->summary['membership_history'] ?? null;
        if (! $metadata) {
            return null;
        }
        [$from, $until] = BusinessClock::window();
        if (config('airbersih.mode') === 'operational' && ($metadata['as_of'] < $from || $metadata['as_of'] >= $until)) {
            return null;
        }

        return $metadata + ['batch_id' => $batch->id];
    }

    private function sourceRows(ImportBatch $batch)
    {
        return SourceRow::where('import_batch_id', $batch->id)->where('sheet', self::SHEET)->whereBetween('row_number', [6, 56])->orderBy('row_number');
    }

    private function validate(ImportBatch $batch, Collection $rows): array
    {
        abort_unless($batch->status === 'ready', 422, 'Sumber harus selesai diperiksa.');
        $header = SourceRow::where('import_batch_id', $batch->id)->where('sheet', self::SHEET)->where('row_number', 3)->first();
        abort_unless(preg_match('/^Update\s*:\s*08\s+Dec\s+2025$/i', trim((string) ($header?->cells['B']['value'] ?? ''))), 422, 'Tanggal sumber bukan 8 Desember 2025.');
        $columns = SourceRow::where('import_batch_id', $batch->id)->where('sheet', self::SHEET)->where('row_number', 5)->first();
        foreach (['B' => 'No', 'C' => 'Nama Anggota', 'D' => 'Alamat Lengkap', 'E' => 'Jabatan', 'F' => 'Status', 'G' => 'No HP'] as $column => $label) {
            abort_unless(trim((string) ($columns?->cells[$column]['value'] ?? '')) === $label, 422, 'Pemetaan daftar anggota tidak sesuai sumber.');
        }
        abort_unless($rows->pluck('row_number')->all() === range(6, 56), 422, 'Daftar sumber harus lengkap: baris 6–56.');
        $serials = [];
        $active = 0;
        $passive = 0;
        $numericPhones = 0;
        foreach ($rows as $row) {
            $serial = (string) ($row->cells['B']['value'] ?? '');
            abort_unless(ctype_digit($serial) && trim((string) ($row->cells['C']['value'] ?? '')) !== '', 422, 'Nomor atau nama anggota sumber belum lengkap.');
            abort_if(in_array($serial, $serials, true), 422, 'Nomor anggota sumber berulang.');
            $serials[] = $serial;
            $status = trim((string) ($row->cells['F']['value'] ?? ''));
            abort_unless(in_array($status, ['Aktif', 'Pasif'], true), 422, 'Status anggota sumber tidak dikenal.');
            $active += (int) ($status === 'Aktif');
            $passive += (int) ($status === 'Pasif');
            $numericPhones += (int) is_numeric($row->cells['G']['value'] ?? null);
        }
        abort_unless($active === 41 && $passive === 10, 422, 'Jumlah status berbeda dari sumber Desember yang diperiksa.');

        return [
            'as_of' => self::AS_OF, 'period' => '2025-12', 'sheet' => self::SHEET, 'source_hash' => self::SOURCE_HASH,
            'count' => $rows->count(), 'active' => $active, 'passive' => $passive, 'numeric_phone_cells' => $numericPhones,
            'rows_digest' => hash('sha256', json_encode($rows->map(fn ($row) => ['row' => $row->row_number, 'cells' => $row->cells])->all(), JSON_THROW_ON_ERROR)),
        ];
    }
}
