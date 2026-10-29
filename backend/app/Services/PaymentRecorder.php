<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Mail\AdminAlertMail;
use App\Mail\PaymentReceiptMail;
use App\Models\Activity;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PaymentRecorder
{
    /**
     * Create a pending payment that covers the given invoices.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    public function pending(User $user, Collection $invoices, string $method): Payment
    {
        return DB::transaction(function () use ($user, $invoices, $method) {
            $payment = $user->payments()->create([
                'amount_cents' => (int) $invoices->sum('total_cents'),
                'currency' => config('business.currency'),
                'method' => $method,
                'status' => 'pending',
            ]);
            $payment->invoices()->attach($invoices->mapWithKeys(fn (Invoice $i) => [$i->id => ['amount_cents' => $i->total_cents]]));

            return $payment;
        });
    }

    /** Mark a payment as succeeded and its invoices as paid. Safe to call more than once. */
    public function succeeded(Payment $payment, array $attributes = [], bool $sendReceipt = true): Payment
    {
        $changed = DB::transaction(function () use ($payment, $attributes) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === 'succeeded') {
                return false;
            }

            $payment->fill($attributes + ['status' => 'succeeded', 'paid_at' => now()])->save();

            foreach ($payment->invoices as $invoice) {
                if ($invoice->status !== InvoiceStatus::Paid) {
                    $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => $payment->paid_at]);
                }
            }

            return true;
        });

        $payment->refresh()->load('invoices', 'user');

        if ($changed) {
            $numbers = $payment->invoices->pluck('number')->implode(', ');
            $amount = Money::format($payment->amount_cents);
            Activity::log($payment->user_id, 'payment', "Payment received · {$amount} · {$numbers}", $payment);
            if ($sendReceipt) {
                Mail::to($payment->user)->queue(new PaymentReceiptMail($payment));
            }

            if (in_array($payment->method, ['card', 'checkout'], true)) {
                AdminAlertMail::dispatch('payment', "Payment received · {$amount}", [
                    'Client' => $payment->user->company ?: $payment->user->name,
                    'Invoices' => $numbers,
                    'Method' => $payment->method === 'card' ? 'Card (in portal)' : 'Stripe Checkout',
                ], '/payments');
            }
        }

        return $payment;
    }

    /** Record an offline payment (e-Transfer, cheque…) against one invoice. */
    public function manual(Invoice $invoice, string $method, ?string $reference, ?string $note, ?string $paidOn, bool $sendReceipt): Payment
    {
        $payment = $this->pending($invoice->user, collect([$invoice]), $method);
        $payment->update(['reference' => $reference, 'note' => $note]);

        return $this->succeeded($payment, $paidOn ? ['paid_at' => now()->parse($paidOn)] : [], $sendReceipt);
    }

    public function failed(Payment $payment, ?string $note = null): void
    {
        if ($payment->status === 'pending') {
            $payment->update(['status' => 'failed', 'note' => $note]);
        }
    }
}
