<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'name', 'type', 'status', 'detail', 'meta', 'price_cents', 'billing_cycle', 'auto_invoice', 'invoice_lead_days',
    'progress', 'progress_label', 'hours_total', 'hours_used', 'started_at', 'renews_at', 'monitor_url', 'sort',
    'tax_rate', 'tax_label',
])]
class Service extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => ServiceType::class,
            'status' => ServiceStatus::class,
            'billing_cycle' => BillingCycle::class,
            'auto_invoice' => 'boolean',
            'tax_rate' => 'float',
            'progress' => 'float',
            'hours_total' => 'float',
            'hours_used' => 'float',
            'started_at' => 'date',
            'renews_at' => 'date',
            'renewal_reminded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function uptimeChecks(): HasMany
    {
        return $this->hasMany(UptimeCheck::class);
    }

    /** Days before the renewal date its invoice is issued: its own setting, else the business default. */
    public function invoiceLeadDays(): int
    {
        return $this->invoice_lead_days ?? (int) config('business.renewal_invoice_lead_days');
    }

    /**
     * When the daily job issues the next renewal invoice, or null without a recurring renewal. Never a
     * full cycle early, so one run can't bill a period that hasn't started yet twice.
     */
    public function renewalInvoiceDate(): ?CarbonInterface
    {
        if ($this->renews_at === null || ! $this->billing_cycle?->isRecurring()) {
            return null;
        }
        $cycleDays = (int) $this->renews_at->diffInDays($this->billing_cycle->advance($this->renews_at));

        return $this->renews_at->copy()->subDays(min($this->invoiceLeadDays(), $cycleDays - 1));
    }

    public function priceLabel(): string
    {
        if ($this->billing_cycle === BillingCycle::Included) {
            return 'Included';
        }
        if ($this->price_cents === null) {
            return '—';
        }

        return Money::format($this->price_cents).($this->billing_cycle?->suffix() ?? '');
    }

    /** Progress as a percentage, from explicit progress or hours used. */
    public function progressPercent(): ?float
    {
        if ($this->progress !== null) {
            return $this->progress;
        }
        if ($this->hours_total) {
            return round(min(100, ($this->hours_used ?? 0) / $this->hours_total * 100), 1);
        }

        return null;
    }
}
