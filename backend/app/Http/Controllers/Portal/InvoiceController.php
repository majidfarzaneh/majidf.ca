<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\InvoicePdf;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $invoices = $request->user()->invoices()->visible()->with('items', 'payments')
            ->orderByDesc('issued_at')->orderByDesc('id')->get();

        return response()->json([
            'data' => InvoiceResource::collection($invoices),
            'totals' => [
                'outstanding_cents' => (int) $invoices->filter->isOpen()->sum('total_cents'),
                'payable_cents' => (int) $invoices->filter->isPayable()->sum('total_cents'),
                'paid_this_year_cents' => (int) $invoices->where('status', InvoiceStatus::Paid)
                    ->filter(fn ($i) => $i->paid_at?->isCurrentYear())->sum('total_cents'),
                'year' => now()->year,
            ],
        ]);
    }

    public function pdf(Request $request, Invoice $invoice, InvoicePdf $pdf): Response
    {
        abort_unless($invoice->user_id === $request->user()->id && in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::Paid], true), 404);
        Audit::log('invoice.downloaded', "Downloaded {$invoice->number}", $invoice);

        return response($pdf->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->number.'.pdf"',
        ]);
    }
}
