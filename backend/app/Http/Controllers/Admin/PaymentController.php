<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\OfflinePayments;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function __construct(private OfflinePayments $offline) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PaymentResource::collection(
            Payment::query()->with('user', 'invoices', 'reviewer')
                ->when($request->string('status')->toString() ?: 'succeeded', fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))
                ->when($request->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))
                ->latest('id')
                ->paginate(50)
        );
    }

    public function show(Payment $payment): PaymentResource
    {
        return new PaymentResource($payment->load('user', 'invoices', 'reviewer'));
    }

    public function approve(Request $request, Payment $payment): PaymentResource
    {
        abort_unless($payment->isInReview(), 422, 'This payment has already been reviewed.');
        $data = $request->validate([
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'send_receipt' => ['boolean'],
        ]);

        $payment = $this->offline->approve($payment, $request->user(), $data['paid_on'] ?? null, $data['send_receipt'] ?? true);

        return $this->show($payment);
    }

    public function reject(Request $request, Payment $payment): PaymentResource
    {
        abort_unless($payment->isInReview(), 422, 'This payment has already been reviewed.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->show($this->offline->reject($payment, $request->user(), $data['reason']));
    }

    /** Shown inline in the review dialog. */
    public function receipt(Payment $payment): StreamedResponse
    {
        abort_unless($this->offline->receiptExists($payment), 404);

        $headers = [
            'Content-Type' => $payment->receipt_mime,
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ];
        // Uploads are limited to images and PDF; Chrome won't render a PDF under a sandbox CSP.
        if ($payment->receipt_mime !== 'application/pdf') {
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox";
        }

        return Storage::disk(OfflinePayments::DISK)->response($payment->receipt_path, $payment->receipt_name, $headers);
    }
}
