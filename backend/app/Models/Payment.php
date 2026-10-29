<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'user_id', 'amount_cents', 'currency', 'method', 'status', 'reference',
    'stripe_payment_intent_id', 'stripe_checkout_session_id', 'save_card', 'note', 'paid_at', 'client_note',
    'receipt_path', 'receipt_name', 'receipt_mime', 'receipt_size', 'submitted_at', 'reviewed_at', 'reviewed_by', 'rejection_reason',
])]
class Payment extends Model
{
    public const METHODS = ['card', 'checkout', 'autopay', 'etransfer', 'bank', 'cash', 'cheque', 'other'];

    /** Methods the admin can record by hand (auto-pay charges are only ever made by billing:run). */
    public const MANUAL_METHODS = ['card', 'checkout', 'etransfer', 'bank', 'cash', 'cheque', 'other'];

    /** Methods a client can pay by outside the portal and then upload proof for. */
    public const OFFLINE_METHODS = ['etransfer', 'cheque', 'bank'];

    public const METHOD_LABELS = [
        'card' => 'card', 'checkout' => 'card', 'autopay' => 'card (auto-pay)', 'etransfer' => 'Interac e-Transfer', 'bank' => 'bank transfer',
        'cheque' => 'cheque', 'cash' => 'cash', 'other' => 'other',
    ];

    protected function casts(): array
    {
        return ['save_card' => 'boolean', 'paid_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'receipt_size' => 'integer'];
    }

    public function isInReview(): bool
    {
        return $this->status === 'review';
    }

    public function methodLabel(): string
    {
        return self::METHOD_LABELS[$this->method] ?? $this->method;
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class)->withPivot('amount_cents');
    }
}
