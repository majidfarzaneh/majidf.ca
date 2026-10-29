<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Mail\AdminAlertMail;
use App\Mail\InvoiceMail;
use App\Mail\PaymentReceiptMail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\PaymentRecorder;
use App\Services\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Mockery\MockInterface;
use Stripe\Event;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\StripeClient;
use Tests\TestCase;

class AutopayTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    /** Stands in for StripeClient: one Mockery mock per Stripe service the gateway uses. */
    private StripeClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x', 'business.admin_email' => 'admin@example.com']);

        $this->client = User::factory()->create();
        $this->client->forceFill(['stripe_customer_id' => 'cus_1'])->save();

        // A real StripeClient subclass (the gateway's return type) whose services are plain properties.
        $this->stripe = new class('sk_test_x') extends StripeClient
        {
            public MockInterface $paymentIntents;

            public MockInterface $setupIntents;

            public MockInterface $paymentMethods;

            public MockInterface $customers;
        };
        foreach (['paymentIntents', 'setupIntents', 'paymentMethods', 'customers'] as $service) {
            $this->stripe->{$service} = Mockery::mock();
        }
        // Built with its real PaymentRecorder; only client() is replaced.
        $gateway = Mockery::mock(StripeGateway::class, [app(PaymentRecorder::class)])->makePartial();
        $gateway->shouldReceive('client')->andReturn($this->stripe);
        $this->instance(StripeGateway::class, $gateway);
    }

    private function withSavedCard(): void
    {
        $this->client->forceFill([
            'stripe_payment_method_id' => 'pm_saved', 'card_brand' => 'visa', 'card_last4' => '4242',
            'card_exp_month' => 8, 'card_exp_year' => 2030, 'autopay' => true, 'autopay_consented_at' => now(),
        ])->save();
    }

    private function renewingService(): Service
    {
        return Service::create([
            'user_id' => $this->client->id, 'name' => 'VPS', 'type' => 'hosting', 'status' => 'active',
            'price_cents' => 2900, 'billing_cycle' => 'monthly', 'auto_invoice' => true, 'renews_at' => today(),
        ]);
    }

    public function test_renewal_charges_the_saved_card(): void
    {
        $this->withSavedCard();
        $this->renewingService();
        $this->stripe->paymentIntents->shouldReceive('create')->once()
            ->withArgs(fn ($params) => $params['payment_method'] === 'pm_saved' && $params['off_session'] && $params['confirm'] && $params['customer'] === 'cus_1')
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_auto', 'status' => 'succeeded']));

        $this->artisan('billing:run')->assertSuccessful();

        $invoice = Invoice::query()->sole();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $payment = Payment::query()->sole();
        $this->assertSame(['autopay', 'succeeded', 'pi_auto'], [$payment->method, $payment->status, $payment->stripe_payment_intent_id]);
        // One receipt instead of an invoice they'd have to pay.
        Mail::assertQueued(PaymentReceiptMail::class, 1);
        Mail::assertNotQueued(InvoiceMail::class);
    }

    public function test_admin_renewal_invoice_charges_an_auto_pay_client(): void
    {
        $this->withSavedCard();
        $service = $this->renewingService();
        $service->update(['auto_invoice' => false, 'renews_at' => today()->addDays(10)]);
        $this->stripe->paymentIntents->shouldReceive('create')->once()
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_now', 'status' => 'succeeded']));

        $this->actingAs(User::factory()->admin()->create())->postJson("/api/admin/services/{$service->id}/renewal-invoice")
            ->assertCreated()->assertJsonPath('autopay', 'succeeded')->assertJsonPath('invoice.status', 'paid');

        Mail::assertQueued(PaymentReceiptMail::class, 1);
        Mail::assertNotQueued(InvoiceMail::class);
    }

    public function test_declined_card_leaves_the_invoice_to_pay_and_tells_everyone(): void
    {
        $this->withSavedCard();
        $this->renewingService();
        $this->stripe->paymentIntents->shouldReceive('create')->once()->andThrow(CardException::factory(
            'Your card was declined.', 402, null,
            ['error' => ['code' => 'card_declined', 'message' => 'Your card was declined.', 'payment_intent' => ['id' => 'pi_declined']]],
            null, 'card_declined',
        ));

        $this->artisan('billing:run')->assertSuccessful();

        $invoice = Invoice::query()->sole();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertTrue($invoice->isPayable());
        $payment = Payment::query()->sole();
        $this->assertSame(['failed', 'pi_declined'], [$payment->status, $payment->stripe_payment_intent_id]);
        Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $m) => $m->kind === 'autopay_failed'
            && $m->hasTo($this->client->email) && str_contains($m->render(), 'Your card was declined'));
        Mail::assertQueued(AdminAlertMail::class, fn (AdminAlertMail $m) => $m->hasTo('admin@example.com'));
        Mail::assertNotQueued(PaymentReceiptMail::class);
    }

    public function test_bank_confirmation_request_is_explained(): void
    {
        $this->withSavedCard();
        $this->renewingService();
        $this->stripe->paymentIntents->shouldReceive('create')->andThrow(CardException::factory(
            'Authentication required.', 402, null, ['error' => ['code' => 'authentication_required']], null, 'authentication_required',
        ));

        $this->artisan('billing:run')->assertSuccessful();

        $this->assertStringContainsString('Your bank asked to confirm', Payment::query()->sole()->note);
    }

    public function test_without_autopay_the_invoice_is_emailed_as_before(): void
    {
        $this->renewingService();
        $this->stripe->paymentIntents->shouldNotReceive('create');

        $this->artisan('billing:run')->assertSuccessful();

        $this->assertSame(InvoiceStatus::Unpaid, Invoice::query()->sole()->status);
        Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $m) => $m->kind === 'issued');
    }

    public function test_client_saves_a_card_and_auto_pay_turns_on(): void
    {
        $this->stripe->setupIntents->shouldReceive('retrieve')->with('seti_1')
            ->andReturn(SetupIntent::constructFrom(['id' => 'seti_1', 'customer' => 'cus_1', 'status' => 'succeeded', 'payment_method' => 'pm_new']));
        $this->stripe->paymentMethods->shouldReceive('retrieve')->with('pm_new')->andReturn(PaymentMethod::constructFrom([
            'id' => 'pm_new', 'customer' => 'cus_1', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2030],
        ]));
        $this->stripe->customers->shouldReceive('update')->once()->with('cus_1', ['invoice_settings' => ['default_payment_method' => 'pm_new']]);

        $this->actingAs($this->client)->postJson('/api/portal/payment-method', ['setup_intent' => 'seti_1'])
            ->assertOk()
            ->assertJsonPath('data.autopay', true)
            ->assertJsonPath('data.card.last4', '4242')
            ->assertJsonMissingPath('data.stripe_payment_method_id');

        $this->assertNotNull($this->client->fresh()->autopay_consented_at);
    }

    public function test_a_setup_intent_from_another_customer_is_rejected(): void
    {
        $this->stripe->setupIntents->shouldReceive('retrieve')
            ->andReturn(SetupIntent::constructFrom(['id' => 'seti_x', 'customer' => 'cus_other', 'status' => 'succeeded', 'payment_method' => 'pm_x']));
        $this->stripe->paymentMethods->shouldNotReceive('retrieve');

        $this->actingAs($this->client)->postJson('/api/portal/payment-method', ['setup_intent' => 'seti_x'])->assertStatus(422);
        $this->assertFalse($this->client->fresh()->hasSavedCard());
    }

    public function test_client_removes_the_card(): void
    {
        $this->withSavedCard();
        $this->stripe->paymentMethods->shouldReceive('detach')->once()->with('pm_saved');

        $this->actingAs($this->client)->deleteJson('/api/portal/payment-method')
            ->assertOk()->assertJsonPath('data.card', null)->assertJsonPath('data.autopay', false);
    }

    public function test_auto_pay_can_be_paused_but_needs_a_card_to_turn_on(): void
    {
        $this->actingAs($this->client)->putJson('/api/portal/payment-method/autopay', ['enabled' => true])->assertStatus(422);

        $this->withSavedCard();
        $this->actingAs($this->client)->putJson('/api/portal/payment-method/autopay', ['enabled' => false])
            ->assertOk()->assertJsonPath('data.autopay', false)->assertJsonPath('data.card.last4', '4242');
    }

    public function test_card_saved_while_paying_is_kept_when_the_payment_succeeds(): void
    {
        $invoice = Invoice::create([
            'user_id' => $this->client->id, 'number' => 'INV-1', 'description' => 'Work', 'status' => 'unpaid',
            'subtotal_cents' => 1000, 'tax_cents' => 0, 'total_cents' => 1000, 'issued_at' => today(), 'due_at' => today()->addDays(14),
        ]);
        $payment = Payment::create([
            'user_id' => $this->client->id, 'amount_cents' => 1000, 'method' => 'card', 'status' => 'pending',
            'stripe_payment_intent_id' => 'pi_pay', 'currency' => 'CAD',
        ]);
        $payment->invoices()->attach($invoice->id, ['amount_cents' => 1000]);

        $this->stripe->paymentIntents->shouldReceive('update')->once()->with('pi_pay', ['setup_future_usage' => 'off_session']);
        $this->actingAs($this->client)->postJson("/api/portal/payments/{$payment->id}/save-card", ['save' => true])->assertOk();

        $this->stripe->paymentMethods->shouldReceive('retrieve')->with('pm_paid')->andReturn(PaymentMethod::constructFrom([
            'id' => 'pm_paid', 'customer' => 'cus_1', 'card' => ['brand' => 'mastercard', 'last4' => '4444', 'exp_month' => 1, 'exp_year' => 2031],
        ]));
        $this->stripe->customers->shouldReceive('update')->once();

        app(StripeGateway::class)->handle(Event::constructFrom([
            'id' => 'evt_1', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_pay', 'object' => 'payment_intent', 'payment_method' => 'pm_paid', 'metadata' => ['payment_id' => $payment->id]]],
        ]));

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $client = $this->client->fresh();
        $this->assertSame(['pm_paid', '4444', true], [$client->stripe_payment_method_id, $client->card_last4, $client->autopay]);
    }

    public function test_admin_cannot_record_an_auto_pay_payment_by_hand(): void
    {
        $invoice = Invoice::create([
            'user_id' => $this->client->id, 'number' => 'INV-2', 'description' => 'Work', 'status' => 'unpaid',
            'subtotal_cents' => 1000, 'tax_cents' => 0, 'total_cents' => 1000, 'issued_at' => today(), 'due_at' => today()->addDays(14),
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson("/api/admin/invoices/{$invoice->id}/payments", ['method' => 'autopay'])
            ->assertStatus(422)->assertJsonValidationErrors('method');
    }
}
