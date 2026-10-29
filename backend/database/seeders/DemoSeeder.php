<?php

namespace Database\Seeders;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\UptimeCheck;
use App\Models\User;
use App\Services\InvoiceManager;
use Illuminate\Database\Seeder;

/** Sample client matching the portal design (Sara at Olive & Fig). Local only. */
class DemoSeeder extends Seeder
{
    public function run(InvoiceManager $invoices): void
    {
        if (User::query()->where('email', 'sara@oliveandfig.ca')->exists()) {
            return;
        }

        $admin = User::query()->where('role', Role::Admin)->firstOrFail();
        $sara = User::create([
            'name' => 'Sara Ahmadi', 'email' => 'sara@oliveandfig.ca', 'role' => Role::Client,
            'company' => 'Olive & Fig', 'phone' => '416-555-0142', 'address' => "214 Ossington Ave\nToronto, ON M6J 2Z9",
            'notification_prefs' => User::DEFAULT_PREFS,
        ]);

        $today = today();
        $s = fn (array $a) => Service::create($a + ['user_id' => $sara->id]);
        $s(['name' => 'oliveandfig.ca', 'type' => 'website', 'status' => 'active', 'detail' => 'Online ordering · Laravel + React', 'meta' => 'Live since Mar 2026', 'started_at' => '2026-03-02', 'monitor_url' => 'https://example.com', 'sort' => 1]);
        $s(['name' => 'Olive & Fig app', 'type' => 'mobile_app', 'status' => 'in_development', 'detail' => 'iOS + Android · React Native', 'meta' => 'Milestone 3 of 4', 'progress' => 68, 'progress_label' => 'milestone 3 of 4', 'sort' => 2]);
        $vps = $s(['name' => 'VPS · 4 GB', 'type' => 'hosting', 'status' => 'active', 'detail' => 'Toronto region · managed', 'price_cents' => 2900, 'billing_cycle' => 'monthly', 'renews_at' => $today->copy()->addDays(28), 'sort' => 3]);
        $s(['name' => 'oliveandfig.ca', 'type' => 'domain', 'status' => 'active', 'detail' => '.ca registration, auto-renew', 'price_cents' => 1900, 'billing_cycle' => 'yearly', 'renews_at' => $today->copy()->addMonths(4), 'sort' => 4]);
        $care = $s(['name' => 'Care plan', 'type' => 'maintenance', 'status' => 'active', 'detail' => 'Updates, backups, monitoring', 'meta' => 'Billed monthly', 'price_cents' => 24000, 'billing_cycle' => 'monthly', 'renews_at' => $today->copy()->startOfMonth()->addMonth(), 'sort' => 5]);
        $s(['name' => 'Wildcard SSL', 'type' => 'ssl', 'status' => 'active', 'detail' => '*.oliveandfig.ca', 'billing_cycle' => 'included', 'renews_at' => $today->copy()->addDays(28), 'sort' => 6]);
        $s(['name' => 'Dev hours block', 'type' => 'development', 'status' => 'active', 'detail' => '12.5 of 20 h used', 'meta' => 'Expires Dec 31, 2026', 'price_cents' => 12000, 'billing_cycle' => 'hourly', 'hours_total' => 20, 'hours_used' => 12.5, 'progress_label' => '12.5 of 20 h', 'sort' => 7]);

        // 30 days of uptime for the website, with one blip.
        $site = $sara->services()->whereNotNull('monitor_url')->first();
        for ($d = 29; $d >= 0; $d--) {
            for ($h = 0; $h < 24; $h += 2) {
                UptimeCheck::create([
                    'service_id' => $site->id, 'is_up' => ! ($d === 18 && $h === 4),
                    'status_code' => 200, 'response_ms' => rand(80, 240),
                    'checked_at' => $today->copy()->subDays($d)->addHours($h),
                ]);
            }
        }

        $make = function (string $desc, int $daysAgo, array $items, ?InvoiceStatus $paid = null) use ($invoices, $sara, $today) {
            $invoice = $invoices->save(new Invoice, [
                'user_id' => $sara->id, 'description' => $desc, 'tax_rate' => 0,
                'issued_at' => $today->copy()->subDays($daysAgo), 'due_at' => $today->copy()->subDays($daysAgo - 14),
            ], $items);
            $invoice->update(['status' => InvoiceStatus::Unpaid, 'sent_at' => $invoice->issued_at]);
            if ($paid) {
                $payment = Payment::create(['user_id' => $sara->id, 'amount_cents' => $invoice->total_cents, 'method' => 'card', 'status' => 'succeeded', 'paid_at' => $invoice->issued_at->copy()->addDays(3)]);
                $payment->invoices()->attach($invoice->id, ['amount_cents' => $invoice->total_cents]);
                $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => $payment->paid_at]);
            }

