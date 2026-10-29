<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\OfflinePayments;
use App\Services\StripeGateway;
use App\Support\OfflineMethods;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function __construct(private StripeGateway $stripe) {}

    /** Start an in-portal card payment and return the Payment Element client secret. */
    public function intent(Request $request): JsonResponse
    {
        $invoices = $this->invoices($request);
        $result = $this->stripe->createIntent($request->user(), $invoices);

        return response()->json([
            'payment_id' => $result['payment']->id,
            'client_secret' => $result['client_secret'],
            'amount_cents' => $result['payment']->amount_cents,
        ]);
    }

    /** Start a hosted Stripe Checkout session and return its URL. */
    public function checkout(Request $request): JsonResponse
    {
        return response()->json(['url' => $this->stripe->createCheckout($request->user(), $this->invoices($request))]);
    }

    /** Called after the browser confirms a payment, so the portal updates without waiting for the webhook. */
    public function show(Request $request, Payment $payment): PaymentResource
    {
        abort_unless($payment->user_id === $request->user()->id, 404);

        return new PaymentResource($this->stripe->sync($payment)->load('invoices'));
    }

    /** Pay outside the portal (e-Transfer, cheque, bank transfer) and upload the receipt for review. */
    public function offline(Request $request, OfflinePayments $offline): PaymentResource
    {
        $data = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['integer'],
            'method' => ['required', Rule::in(array_keys(OfflineMethods::enabled()))],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif,pdf', 'max:'.config('business.offline.receipt_max_kb')],
        ], [
            'receipt.required' => 'Attach a photo or PDF of your receipt.',
            'receipt.mimes' => 'The receipt must be an image (JPG, PNG, WebP, HEIC) or a PDF.',
            'receipt.max' => 'The receipt must be smaller than 10 MB.',
        ]);

        $ids = array_unique($data['invoice_ids']);
        $invoices = $request->user()->invoices()->open()->with('payments')->whereIn('id', $ids)->get();
        if ($invoices->count() !== count($ids) || ! $invoices->every->acceptsOfflinePayment()) {
            throw ValidationException::withMessages(['invoice_ids' => 'Those invoices are already paid or a payment for them is already being reviewed.']);
        }

        $payment = $offline->submit($request->user(), $invoices, $data['method'], $request->file('receipt'), $data['reference'] ?? null, $data['note'] ?? null);

        return new PaymentResource($payment->load('invoices'));
    }

    /** The receipt the client uploaded. */
    public function receipt(Request $request, Payment $payment): StreamedResponse
    {
        abort_unless($payment->user_id === $request->user()->id && $payment->receipt_path, 404);

        return Storage::disk(OfflinePayments::DISK)->download($payment->receipt_path, $payment->receipt_name);
    }

    /** @return Collection<int, Invoice> */
    private function invoices(Request $request): Collection
    {
        abort_unless($this->stripe->enabled(), 503, 'Online payments are not set up yet.');

        $data = $request->validate([
            'invoice_ids' => ['array', 'required_without:all'],
            'invoice_ids.*' => ['integer'],
            'all' => ['boolean'],
        ]);

        $query = $request->user()->invoices()->open();
        if (empty($data['all'])) {
            $query->whereIn('id', $data['invoice_ids']);
        }
        // Only invoices still inside their payment window (due date + grace days) can be paid online.
        $invoices = $query->with('payments')->orderBy('due_at')->get()->filter->isPayable()->values();

        if ($invoices->isEmpty() || (empty($data['all']) && $invoices->count() !== count(array_unique($data['invoice_ids'])))) {
            throw ValidationException::withMessages(['invoice_ids' => 'Those invoices are already paid or their online payment window has closed.']);
        }

        return $invoices;
    }
}
