<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoicePdf
{
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing('items', 'user');

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'status' => $invoice->displayStatus(),
            'business' => config('business'),
            'tax_label' => config('business.tax_label'),
            'portal_url' => preg_replace('#^https?://#', '', config('business.portal_url')),
        ])->setPaper('letter')->setOption('isFontSubsettingEnabled', true)->output();
    }
}
