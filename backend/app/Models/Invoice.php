<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'number', 'status', 'description', 'issued_at', 'due_at', 'grace_days', 'currency',
    'subtotal_cents', 'tax_rate', 'tax_label', 'tax_cents', 'total_cents', 'notes', 'sent_at', 'paid_at',
])]
class Invoice extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issued_at' => 'date',
            'due_at' => 'date',
            'pay_until' => 'date',
            'grace_days' => 'integer',
            'tax_rate' => 'float',
            'reminders' => 'array',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    /** Reminder kinds sent automatically, in order. */
    public const REMINDERS = ['due_soon', 'due_today', 'overdue', 'closed'];

    protected static function booted(): void
    {
        static::saving(function (Invoice $invoice) {
            $invoice->grace_days ??= (int) config('business.grace_days');
            $invoice->pay_until = $invoice->due_at?->copy()->addDays($invoice->grace_days);

            // A new due date or grace period restarts the reminder schedule.
            if ($invoice->exists && $invoice->isDirty(['due_at', 'grace_days'])) {
                $invoice->reminders = null;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort');
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class)->withPivot('amount_cents');
    }

    /** Invoices the client can see and pay (not drafts or voided). */
    public function scopeVisible(Builder $query): void
    {
        $query->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Paid]);
    }

    /** Issued and not yet paid: money that is still owed, payable online or not. */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', InvoiceStatus::Unpaid);
    }

    public function isOpen(): bool
    {
        return $this->status === InvoiceStatus::Unpaid;
    }

    /** Can the client still pay it online? Open, not awaiting review of offline proof, and within due date + grace days. */
    public function isPayable(): bool
    {
        return $this->isOpen() && ! $this->isInReview() && ($this->pay_until === null || $this->pay_until->gte(today()));
    }

    /** The client uploaded proof of an offline payment that the admin hasn't reviewed yet. */
    public function isInReview(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        return $this->relationLoaded('payments')
            ? $this->payments->contains('status', 'review')
            : $this->payments()->where('status', 'review')->exists();
    }

    /** Can the client submit proof of an offline payment? Open (expired too) and nothing already in review. */
    public function acceptsOfflinePayment(): bool
    {
        return $this->isOpen() && ! $this->isInReview();
    }

    /** Past the due date (whether or not the grace period has ended). */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->lt(today());
    }

    /** Past the grace period: still owed, but no longer payable online. */
    public function isExpired(): bool
    {
        return $this->isOpen() && $this->pay_until !== null && $this->pay_until->lt(today());
    }

    /** The status shown to people: Draft, Unpaid, In review, Overdue, Expired, Paid or Void. */
    public function displayStatus(): string
    {
        return match (true) {
            $this->isInReview() => 'In review',
            $this->isExpired() => 'Expired',
            $this->isOverdue() => 'Overdue',
            default => ucfirst($this->status->value),
        };
    }

    public function reminderSent(string $kind): bool
    {
        return isset(($this->reminders ?? [])[$kind]);
    }

    public function markReminderSent(string $kind): void
    {
        $this->forceFill(['reminders' => array_merge($this->reminders ?? [], [$kind => now()->toIso8601String()])])->saveQuietly();
    }

    /** Recompute subtotal, tax and total from line items. */
    public function recalculate(): void
    {
        $this->loadMissing('items');
        $this->subtotal_cents = (int) $this->items->sum('amount_cents');
        $this->tax_cents = (int) round($this->subtotal_cents * $this->tax_rate / 100);
        $this->total_cents = $this->subtotal_cents + $this->tax_cents;
    }

    public static function nextNumber(): string
    {
        $prefix = config('business.invoice_prefix');
        $start = (int) config('business.invoice_start');
        $last = static::query()->where('number', 'like', $prefix.'%')->pluck('number')
            ->map(fn ($n) => (int) substr($n, strlen($prefix)))->max();

        return $prefix.max($start, ($last ?? $start - 1) + 1);
    }
}
