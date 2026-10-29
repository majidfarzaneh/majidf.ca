<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Mail\InvoiceMail;
use App\Models\Activity;
use App\Models\Invoice;
use App\Support\AdminAlert;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class InvoiceManager
{
    /**
     * Create or update an invoice together with its line items.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{description: string, quantity: float|int, unit_price: float|int|string, service_id?: int|null}>  $items
     */
    public function save(Invoice $invoice, array $data, array $items): Invoice
    {
        return DB::transaction(function () use ($invoice, $data, $items) {
            $invoice->fill($data);
            $invoice->number ??= Invoice::nextNumber();
            $invoice->status ??= InvoiceStatus::Draft;
            $invoice->tax_label ??= config('business.tax_label');
            $invoice->save();

            $invoice->items()->delete();
            foreach (array_values($items) as $i => $item) {
                $unit = Money::toCents($item['unit_price']);
                $qty = (float) $item['quantity'];
                $invoice->items()->create([
                    'service_id' => $item['service_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $qty,
                    'unit_price_cents' => $unit,
                    'amount_cents' => (int) round($unit * $qty),
                    'sort' => $i,
                ]);
            }

            $invoice->unsetRelation('items');
            $invoice->recalculate();
            $invoice->save();

            return $invoice->load('items');
        });
    }

    /** Issue the invoice: make it visible and payable in the portal, and email it unless asked not to. */
    public function send(Invoice $invoice, bool $email = true): Invoice
    {
        $firstSend = $invoice->status === InvoiceStatus::Draft;

        $invoice->status = InvoiceStatus::Unpaid;
        $invoice->issued_at ??= today();
        $invoice->due_at ??= today()->addDays(config('business.payment_terms_days'));
        $invoice->sent_at = now();
        $invoice->save();

        if ($firstSend) {
            Activity::log($invoice->user_id, 'invoice', "Invoice {$invoice->number} issued · ".Money::format($invoice->total_cents), $invoice);
        }

        $emailed = $email && $invoice->user->wants('inv');
        if ($emailed) {
            Mail::to($invoice->user)->queue(new InvoiceMail($invoice));
        }
        Audit::log('invoice.sent', ($firstSend ? 'Issued' : 'Re-sent')." {$invoice->number} · ".Money::format($invoice->total_cents)
            .($emailed ? ' (emailed)' : ' (not emailed)'), $invoice, $invoice->user);

        return $invoice;
    }

    /** Manual "Send reminder": picks the wording that fits the invoice's current state. */
    public function remind(Invoice $invoice): void
    {
        $kind = InvoiceMail::reminderKindFor($invoice);
        Mail::to($invoice->user)->queue(new InvoiceMail($invoice, $kind));
        $invoice->forceFill(['reminded_at' => now()])->saveQuietly();
        Audit::log('invoice.reminded', "Sent a reminder for {$invoice->number} (".str_replace('_', ' ', $kind).')', $invoice, $invoice->user);
    }

    /**
     * The automatic reminder due today for an open invoice, if any:
     * due_soon (N days before), due_today, overdue (in the grace period) and closed (grace period over).
     */
    public function scheduledReminder(Invoice $invoice): ?string
    {
        // No reminders while the client's offline payment proof waits for review.
        if (! $invoice->isOpen() || ! $invoice->due_at || $invoice->isInReview()) {
            return null;
        }

        $today = today();
        $kind = match (true) {
            $invoice->pay_until->lt($today) => 'closed',
            $invoice->due_at->lt($today) => 'overdue',
            $invoice->due_at->isSameDay($today) => 'due_today',
            $invoice->due_at->copy()->subDays(config('business.reminder_days_before'))->lte($today) => 'due_soon',
            default => null,
        };

        // Don't send "due soon" right after the invoice itself went out.
        if ($kind === 'due_soon' && $invoice->sent_at && $invoice->sent_at->gt($invoice->due_at->copy()->subDays(config('business.reminder_days_before')))) {
            return null;
        }

        return $kind && ! $invoice->reminderSent($kind) ? $kind : null;
    }

    /**
     * Send an automatic reminder once. Client emails respect their preference; due today, overdue and
     * closed also notify the admin.
     */
    public function sendScheduledReminder(Invoice $invoice, string $kind): void
    {
        $emailed = $invoice->user->wants('inv');
        if ($emailed) {
            Mail::to($invoice->user)->queue(new InvoiceMail($invoice, $kind));
        }

        $amount = Money::format($invoice->total_cents);
        $label = ['due_soon' => 'due soon', 'due_today' => 'due today', 'overdue' => 'overdue', 'closed' => 'payment window closed'][$kind] ?? $kind;
        Audit::log("invoice.reminder.{$kind}", "Reminder: {$invoice->number} {$label}".($emailed ? ' (emailed)' : ' (client has invoice emails off)'), $invoice, $invoice->user);

        $client = $invoice->user->company ?: $invoice->user->name;
        $details = ['Client' => $client, 'Invoice' => $invoice->number.' · '.$invoice->description, 'Amount' => $amount];
        match ($kind) {
            'due_today' => AdminAlert::send('invoice', "{$invoice->number} is due today · {$amount}", $details, '/invoices/'.$invoice->id),
            'overdue' => AdminAlert::send('invoice', "{$invoice->number} is overdue · {$amount}", $details + [
                'Payable until' => $invoice->pay_until->format('M j, Y'),
            ], '/invoices/'.$invoice->id),
            'closed' => AdminAlert::send('invoice', "{$invoice->number} expired unpaid · {$amount}", $details + [
                'Was due' => $invoice->due_at->format('M j, Y').' (payable until '.$invoice->pay_until->format('M j').')',
            ], '/invoices/'.$invoice->id),
            default => null,
        };

        if ($kind === 'closed') {
            Activity::log($invoice->user_id, 'invoice', "Invoice {$invoice->number} expired unpaid · {$amount}", $invoice);
        }

        $invoice->markReminderSent($kind);
    }

    public function void(Invoice $invoice): Invoice
    {
        $invoice->update(['status' => InvoiceStatus::Void]);
        Activity::log($invoice->user_id, 'invoice', "Invoice {$invoice->number} voided", $invoice);
        Audit::log('invoice.voided', "Voided {$invoice->number} · ".Money::format($invoice->total_cents), $invoice, $invoice->user);

        return $invoice;
    }
}
