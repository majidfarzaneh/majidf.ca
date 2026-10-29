<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = (bool) $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'description' => $this->description,
            'status' => $this->status->value,
            'display_status' => $this->displayStatus(),
            'open' => $this->isOpen(),
            'payable' => $this->isPayable(),
            'overdue' => $this->isOverdue(),
            'expired' => $this->isExpired(),
            'in_review' => $this->isInReview(),
            'accepts_offline' => $this->acceptsOfflinePayment(),
            // Latest offline proof that was rejected while the invoice is still open, so the client sees why.
            'rejection' => $this->when($this->isOpen() && $this->relationLoaded('payments'), function () {
                $last = $this->payments->whereIn('status', ['review', 'rejected', 'succeeded'])->sortByDesc('id')->first();

                return $last?->status === 'rejected' ? ['reason' => $last->rejection_reason, 'at' => $last->reviewed_at?->toIso8601String()] : null;
            }),
            'grace_days' => $this->grace_days,
            'pay_until' => $this->pay_until?->toDateString(),
            'issued_at' => $this->issued_at?->toDateString(),
            'due_at' => $this->due_at?->toDateString(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'currency' => $this->currency,
            'subtotal_cents' => $this->subtotal_cents,
            'tax_rate' => $this->tax_rate,
            'tax_label' => $this->tax_label,
            'tax_cents' => $this->tax_cents,
            'total_cents' => $this->total_cents,
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'service_id' => $item->service_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->unit_price_cents,
                'amount_cents' => $item->amount_cents,
            ])),
            $this->mergeWhen($admin, fn () => [
                'user_id' => $this->user_id,
                'user' => new UserResource($this->whenLoaded('user')),
                'sent_at' => $this->sent_at?->toIso8601String(),
                'reminded_at' => $this->reminded_at?->toIso8601String(),
                'reminders' => (object) ($this->reminders ?? []),
                'payments' => PaymentResource::collection($this->whenLoaded('payments')),
                'created_at' => $this->created_at?->toIso8601String(),
            ]),
        ];
    }
}
