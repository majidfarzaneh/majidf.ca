<?php

namespace App\Console\Commands;

use App\Enums\ServiceStatus;
use App\Mail\RenewalReminderMail;
use App\Models\Invoice;
use App\Models\LoginCode;
use App\Models\Service;
use App\Services\InvoiceManager;
use App\Services\RenewalInvoicer;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('billing:run')]
#[Description('Daily: issue recurring invoices, send renewal and invoice reminders, prune expired sign-in codes')]
class RunBilling extends Command
{
    public function handle(InvoiceManager $invoices, RenewalInvoicer $renewals): void
    {
        $this->recurringInvoices($renewals);
        $this->renewalReminders();
        $this->invoiceReminders($invoices);

        LoginCode::query()->where('expires_at', '<', now()->subDay())->delete();
    }

    /**
     * Services with auto-invoicing get an invoice on their renewal date, then roll forward one cycle
     * (RenewalInvoicer). Clients with auto-pay are charged on their saved card instead of being emailed.
     */
    private function recurringInvoices(RenewalInvoicer $renewals): void
    {
        $due = Service::query()->where('auto_invoice', true)
            ->where('status', ServiceStatus::Active)
            ->whereNotNull('price_cents')->whereDate('renews_at', '<=', today())->get();

        foreach ($due as $service) {
            if ($renewals->blocker($service)) {
                continue;
            }
            if (! $issued = $renewals->issue($service, onlyIfDue: true)) {
                continue;
            }
            ['invoice' => $invoice, 'payment' => $payment] = $issued;
            $this->line("Issued {$invoice->number} for {$service->name} (".Money::format($invoice->total_cents).')'
                .($payment ? " · auto-pay {$payment->status}" : ''));
        }
    }

    private function renewalReminders(): void
    {
        $days = config('business.renewal_reminder_days');
        $services = Service::query()->with('user')->where('status', ServiceStatus::Active)
            ->whereNull('renewal_reminded_at')
            ->whereBetween('renews_at', [today(), today()->addDays($days)])->get();

        foreach ($services as $service) {
            if ($service->user->wants('renew') && $service->billing_cycle?->isRecurring()) {
                Mail::to($service->user)->queue(new RenewalReminderMail($service));
                Audit::log('service.renewal_reminder', "Renewal reminder: {$service->name} renews ".$service->renews_at->format('M j, Y'), $service, $service->user);
            }
            $service->forceFill(['renewal_reminded_at' => now()])->save();
        }
    }

    /** Due soon, due today, overdue and closed reminders. Each goes out once per invoice. */
    private function invoiceReminders(InvoiceManager $invoices): void
    {
        Invoice::query()->with('user')->open()->whereNotNull('due_at')
            ->whereDate('due_at', '<=', today()->addDays(config('business.reminder_days_before')))
            ->get()
            ->each(function (Invoice $invoice) use ($invoices) {
                if ($kind = $invoices->scheduledReminder($invoice)) {
                    $invoices->sendScheduledReminder($invoice, $kind);
                    $this->line("Sent {$kind} reminder for {$invoice->number}");
                }
            });
    }
}
