<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Models\Activity;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Tax;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues a recurring service's invoice for its next period (renews_at → one cycle later) and moves
 * renews_at forward. Used by billing:run on the renewal date and by the admin's "Invoice renewal".
 */
class RenewalInvoicer
{
    public function __construct(private InvoiceManager $invoices, private StripeGateway $stripe) {}

    /** Why the service can't be invoiced for a renewal, or null when it can. */
    public function blocker(Service $service): ?string
    {
        return match (true) {
            ! $service->billing_cycle?->isRecurring() => 'Only monthly, quarterly or yearly services have renewals.',
            $service->price_cents === null => 'Set a price first.',
            $service->renews_at === null => 'Set a renewal date first.',
            $service->status === ServiceStatus::Cancelled => 'Cancelled services are not renewed.',
            default => null,
        };
    }

    /**
     * Create the invoice and roll the service forward one cycle. With $send, the invoice is issued:
     * auto-pay clients are charged on their saved card, everyone else is emailed the invoice.
     * With $onlyIfDue (the daily job), nothing happens unless the renewal date is still today or earlier.
     *
     * @return array{invoice: Invoice, payment: ?Payment}|null
     */
    public function issue(Service $service, bool $send = true, bool $onlyIfDue = false): ?array
    {
        // Locked, so a double click or the daily job running at the same moment can't bill one period twice.
        $invoice = DB::transaction(function () use ($service, $onlyIfDue) {
            $service = Service::query()->with('user')->lockForUpdate()->findOrFail($service->id);
            // Already invoiced early by the admin since the job picked it up.
            if ($onlyIfDue && ($service->renews_at === null || $service->renews_at->gt(today()))) {
                return null;
            }
            if ($reason = $this->blocker($service)) {
                throw ValidationException::withMessages(['renewal' => $reason]);
            }

            $tax = Tax::for($service->user, $service);
            $start = $service->renews_at;
            $next = $service->billing_cycle->advance($start);
            $invoice = $this->invoices->save(new Invoice, [
                'user_id' => $service->user_id,
                'description' => "{$service->name} · {$start->format('M Y')}",
                'tax_rate' => $tax['rate'],
                'tax_label' => $tax['label'],
                'issued_at' => today(),
                'due_at' => today()->addDays(config('business.payment_terms_days')),
            ], [[
                'service_id' => $service->id,
                'description' => "{$service->type->label()} · {$service->name} ({$start->format('M Y')} – {$next->copy()->subDay()->format('M j, Y')})",
                'quantity' => 1,
                'unit_price' => $service->price_cents / 100,
            ]]);

            $service->forceFill(['renews_at' => $next, 'renewal_reminded_at' => null])->save();
            Activity::log($service->user_id, 'invoice', "Renewal invoice {$invoice->number} · {$service->name} · ".Money::format($invoice->total_cents), $invoice);
            Audit::log('invoice.renewal_created', "Created renewal invoice {$invoice->number} for {$service->name} ({$start->format('M j')} – {$next->copy()->subDay()->format('M j, Y')})", $invoice, $service->user);

            return $invoice;
        });
        if (! $invoice) {
            return null;
        }

        $payment = null;
        if ($send) {
            // Charged outside the transaction: a Stripe call shouldn't hold the service row locked.
            $autopay = $invoice->user->autopayEnabled() && $this->stripe->enabled();
            $this->invoices->send($invoice, email: ! $autopay);
            if ($autopay) {
                $payment = $this->stripe->chargeSavedCard($invoice);
            }
        }

        return ['invoice' => $invoice->refresh(), 'payment' => $payment];
    }
}
