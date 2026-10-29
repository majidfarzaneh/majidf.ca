<?php

namespace App\Services;

use App\Mail\AdminAlertMail;
use App\Mail\PaymentRejectedMail;
use App\Models\Activity;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Client-submitted e-Transfer / cheque / bank transfer payments, approved or rejected by the admin. */
class OfflinePayments
{
    public const DISK = 'local';

    public function __construct(private PaymentRecorder $recorder) {}

    /** @param Collection<int, Invoice> $invoices */
    public function submit(User $user, Collection $invoices, string $method, UploadedFile $receipt, ?string $reference, ?string $note): Payment
    {
        $path = $receipt->storeAs("receipts/{$user->id}", Str::uuid().'.'.$receipt->extension(), self::DISK);

        $payment = DB::transaction(function () use ($user, $invoices, $method, $receipt, $path, $reference, $note) {
            $payment = $user->payments()->create([
                'amount_cents' => (int) $invoices->sum('total_cents'),
                'currency' => config('business.currency'),
                'method' => $method,
                'status' => 'review',
                'reference' => $reference,
                'client_note' => $note,
                'receipt_path' => $path,
                'receipt_name' => Str::limit($receipt->getClientOriginalName(), 200, ''),
                'receipt_mime' => $receipt->getMimeType(),
                'receipt_size' => $receipt->getSize(),
                'submitted_at' => now(),
            ]);
            $payment->invoices()->attach($invoices->mapWithKeys(fn (Invoice $i) => [$i->id => ['amount_cents' => $i->total_cents]]));

            return $payment;
        });

        $numbers = $invoices->pluck('number')->implode(', ');
        $amount = Money::format($payment->amount_cents);
        Activity::log($user, 'payment', "Payment proof sent · {$amount} by {$payment->methodLabel()} · {$numbers}", $payment);
        AdminAlertMail::dispatch('payment review', "Payment proof to review · {$amount}", array_filter([
            'Client' => $user->company ?: $user->name,
            'Invoices' => $numbers,
            'Method' => ucfirst($payment->methodLabel()),
            'Reference' => $reference,
            'Note' => $note,
        ]), '/payments?status=review');

        return $payment;
    }

    public function approve(Payment $payment, User $admin, ?string $paidOn, bool $sendReceipt): Payment
    {
        $payment->update(['reviewed_at' => now(), 'reviewed_by' => $admin->id]);

        return $this->recorder->succeeded($payment, $paidOn ? ['paid_at' => now()->parse($paidOn)] : [], $sendReceipt);
    }

    public function reject(Payment $payment, User $admin, string $reason): Payment
    {
        $payment->update([
            'status' => 'rejected',
            'reviewed_at' => now(),
            'reviewed_by' => $admin->id,
            'rejection_reason' => $reason,
        ]);

        $payment->load('invoices', 'user');
        Activity::log($payment->user_id, 'payment', 'Payment proof not accepted · '.$payment->invoices->pluck('number')->implode(', '), $payment);
        Mail::to($payment->user)->queue(new PaymentRejectedMail($payment));

        return $payment;
    }

    public function receiptExists(Payment $payment): bool
    {
        return $payment->receipt_path !== null && Storage::disk(self::DISK)->exists($payment->receipt_path);
    }
}
