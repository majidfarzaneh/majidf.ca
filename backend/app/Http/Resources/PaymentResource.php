<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'method' => $this->method,
            'method_label' => $this->methodLabel(),
            'status' => $this->status,
            'reference' => $this->reference,
            'note' => $this->note,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'client_note' => $this->client_note,
            'has_receipt' => $this->receipt_path !== null,
            'receipt_name' => $this->receipt_name,
            'receipt_mime' => $this->receipt_mime,
            'receipt_size' => $this->receipt_size,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'stripe_payment_intent_id' => $this->when($request->user()?->isAdmin(), $this->stripe_payment_intent_id),
            'invoices' => $this->whenLoaded('invoices', fn () => $this->invoices->map(fn ($i) => [
                'id' => $i->id, 'number' => $i->number, 'description' => $i->description, 'amount_cents' => $i->pivot->amount_cents,
            ])),
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
