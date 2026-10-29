<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Activity;
use App\Models\Payment;
use App\Services\StripeGateway;
use App\Support\AdminAlert;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** The client's saved card and auto-pay (Profile → Payment method). The card itself stays in Stripe. */
class PaymentMethodController extends Controller
{
    public function __construct(private StripeGateway $stripe) {}

    /** Start saving a card: returns the SetupIntent secret for the Payment Element. */
    public function setup(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        return response()->json(['client_secret' => $this->stripe->createSetupIntent($request->user())]);
    }

    /** The browser confirmed the SetupIntent: keep the card and turn auto-pay on. */
    public function store(Request $request): UserResource
    {
        $this->ensureEnabled();
        $data = $request->validate(['setup_intent' => ['required', 'string', 'starts_with:seti_']]);

        $user = $this->stripe->saveCardFromSetup($request->user(), $data['setup_intent']);
        Activity::log($user->id, 'payment', 'Auto-pay on · '.ucfirst($user->card_brand).' •••• '.$user->card_last4);
        Audit::log('autopay.card_saved', 'Saved a card for auto-pay · '.ucfirst($user->card_brand).' •••• '.$user->card_last4, $user);
        AdminAlert::send('autopay', ($user->company ?: $user->name).' turned on auto-pay', [
            'Card' => ucfirst($user->card_brand).' •••• '.$user->card_last4,
        ], '/clients/'.$user->id);

        return new UserResource($user);
    }

    /** Turn auto-pay on or off; the card stays saved either way. */
    public function autopay(Request $request): UserResource
    {
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $user = $request->user();
        if ($enabled && ! $user->hasSavedCard()) {
            throw ValidationException::withMessages(['enabled' => 'Add a card first.']);
        }

        $user->forceFill(['autopay' => $enabled] + ($enabled ? ['autopay_consented_at' => now()] : []))->save();
        Activity::log($user->id, 'payment', $enabled ? 'Auto-pay turned on' : 'Auto-pay turned off');
        Audit::log($enabled ? 'autopay.on' : 'autopay.off', $enabled ? 'Turned auto-pay on' : 'Turned auto-pay off', $user);
        AdminAlert::send('autopay', ($user->company ?: $user->name).' turned auto-pay '.($enabled ? 'on' : 'off'), [], '/clients/'.$user->id);

        return new UserResource($user);
    }

    /** Remove the saved card (detached in Stripe) and turn auto-pay off. */
    public function destroy(Request $request): UserResource
    {
        $user = $request->user();
        if ($user->hasSavedCard()) {
            $card = ucfirst((string) $user->card_brand).' •••• '.$user->card_last4;
            $this->stripe->removeCard($user);
            Activity::log($user->id, 'payment', 'Saved card removed · auto-pay off');
            Audit::log('autopay.card_removed', "Removed their saved card ({$card}) · auto-pay off", $user);
            AdminAlert::send('autopay', ($user->company ?: $user->name).' removed their saved card', ['Card' => $card], '/clients/'.$user->id);
        }

        return new UserResource($user);
    }

    /** "Save this card for automatic payments" in the card form, set before the payment is confirmed. */
    public function saveOnPayment(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->user_id === $request->user()->id, 404);
        $this->stripe->saveCardOnPayment($payment, $request->validate(['save' => ['required', 'boolean']])['save']);

        return response()->json(['ok' => true]);
    }

    private function ensureEnabled(): void
    {
        abort_unless($this->stripe->enabled(), 503, 'Online payments are not set up yet.');
    }
}
