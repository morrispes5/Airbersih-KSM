<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class PdfDocuments
{
    public function invoice(Invoice $invoice): string
    {
        $pdf = Pdf::loadView('documents.invoice', ['invoice' => $invoice, 'snapshot' => $invoice->snapshot])->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);

        return $pdf->output();
    }

    public function cache(Invoice $invoice): void
    {
        if ($invoice->status === 'draft') {
            return;
        }$path = 'invoices/'.$invoice->id.'.pdf';
        Storage::disk(PrivateFiles::disk())->put($path, $this->invoice($invoice));
        $invoice->update(['pdf_path' => $path]);
    }
}
