<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeGateway
{
    public function __construct(private PaymentRecorder $recorder) {}

    public function enabled(): bool
    {
        return filled(config('services.stripe.secret')) && filled(config('services.stripe.key'));
    }

    public function client(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Start an in-portal card payment (Stripe Payment Element).
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return array{payment: Payment, client_secret: string}
     */
    public function createIntent(User $user, Collection $invoices): array
    {
        $payment = $this->recorder->pending($user, $invoices, 'card');

        $intent = $this->client()->paymentIntents->create([
            'amount' => $payment->amount_cents,
            'currency' => strtolower($payment->currency),
            'customer' => $this->customerId($user),
            'automatic_payment_methods' => ['enabled' => true],
            'description' => $this->describe($invoices),
            'receipt_email' => $user->email,
            'metadata' => ['payment_id' => $payment->id, 'invoices' => $invoices->pluck('number')->implode(',')],
        ], ['idempotency_key' => 'payment-'.$payment->id]);

        $payment->update(['stripe_payment_intent_id' => $intent->id]);

        return ['payment' => $payment, 'client_secret' => $intent->client_secret];
    }

    /**
     * Start a hosted Stripe Checkout session (Apple Pay, Google Pay, Link…).
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    public function createCheckout(User $user, Collection $invoices): string
    {
        $payment = $this->recorder->pending($user, $invoices, 'checkout');
        $base = config('business.portal_url').'/invoices';

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'customer' => $this->customerId($user),
            'line_items' => $invoices->map(fn (Invoice $i) => [
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($i->currency),
                    'unit_amount' => $i->total_cents,
                    'product_data' => ['name' => $i->number.' · '.$i->description],
                ],
            ])->values()->all(),
            'payment_intent_data' => [
                'description' => $this->describe($invoices),
                'metadata' => ['payment_id' => $payment->id],
            ],
            'metadata' => ['payment_id' => $payment->id],
            'success_url' => $base.'?checkout=success&payment='.$payment->id,
            'cancel_url' => $base.'?checkout=cancelled',
        ], ['idempotency_key' => 'checkout-'.$payment->id]);

        $payment->update(['stripe_checkout_session_id' => $session->id]);

        return $session->url;
    }

    /** Ask Stripe for the latest state of a payment (used right after the client confirms). */
    public function sync(Payment $payment): Payment
    {
        if ($payment->status !== 'pending' || ! $this->enabled()) {
            return $payment;
        }

        if ($payment->stripe_checkout_session_id) {
            $session = $this->client()->checkout->sessions->retrieve($payment->stripe_checkout_session_id);
            if ($session->payment_status === 'paid') {
                return $this->recorder->succeeded($payment, ['stripe_payment_intent_id' => $session->payment_intent]);
            }
        } elseif ($payment->stripe_payment_intent_id) {
            $intent = $this->client()->paymentIntents->retrieve($payment->stripe_payment_intent_id);
            if ($intent->status === 'succeeded') {
                return $this->recorder->succeeded($payment);
            }
        }

        return $payment;
    }

    public function constructEvent(string $payload, ?string $signature): Event
    {
        return Webhook::constructEvent($payload, (string) $signature, (string) config('services.stripe.webhook_secret'));
    }

    public function handle(Event $event): void
    {
        $object = $event->data->object;

        $payment = match ($event->type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => Payment::query()
                ->where('stripe_checkout_session_id', $object->id)->first(),
            'payment_intent.succeeded', 'payment_intent.payment_failed' => Payment::query()
                ->where('stripe_payment_intent_id', $object->id)
                ->orWhere('id', $object->metadata['payment_id'] ?? 0)->first(),
            default => null,
        };

        if (! $payment) {
            Log::info('Stripe webhook ignored', ['type' => $event->type, 'id' => $object->id ?? null]);

            return;
        }

        match ($event->type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $object->payment_status === 'paid'
                ? $this->recorder->succeeded($payment, ['stripe_payment_intent_id' => $object->payment_intent])
                : null,
            'payment_intent.succeeded' => $this->recorder->succeeded($payment, ['stripe_payment_intent_id' => $object->id]),
            'payment_intent.payment_failed' => $this->recorder->failed($payment, $object->last_payment_error->message ?? null),
        };
    }

    private function customerId(User $user): string
    {
        if (! $user->stripe_customer_id) {
            $customer = $this->client()->customers->create([
                'email' => $user->email,
                'name' => $user->company ?: $user->name,
                'metadata' => ['user_id' => $user->id],
            ]);
            $user->forceFill(['stripe_customer_id' => $customer->id])->save();
        }

        return $user->stripe_customer_id;
    }

    /** @param  Collection<int, Invoice>  $invoices */
    private function describe(Collection $invoices): string
    {
        return config('business.name').' · '.$invoices->pluck('number')->implode(', ');
    }
}
