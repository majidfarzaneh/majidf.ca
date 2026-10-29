<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceManager;
use App\Services\InvoicePdf;
use App\Services\PaymentRecorder;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceManager $invoices) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->string('status')->toString();

        return InvoiceResource::collection(
            Invoice::query()->with('user', 'payments')
                ->when($request->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))
                ->when($status === 'overdue', fn ($q) => $q->open()->whereDate('due_at', '<', today()))
                ->when($status === 'expired', fn ($q) => $q->open()->whereDate('pay_until', '<', today()))
                ->when($status && ! in_array($status, ['overdue', 'expired'], true), fn ($q) => $q->where('status', $status))
                ->when($request->string('search')->trim()->toString(), fn ($q, $s) => $q->where(fn ($q) => $q
                    ->where('number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")))
                ->latest('id')
                ->paginate(50)
        );
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice->load('items', 'user', 'payments'));
    }

    public function store(Request $request): InvoiceResource
    {
        [$data, $items] = $this->validated($request);
        $invoice = $this->invoices->save(new Invoice, $data, $items);
        Audit::log('invoice.created', "Created {$invoice->number} · ".Money::format($invoice->total_cents), $invoice, $invoice->user);

        if ($request->boolean('send')) {
            $this->invoices->send($invoice, $request->boolean('email', true));
        }

        return $this->show($invoice);
    }

    public function update(Request $request, Invoice $invoice): InvoiceResource
    {
        abort_if(in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Void], true), 422, 'Paid and voided invoices cannot be edited.');

        [$data, $items] = $this->validated($request, $invoice);
        $invoice = $this->invoices->save($invoice, $data, $items);
        Audit::log('invoice.updated', "Edited {$invoice->number} · ".Money::format($invoice->total_cents), $invoice, $invoice->user);

        if ($request->boolean('send') && $invoice->status === InvoiceStatus::Draft) {
            $this->invoices->send($invoice, $request->boolean('email', true));
        }

        return $this->show($invoice);
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        abort_unless($invoice->status === InvoiceStatus::Draft, 422, 'Only drafts can be deleted. Void the invoice instead.');
        Audit::log('invoice.deleted', "Deleted draft {$invoice->number}", null, $invoice->user);
        $invoice->delete();

        return response()->json(['ok' => true]);
    }

    public function send(Request $request, Invoice $invoice): InvoiceResource
    {
        abort_unless(in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Unpaid], true), 422, 'Only drafts and unpaid invoices can be sent.');
        abort_if($invoice->total_cents <= 0, 422, 'Add at least one line item first.');
        $this->invoices->send($invoice, $request->boolean('email', true));

        return $this->show($invoice);
    }

    public function remind(Invoice $invoice): InvoiceResource
    {
        abort_unless($invoice->isOpen(), 422, 'Only unpaid invoices can be reminded.');
        $this->invoices->remind($invoice);

        return $this->show($invoice);
    }

    public function void(Invoice $invoice): InvoiceResource
    {
        abort_if($invoice->status === InvoiceStatus::Paid, 422, 'Paid invoices cannot be voided.');
        $this->invoices->void($invoice);

        return $this->show($invoice);
    }

    public function recordPayment(Request $request, Invoice $invoice, PaymentRecorder $recorder): InvoiceResource
    {
        abort_unless($invoice->isOpen(), 422, 'Only unpaid invoices can be marked paid.');
        $data = $request->validate([
            'method' => ['required', Rule::in(Payment::MANUAL_METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'send_receipt' => ['boolean'],
        ]);

        $recorder->manual($invoice, $data['method'], $data['reference'] ?? null, $data['note'] ?? null, $data['paid_on'] ?? null, $data['send_receipt'] ?? true);

        return $this->show($invoice->refresh());
    }

    public function pdf(Invoice $invoice, InvoicePdf $pdf): Response
    {
        return response($pdf->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->number.'.pdf"',
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function validated(Request $request, ?Invoice $invoice = null): array
    {
        $data = $request->validate([
            'user_id' => [$invoice ? 'sometimes' : 'required', Rule::exists('users', 'id')->where('role', 'client')],
            'description' => ['required', 'string', 'max:160'],
            'issued_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:50'],
            'tax_label' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'items.*.service_id' => ['nullable', 'integer', Rule::exists('services', 'id')],
        ]);

        $items = $data['items'];
        unset($data['items']);

        return [$data, $items];
    }
}
