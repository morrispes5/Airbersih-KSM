<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\PdfDocuments;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateInvoicePdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public int $invoiceId) {}

    public function handle(PdfDocuments $pdf): void
    {
        $pdf->cache(Invoice::findOrFail($this->invoiceId));
    }
}
