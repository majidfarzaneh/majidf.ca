<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
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
                $payment = $this->recorder->succeeded($payment);
                $this->keepCardFrom($payment, $intent->payment_method);

                return $payment;
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
            'payment_intent.succeeded' => $this->keepCardFrom(
                $this->recorder->succeeded($payment, ['stripe_payment_intent_id' => $object->id]),
                $object->payment_method ?? null,
            ),
            'payment_intent.payment_failed' => $this->recorder->failed($payment, $object->last_payment_error->message ?? null),
        };
    }

    /**
     * "Save this card for automatic payments" ticked in the card form: ask Stripe to keep the card
     * for off-session use. Must happen before the browser confirms the payment.
     */
    public function saveCardOnPayment(Payment $payment, bool $save): void
    {
        abort_unless($payment->method === 'card' && $payment->status === 'pending' && $payment->stripe_payment_intent_id, 422, 'This payment can no longer be changed.');

        $this->client()->paymentIntents->update($payment->stripe_payment_intent_id, [
            'setup_future_usage' => $save ? 'off_session' : '',
        ]);
        $payment->update(['save_card' => $save]);
    }

    /** Start saving a card without paying anything (Profile → Payment method). */
    public function createSetupIntent(User $user): string
    {
        return $this->client()->setupIntents->create([
            'customer' => $this->customerId($user),
            'payment_method_types' => ['card'],
            'usage' => 'off_session',
            'metadata' => ['user_id' => $user->id],
        ])->client_secret;
    }

    /** The browser confirmed the SetupIntent: keep its card and turn auto-pay on (the client consented in the form). */
    public function saveCardFromSetup(User $user, string $setupIntentId): User
    {
        $setup = $this->client()->setupIntents->retrieve($setupIntentId);
        if ($setup->customer !== $user->stripe_customer_id || $setup->status !== 'succeeded' || ! $setup->payment_method) {
            throw ValidationException::withMessages(['card' => 'The card could not be saved. Please try again.']);
        }

        return $this->rememberCard($user, $setup->payment_method);
    }

    /** Forget the saved card here and detach it in Stripe; auto-pay turns off. */
    public function removeCard(User $user): User
    {
        if ($user->stripe_payment_method_id) {
            try {
                $this->client()->paymentMethods->detach($user->stripe_payment_method_id);
            } catch (ApiErrorException $e) {
                Log::warning('Stripe card detach failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        $user->forceFill([
            'stripe_payment_method_id' => null, 'card_brand' => null, 'card_last4' => null,
            'card_exp_month' => null, 'card_exp_year' => null, 'autopay' => false,
        ])->save();

        return $user;
    }

    /**
     * Auto-pay: charge the client's saved card for an issued invoice, without them present.
     * Succeeds, stays pending (Stripe still processing; the webhook finishes it) or fails with the reason.
     */
    public function chargeSavedCard(Invoice $invoice): Payment
    {
        $user = $invoice->user;
        $payment = $this->recorder->pending($user, collect([$invoice]), 'autopay');

        try {
            $intent = $this->client()->paymentIntents->create([
                'amount' => $payment->amount_cents,
                'currency' => strtolower($payment->currency),
                'customer' => $this->customerId($user),
                'payment_method' => $user->stripe_payment_method_id,
                'off_session' => true,
                'confirm' => true,
                'description' => $this->describe(collect([$invoice])).' · auto-pay',
                'metadata' => ['payment_id' => $payment->id, 'invoices' => $invoice->number, 'autopay' => '1'],
            ], ['idempotency_key' => 'autopay-'.$payment->id]);
        } catch (CardException $e) {
            if ($id = $e->getError()?->payment_intent?->id) {
                $payment->update(['stripe_payment_intent_id' => $id]);
            }
            $this->recorder->failed($payment, $e->getStripeCode() === 'authentication_required'
                ? 'Your bank asked to confirm this payment, so it could not be charged automatically.'
                : ($e->getMessage() ?: 'Your card was declined.'));

            return $payment->refresh();
        } catch (ApiErrorException $e) {
            Log::error('Auto-pay charge failed', ['invoice' => $invoice->number, 'error' => $e->getMessage()]);
            $this->recorder->failed($payment, 'The automatic payment could not be processed.');

            return $payment->refresh();
        }

        $payment->update(['stripe_payment_intent_id' => $intent->id]);

        if ($intent->status === 'succeeded') {
            return $this->recorder->succeeded($payment);
        }
        // "processing" finishes through the webhook; anything else needs the client.
        if ($intent->status !== 'processing') {
            $this->recorder->failed($payment, 'The automatic payment needs your confirmation.');
        }

        return $payment->refresh();
    }

    /** After a "save this card" payment succeeds, keep the card it used. */
    private function keepCardFrom(Payment $payment, mixed $paymentMethod): Payment
    {
        $id = is_string($paymentMethod) ? $paymentMethod : ($paymentMethod->id ?? null);
        if ($payment->save_card && $id && $payment->user->stripe_payment_method_id !== $id) {
            try {
                $this->rememberCard($payment->user, $id);
            } catch (ApiErrorException $e) {
                Log::warning('Could not keep card after payment', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            }
        }

        return $payment;
    }

    /** Store a card's display details, make it the customer's default and drop the card it replaces. */
    private function rememberCard(User $user, string $paymentMethodId): User
    {
        $method = $this->client()->paymentMethods->retrieve($paymentMethodId);
        if ($method->customer !== $user->stripe_customer_id || ! $method->card) {
            throw ValidationException::withMessages(['card' => 'That card does not belong to this account.']);
        }

        $this->client()->customers->update($user->stripe_customer_id, [
            'invoice_settings' => ['default_payment_method' => $method->id],
        ]);

        $previous = $user->stripe_payment_method_id;
        $user->forceFill([
            'stripe_payment_method_id' => $method->id,
            'card_brand' => $method->card->brand,
            'card_last4' => $method->card->last4,
            'card_exp_month' => $method->card->exp_month,
            'card_exp_year' => $method->card->exp_year,
            'autopay' => true,
            'autopay_consented_at' => now(),
        ])->save();

        if ($previous && $previous !== $method->id) {
            try {
                $this->client()->paymentMethods->detach($previous);
            } catch (ApiErrorException $e) {
                Log::warning('Could not detach replaced card', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        return $user;
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
