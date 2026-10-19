<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\SourceRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

final class SourceStaging
{
    private const SOURCE_A = '0b07bd9fe1c0437997fd1392aa7e7efc6128ade51ca0fb2c7f13c1f81b9e0de8';

    private const SOURCE_B = 'f538298286132c4232b95b3bb90c8318c77d1f514c18f083f03712a88e4a06ba';

    public function stage(ImportBatch $batch, string $path): int
    {
        abort_unless(hash_equals($batch->hash, hash_file('sha256', $path)), 422, 'Checksum sumber tidak sesuai.');
        app(WorkbookPreview::class)->inspect($path);
        $reader = new Xlsx;
        $reader->setReadDataOnly(true)->setReadEmptyCells(false);
        $info = $reader->listWorksheetInfo($path);
        foreach ($info as $i) {
            abort_if($i['totalRows'] > 5000 || $i['totalColumns'] > 64, 422, 'Sheet melampaui batas staging 5.000 baris / 64 kolom.');
        }
        $count = 0;
        foreach ($info as $i) {
            $reader->setLoadSheetsOnly([$i['worksheetName']]);
            $book = $reader->load($path);
            try {
                $sheet = $book->getSheetByName($i['worksheetName']);
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = [];
                    foreach ($row->getCellIterator() as $cell) {
                        $value = $cell->getValue();
                        if ($value !== null && $value !== '') {
                            $cells[$cell->getColumn()] = ['value' => $cell->isFormula() ? $cell->getOldCalculatedValue() : $value, 'formula' => $cell->isFormula() ? $value : null];
                        }
                    }
                    if (! $cells) {
                        continue;
                    }
                    $n = $row->getRowIndex();
                    $name = $sheet->getTitle();
                    $master = ($batch->hash === self::SOURCE_A && $name === 'Pelanggan') || ($batch->hash === self::SOURCE_B && $name === 'Sheet1');
                    $period = $batch->hash === self::SOURCE_A ? (["Maret '25" => '2025-03', "April '25" => '2025-04', "Mei '25" => '2025-05'][$name] ?? null) : null;
                    $kind = $master && $n >= 5 && is_numeric($cells['B']['value'] ?? null) ? 'master' : ($period && $n >= 6 && $n <= ($period === '2025-05' ? 44 : 43) ? 'history' : 'reference');
                    $issues = match ($kind) {
                        'master' => ['Master historis; identitas, status dan sambungan saat ini perlu verifikasi.', 'Versi A/B berbeda pada 39 sel; bandingkan sumber sebelum mengesahkan.'],
                        'history' => ['Tanggal sumber hanya diketahui sampai bulan; bukan bukti pembayaran diterima.'],
                        default => ['Referensi sumber; tidak diposting menjadi transaksi.'],
                    };
                    if ($period === '2025-05') {
                        $issues[] = 'Nama sheet Mei, label sumber April: periode perlu disahkan.';
                        if ($n === 29) {
                            $issues[] = 'CONFLICT: invoice referensi 50 m3, Excel 0 m3. Posting ditahan.';
                        }
                    }
                    if ($kind === 'history') {
                        foreach (['G', 'H', 'I', 'J', 'L'] as $column) {
                            if (! is_numeric($cells[$column]['value'] ?? null)) {
                                $issues[] = 'Angka '.$column.' tidak lengkap.';
                            }
                        }
                        if (is_numeric($cells['G']['value'] ?? null) && is_numeric($cells['H']['value'] ?? null) && is_numeric($cells['I']['value'] ?? null)
                            && abs(($cells['H']['value'] - $cells['G']['value']) - $cells['I']['value']) > 0.000001) {
                            $issues[] = 'Selisih meter tidak cocok dengan volume cache.';
                        }
                    }
                    $rows[] = ['import_batch_id' => $batch->id, 'sheet' => $name, 'row_number' => $n, 'kind' => $kind, 'period' => $period, 'date_precision' => $period ? 'month' : 'unknown', 'cells' => json_encode($cells, JSON_THROW_ON_ERROR), 'issues' => json_encode($issues, JSON_THROW_ON_ERROR), 'status' => 'review', 'created_at' => now(), 'updated_at' => now()];
                    $count++;
                }
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table('source_rows')->insertOrIgnore($chunk);
                }
            } finally {
                $book->disconnectWorksheets();
                unset($book);
                gc_collect_cycles();
            }
        }

        return $count;
    }

    public function review(User $user, int $id, string $action, string $reason): void
    {
        app(Operations::class)->finance($user);
        Validator::make(compact('action', 'reason'), ['action' => 'required|in:verify_master,hold,reference', 'reason' => 'required|string|min:8|max:500'])->validate();
        DB::transaction(function () use ($user, $id, $action, $reason) {
            $row = SourceRow::lockForUpdate()->findOrFail($id);
            abort_if($row->kind === 'membership_history', 422, 'Riwayat anggota telah dicatat; koreksi perlu pemeriksaan riwayat tersendiri.');
            abort_if($row->status !== 'review' && $action !== 'hold', 409, 'Baris sudah diputuskan.');
            if ($action === 'verify_master') {
                abort_unless($row->kind === 'master', 422, 'Hanya master pelanggan dapat disahkan melalui tindakan ini.');
                $name = trim((string) ($row->cells['C']['value'] ?? ''));
                $address = trim(implode(' ', array_map(fn ($col) => (string) ($row->cells[$col]['value'] ?? ''), ['D', 'E', 'F'])));
                abort_unless($name && $address, 422, 'Nama/alamat sumber belum lengkap.');
                $sourceKey = ImportBatch::findOrFail($row->import_batch_id)->hash.':'.$row->sheet.':'.$row->row_number;
                abort_if(Customer::whereRaw('lower(name)=lower(?)', [$name])->whereRaw('lower(address)=lower(?)', [$address])->exists(), 409, 'Identitas dan alamat sama sudah ada. Gunakan master yang sudah disahkan.');
                $customer = app(Operations::class)->saveCustomer($user, ['name' => $name, 'address' => $address, 'phone' => (string) ($row->cells['H']['value'] ?? '')]);
                $customer->update(['source_key' => $sourceKey]);
                $row->customer_id = $customer->id;
            }
            $row->status = $action === 'hold' ? 'review' : ($action === 'verify_master' ? 'verified_master' : 'reference');
            $row->decision = $reason;
            $row->reviewed_by = $user->id;
            $row->save();
            app(Operations::class)->audit($user, 'source.reviewed', 'source:'.$id, ['action' => $action, 'reason' => $reason]);
        });
    }
}
