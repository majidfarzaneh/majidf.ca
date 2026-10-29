<?php

namespace Tests\Feature;

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvoiceRemindersTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(8, 0));
        $this->client = User::factory()->create();
        User::factory()->admin()->create(); // receives the bell notifications
    }

    /** An invoice issued Oct 1, due Oct 15, payable online until Oct 15 + $grace. */
    private function invoice(int $grace = 3): Invoice
    {
        $invoice = app(InvoiceManager::class)->save(new Invoice, [
            'user_id' => $this->client->id, 'description' => 'Care plan', 'tax_rate' => 0,
            'due_at' => '2026-10-15', 'grace_days' => $grace,
        ], [['description' => 'Care plan · October', 'quantity' => 1, 'unit_price' => 240]]);

        return app(InvoiceManager::class)->send($invoice);
    }

    /** Run the daily job on a date and return the reminder kinds it emailed. */
    private function runOn(string $date): array
    {
        $this->travelTo(now()->parse($date)->setTime(8, 0));
        $before = Mail::queued(InvoiceMail::class)->count();
        $this->artisan('billing:run')->assertSuccessful();

        return Mail::queued(InvoiceMail::class)->slice($before)->pluck('kind')->values()->all();
    }

    public function test_full_reminder_timeline_sends_each_reminder_once(): void
    {
        $invoice = $this->invoice(grace: 3);
        Mail::assertQueued(InvoiceMail::class, fn ($m) => $m->kind === 'issued');

        $this->assertSame([], $this->runOn('2026-10-11'));             // 4 days before: nothing yet
        $this->assertSame(['due_soon'], $this->runOn('2026-10-12'));   // 3 days before
        $this->assertSame([], $this->runOn('2026-10-13'));             // already sent
        $this->assertSame(['due_today'], $this->runOn('2026-10-15'));
        $this->assertSame(['overdue'], $this->runOn('2026-10-16'));    // in the grace period
        $this->assertTrue($invoice->refresh()->isPayable());
        $this->assertSame([], $this->runOn('2026-10-18'));             // last payable day
        $this->assertTrue($invoice->refresh()->isPayable());

        $this->assertSame(['closed'], $this->runOn('2026-10-19'));     // grace over
        $invoice->refresh();
        $this->assertFalse($invoice->isPayable());
        $this->assertTrue($invoice->isExpired());
        $this->assertSame('Expired', $invoice->displayStatus());
        $this->assertAdminNotified('expired unpaid');

        $this->assertSame([], $this->runOn('2026-10-26'));             // no more reminders once closed
    }

    public function test_zero_grace_days_closes_the_day_after_the_due_date(): void
    {
        $invoice = $this->invoice(grace: 0);

        $this->assertSame(['due_today'], $this->runOn('2026-10-15'));
        $this->assertTrue($invoice->refresh()->isPayable());
        $this->assertSame(['closed'], $this->runOn('2026-10-16'));
    }

    public function test_paid_invoices_get_no_reminders(): void
    {
        $invoice = $this->invoice();
        $this->actingAs(User::factory()->admin()->create())
            ->postJson("/api/admin/invoices/{$invoice->id}/payments", ['method' => 'etransfer'])->assertOk();

        $this->assertSame([], $this->runOn('2026-10-15'));
    }

    public function test_reminders_respect_the_new_invoices_preference_but_admin_is_still_alerted(): void
    {
        $this->client->update(['notification_prefs' => ['inv' => false]]);
        $invoice = $this->invoice(grace: 0);

        $this->assertSame([], $this->runOn('2026-10-16'));
        $this->assertTrue($invoice->refresh()->reminderSent('closed'));
        $this->assertAdminNotified('expired unpaid');
    }

    public function test_moving_the_due_date_restarts_the_schedule(): void
    {
        $invoice = $this->invoice(grace: 0);
        $this->assertSame(['due_today'], $this->runOn('2026-10-15'));

        $invoice->update(['due_at' => '2026-10-30']);
        $this->assertNull($invoice->refresh()->reminders);
        $this->assertSame('2026-10-30', $invoice->pay_until->toDateString());
        $this->assertSame(['due_today'], $this->runOn('2026-10-30'));
    }

    public function test_expired_invoices_cannot_be_paid_online_but_admin_can_record_payment(): void
    {
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.key' => 'pk_test_x']);
        $invoice = $this->invoice(grace: 2);
        $this->travelTo(now()->parse('2026-10-18'));

        $this->actingAs($this->client)->getJson('/api/portal/invoices')->assertOk()
            ->assertJsonPath('data.0.display_status', 'Expired')
            ->assertJsonPath('data.0.payable', false)
            ->assertJsonPath('data.0.pay_until', '2026-10-17')
            ->assertJsonPath('totals.outstanding_cents', 24000)
            ->assertJsonPath('totals.payable_cents', 0);

        $this->postJson('/api/portal/payments/intent', ['invoice_ids' => [$invoice->id]])
            ->assertStatus(422)->assertJsonValidationErrors('invoice_ids');

        $this->actingAs(User::factory()->admin()->create())
            ->postJson("/api/admin/invoices/{$invoice->id}/payments", ['method' => 'cheque'])
            ->assertOk()->assertJsonPath('data.status', 'paid');
    }

    public function test_grace_days_are_editable_from_the_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $id = $this->actingAs($admin)->postJson('/api/admin/invoices', [
            'user_id' => $this->client->id, 'description' => 'Build', 'tax_rate' => 0,
            'issued_at' => '2026-10-01', 'due_at' => '2026-10-15', 'grace_days' => 7,
            'items' => [['description' => 'Work', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated()->assertJsonPath('data.grace_days', 7)->assertJsonPath('data.pay_until', '2026-10-22')->json('data.id');

        $this->putJson("/api/admin/invoices/{$id}", [
            'description' => 'Build', 'tax_rate' => 0, 'issued_at' => '2026-10-01', 'due_at' => '2026-10-15', 'grace_days' => 0,
            'items' => [['description' => 'Work', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertOk()->assertJsonPath('data.pay_until', '2026-10-15');

        $this->postJson('/api/admin/invoices', [
            'user_id' => $this->client->id, 'description' => 'Bad', 'tax_rate' => 0, 'grace_days' => -1,
            'items' => [['description' => 'Work', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertJsonValidationErrors('grace_days');
    }
}