            return $invoice;
        };
        $make('Care plan · August', 75, [['description' => 'Care plan · August 2026', 'quantity' => 1, 'unit_price' => 240, 'service_id' => $care->id]], InvoiceStatus::Paid);
        $make('Hosting · Q3', 64, [['description' => 'VPS · 4 GB · Jul–Sep 2026', 'quantity' => 3, 'unit_price' => 29, 'service_id' => $vps->id]], InvoiceStatus::Paid);
        $make('Care plan · September', 44, [['description' => 'Care plan · September 2026', 'quantity' => 1, 'unit_price' => 240, 'service_id' => $care->id]], InvoiceStatus::Paid);
        $make('Development · 8 h', 17, [['description' => 'Ordering flow fixes and menu import', 'quantity' => 8, 'unit_price' => 120]]);
        $make('Care plan · October', 14, [['description' => 'Care plan · October 2026', 'quantity' => 1, 'unit_price' => 240, 'service_id' => $care->id]]);

        $ticket = function (int $number, string $subject, TicketStatus $status, array $messages) use ($sara, $admin) {
            $t = $sara->tickets()->create(['number' => $number, 'subject' => $subject, 'status' => $status]);
            foreach ($messages as [$fromAdmin, $body, $at]) {
                $t->messages()->forceCreate(['user_id' => $fromAdmin ? $admin->id : $sara->id, 'body' => $body, 'created_at' => $at, 'updated_at' => $at]);
            }
            $t->update(['last_activity_at' => end($messages)[2], 'last_admin_reply_at' => collect($messages)->filter(fn ($m) => $m[0])->last()[2] ?? null]);
        };
        $ticket(318, 'Menu PDF not updating on iOS', TicketStatus::Open, [
            [false, 'The new menu PDF shows up on desktop but iPhones still load the old one.', now()->subDay()->setTime(16, 20)],
            [true, 'Found it. Safari is caching the old file. Pushing a versioned URL now, should clear within the hour.', now()->subHours(2)],
        ]);
        $ticket(312, 'Add Apple Pay to checkout', TicketStatus::InProgress, [
            [false, 'Can we add Apple Pay to the ordering checkout?', now()->subDays(7)],
            [true, 'Yes. Needs a domain verification file from Apple, I have sent the request. ETA end of this week.', now()->subDays(6)],
        ]);
        $ticket(301, 'Change opening hours on homepage', TicketStatus::Resolved, [
            [false, 'We now close at 22:00 on weekends.', now()->subDays(13)],
            [true, 'Updated on the homepage and Google listing.', now()->subDays(13)->addHour()],
        ]);

        Activity::query()->where('user_id', $sara->id)->delete();
        foreach ([
            ['renewal', 'Wildcard SSL renewed automatically', 33],
            ['milestone', 'Milestone 3 delivered · Olive & Fig app', 15],
            ['invoice', 'Invoice '.Invoice::query()->latest('id')->value('number').' issued · $240', 14],
            ['ticket', 'Ticket #312 moved to In progress', 1],
        ] as [$kind, $text, $daysAgo]) {
            Activity::create(['user_id' => $sara->id, 'kind' => $kind, 'text' => $text, 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)]);
        }
    }
}
