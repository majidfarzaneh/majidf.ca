<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Mail\InvoiceMail;
use App\Mail\PaymentReceiptMail;
use App\Models\Activity;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\AdminAlert;
use App\Support\Audit;
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

            Audit::log('payment.succeeded', "Payment received · {$amount} by {$payment->methodLabel()} · {$numbers}", $payment, $payment->user);
            if (in_array($payment->method, ['card', 'checkout', 'autopay'], true)) {
                AdminAlert::send('payment', "{$numbers} paid · {$amount}", [
                    'Client' => $payment->user->company ?: $payment->user->name,
                    'Invoices' => $numbers,
                    'Method' => match ($payment->method) {
                        'card' => 'Card (in portal)',
                        'autopay' => 'Saved card (auto-pay)',
                        default => 'Stripe Checkout',
                    },
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
        Audit::log('payment.recorded', "Recorded a {$payment->methodLabel()} payment for {$invoice->number} · ".Money::format($payment->amount_cents), $payment, $invoice->user);

        return $this->succeeded($payment, $paidOn ? ['paid_at' => now()->parse($paidOn)] : [], $sendReceipt);
    }

    public function failed(Payment $payment, ?string $note = null): void
    {
        $payment = Payment::query()->findOrFail($payment->id);
        if ($payment->status !== 'pending') {
            return;
        }
        $payment->update(['status' => 'failed', 'note' => $note]);
        $payment->load('invoices', 'user');
        $numbers = $payment->invoices->pluck('number')->implode(', ');
        Audit::log('payment.failed', 'Payment failed · '.Money::format($payment->amount_cents)." by {$payment->methodLabel()} · {$numbers}".($note ? " · {$note}" : ''), $payment, $payment->user);
        if ($payment->method === 'card') {
            AdminAlert::send('payment', "Card payment failed · {$numbers}", [
                'Client' => $payment->user->company ?: $payment->user->name,
                'Amount' => Money::format($payment->amount_cents),
                'Reason' => $note,
            ], '/payments?status=failed');
        }
        // A failed auto-pay charge: the invoice stays unpaid, so the client pays it in the portal
        // (normal reminders follow) and the admin hears about it.
        if ($payment->method === 'autopay') {
            $payment->load('invoices', 'user');
            foreach ($payment->invoices as $invoice) {
                Activity::log($payment->user_id, 'payment', "Auto-pay failed · {$invoice->number}", $invoice);
                if ($payment->user->wants('inv')) {
                    Mail::to($payment->user)->queue(new InvoiceMail($invoice, 'autopay_failed', $note));
                }
                AdminAlert::send('autopay', "Auto-pay failed · {$invoice->number} · ".Money::format($invoice->total_cents), [
                    'Client' => $payment->user->company ?: $payment->user->name,
                    'Card' => ucfirst((string) $payment->user->card_brand).' •••• '.$payment->user->card_last4,
                    'Reason' => $note ?? 'Unknown',
                ], '/invoices/'.$invoice->id);
            }
        }
    }
}
