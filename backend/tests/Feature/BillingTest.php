<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Mail\InvoiceMail;
use App\Mail\PaymentReceiptMail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Stripe\Event;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->admin()->create();
        $this->client = User::factory()->create();
    }

    private function createInvoice(bool $send = true): Invoice
    {
        $id = $this->actingAs($this->admin)->postJson('/api/admin/invoices', [
            'user_id' => $this->client->id,
            'description' => 'Development · 8 h',
            'tax_rate' => 13,
            'items' => [
                ['description' => 'Feature work', 'quantity' => 8, 'unit_price' => 120],
                ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => '29.50'],
            ],
            'send' => $send,
        ])->assertCreated()->json('data.id');

        return Invoice::findOrFail($id);
    }

    public function test_admin_creates_and_sends_invoice_with_tax(): void
    {
        $invoice = $this->createInvoice();

        $this->assertSame(96000 + 2950, $invoice->subtotal_cents);
        $this->assertSame((int) round(98950 * 0.13), $invoice->tax_cents);
        $this->assertSame($invoice->subtotal_cents + $invoice->tax_cents, $invoice->total_cents);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertNotNull($invoice->due_at);
        Mail::assertQueued(InvoiceMail::class, fn ($m) => $m->hasTo($this->client->email));
    }

    public function test_drafts_are_hidden_from_the_client(): void
    {
        $this->createInvoice(send: false);

        $this->actingAs($this->client)->getJson('/api/portal/invoices')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_client_cannot_download_another_clients_invoice(): void
    {
        $invoice = $this->createInvoice();
        $other = User::factory()->create();

        $this->actingAs($other)->get("/api/portal/invoices/{$invoice->id}/pdf")->assertNotFound();
        $this->actingAs($this->client)->get("/api/portal/invoices/{$invoice->id}/pdf")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_admin_records_manual_payment(): void
    {
        $invoice = $this->createInvoice();

        $this->actingAs($this->admin)->postJson("/api/admin/invoices/{$invoice->id}/payments", [
            'method' => 'etransfer', 'reference' => 'CA123', 'send_receipt' => true,
        ])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        Mail::assertQueued(PaymentReceiptMail::class);
        $this->assertDatabaseHas('activities', ['user_id' => $this->client->id, 'kind' => 'payment']);
    }

    public function test_stripe_webhook_marks_invoices_paid_once(): void
    {
        $invoice = $this->createInvoice();
        $payment = Payment::create([
            'user_id' => $this->client->id, 'amount_cents' => $invoice->total_cents,
            'method' => 'card', 'status' => 'pending', 'stripe_payment_intent_id' => 'pi_test_123',
        ]);
        $payment->invoices()->attach($invoice->id, ['amount_cents' => $invoice->total_cents]);

        $event = Event::constructFrom([
            'id' => 'evt_1', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_test_123', 'object' => 'payment_intent', 'metadata' => ['payment_id' => $payment->id]]],
        ]);
        app(StripeGateway::class)->handle($event);
        app(StripeGateway::class)->handle($event);

        $this->assertSame('succeeded', $payment->refresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        Mail::assertQueued(PaymentReceiptMail::class, 1);
    }

    public function test_webhook_rejects_bad_signature(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $this->postJson('/api/stripe/webhook', ['type' => 'payment_intent.succeeded'], ['Stripe-Signature' => 't=1,v1=bad'])
            ->assertStatus(400);
    }

    public function test_payments_are_unavailable_without_stripe_keys(): void
    {
        $invoice = $this->createInvoice();
        config(['services.stripe.secret' => null]);

        $this->actingAs($this->client)->postJson('/api/portal/payments/intent', ['invoice_ids' => [$invoice->id]])
            ->assertStatus(503);
    }

    public function test_recurring_service_issues_invoice_and_rolls_forward(): void
    {
        $service = Service::create([
            'user_id' => $this->client->id, 'name' => 'VPS', 'type' => 'hosting', 'status' => 'active',
            'price_cents' => 2900, 'billing_cycle' => 'monthly', 'auto_invoice' => true, 'renews_at' => today(),
        ]);

        $this->artisan('billing:run')->assertSuccessful();

        $invoice = Invoice::query()->where('user_id', $this->client->id)->sole();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(2900, $invoice->subtotal_cents);
        $this->assertTrue($service->refresh()->renews_at->isSameDay(today()->addMonthNoOverflow()));

        // Running again the same day issues nothing new.
        $this->artisan('billing:run')->assertSuccessful();
        $this->assertSame(1, Invoice::query()->count());
    }
}
