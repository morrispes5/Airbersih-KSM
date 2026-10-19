<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Reading;
use App\Services\Operations;
use App\Services\PdfDocuments;
use App\Services\PrivateFiles;
use App\Services\Reports;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DocumentsController extends Controller
{
    public function source(ImportBatch $batch)
    {
        Gate::authorize('finance');

        return response()->streamDownload(function () use ($batch) {
            PrivateFiles::temporary($batch->path, function ($temp) use ($batch) {
                abort_unless(hash_equals($batch->hash, hash_file('sha256', $temp)), 422, 'Checksum sumber tidak cocok.');
                readfile($temp);
            });
        }, 'sumber-'.$batch->id.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
    }

    public function invoice(Invoice $invoice, Request $r, PdfDocuments $pdf)
    {
        Gate::authorize('finance');
        $bytes = $pdf->invoice($invoice);

        return response($bytes)->header('Content-Type', 'application/pdf')->header('Content-Disposition', ($r->boolean('download') ? 'attachment' : 'inline').'; filename="invoice-'.$invoice->id.'.pdf"')->header('Cache-Control', 'private, no-store');
    }

    public function attachment(Attachment $attachment)
    {
        if ($attachment->owner_type === 'reading') {
            $r = Reading::findOrFail($attachment->owner_id);
            app(Operations::class)->connection(auth()->user(), $r->connection_id);
        } else {
            Gate::authorize('finance');
        }
        abort_unless(Storage::disk(PrivateFiles::disk())->exists($attachment->path), 404);

        return Storage::disk(PrivateFiles::disk())->response($attachment->path, null, ['Content-Type' => $attachment->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function report(Request $r, Reports $reports)
    {
        Gate::authorize('finance');
        $period = $r->validate(['period' => 'required|date_format:Y-m'])['period'];
        $report = $reports->data($period);
        if ($r->query('format') === 'pdf') {
            return Pdf::loadView('documents.report', compact('report'))->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false])->download('laporan-'.$period.'.pdf');
        }

        return response()->streamDownload(function () use ($report) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['KSM Banyu Bening', 'Tanggal', 'Akun', 'Jenis', 'Keterangan', 'Rupiah'], ',', '"', '');
            fputcsv($f, ['Status saldo awal', $report['opening_known'] ? 'Dikonfirmasi' : 'Belum dikonfirmasi', 'Piutang: hanya yang tercatat, belum rekonsiliasi sumber lama'], ',', '"', '');
            foreach ($report['rows'] as $row) {
                $description = preg_match('/^[=+\-@\t\r]/u', $row->description) ? "'".$row->description : $row->description;
                fputcsv($f, ['KSM Banyu Bening', $row->entry_date->toDateString(), $row->account_id, $row->kind, $description, $row->amount], ',', '"', '');
            }fclose($f);
        }, 'laporan-'.$period.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
