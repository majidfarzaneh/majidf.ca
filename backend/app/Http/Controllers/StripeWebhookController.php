<?php

namespace App\Http\Controllers;

use App\Services\StripeGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeGateway $stripe): Response
    {
        try {
            $event = $stripe->constructEvent($request->getContent(), $request->header('Stripe-Signature'));
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            Log::warning('Stripe webhook rejected', ['error' => $e->getMessage()]);

            return response('Invalid signature', 400);
        }

        $stripe->handle($event);

        return response('ok');
    }
}
