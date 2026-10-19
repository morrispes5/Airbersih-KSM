<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\PrivateFiles;
use App\Services\SourceStaging;
use App\Services\WorkbookPreview;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class InspectWorkbook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $batchId) {}

    public function handle(WorkbookPreview $reader): void
    {
        $batch = ImportBatch::findOrFail($this->batchId);
        $batch->update(['status' => 'processing']);
        try {
            $summary = PrivateFiles::temporary($batch->path, function ($temp) use ($reader, $batch) {
                $summary = $reader->inspect($temp);
                $summary['staged_rows'] = app(SourceStaging::class)->stage($batch, $temp);

                return $summary;
            });
            $batch->update(['status' => 'ready', 'summary' => $summary]);
        } catch (\Throwable $e) {
            $batch->update(['status' => 'failed', 'summary' => ['notice' => 'Workbook gagal dibaca. Pastikan XLSX tidak rusak dan ukuran isi sesuai batas. Unggah ulang untuk mencoba lagi.']]);
            throw new \RuntimeException('Workbook preview failed; no operational records imported.');
        }
    }
}
