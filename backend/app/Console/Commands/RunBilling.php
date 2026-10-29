<?php

namespace App\Console\Commands;

use App\Enums\ServiceStatus;
use App\Mail\RenewalReminderMail;
use App\Models\Invoice;
use App\Models\LoginCode;
use App\Models\Service;
use App\Services\InvoiceManager;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('billing:run')]
#[Description('Daily: issue recurring invoices, send renewal and invoice reminders, prune expired sign-in codes')]
class RunBilling extends Command
{
    public function handle(InvoiceManager $invoices): void
    {
        $this->recurringInvoices($invoices);
        $this->renewalReminders();
        $this->invoiceReminders($invoices);

        LoginCode::query()->where('expires_at', '<', now()->subDay())->delete();
    }

    /** Services with auto-invoicing get an invoice on their renewal date, then roll forward one cycle. */
    private function recurringInvoices(InvoiceManager $invoices): void
    {
        $due = Service::query()->with('user')->where('auto_invoice', true)
            ->where('status', ServiceStatus::Active)
            ->whereNotNull('price_cents')->whereDate('renews_at', '<=', today())->get();

        foreach ($due as $service) {
            if (! $service->billing_cycle?->isRecurring()) {
                continue;
            }
            $period = $service->renews_at->format('M Y');
            $invoice = $invoices->save(new Invoice, [
                'user_id' => $service->user_id,
                'description' => "{$service->name} · {$period}",
                'tax_rate' => config('business.tax_rate'),
                'issued_at' => today(),
                'due_at' => today()->addDays(config('business.payment_terms_days')),
            ], [[
                'service_id' => $service->id,
                'description' => "{$service->type->label()} · {$service->name} ({$period} – {$service->billing_cycle->advance($service->renews_at)->subDay()->format('M j, Y')})",
                'quantity' => 1,
                'unit_price' => $service->price_cents / 100,
            ]]);
            $invoices->send($invoice);

            $service->update(['renews_at' => $service->billing_cycle->advance($service->renews_at)]);
            $service->forceFill(['renewal_reminded_at' => null])->save();
            $this->line("Issued {$invoice->number} for {$service->name} (".Money::format($invoice->total_cents).')');
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
