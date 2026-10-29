<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'role', 'company', 'phone', 'address', 'notification_prefs', 'admin_notes', 'is_active', 'tax_rate', 'tax_label'])]
#[Hidden(['password', 'remember_token', 'admin_notes', 'stripe_customer_id', 'stripe_payment_method_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const DEFAULT_PREFS = ['inv' => true, 'svc' => true, 'tick' => true, 'renew' => true, 'news' => false];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'notification_prefs' => 'array',
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'autopay' => 'boolean',
            'tax_rate' => 'float',
            'autopay_consented_at' => 'datetime',
            'card_exp_month' => 'integer',
            'card_exp_year' => 'integer',
        ];
    }

    /** A saved card that auto-pay can charge (the card itself lives in Stripe). */
    public function hasSavedCard(): bool
    {
        return filled($this->stripe_payment_method_id);
    }

    /** Charge the saved card for auto-invoiced renewals. */
    public function autopayEnabled(): bool
    {
        return $this->autopay && $this->hasSavedCard();
    }

    /** What the portal and admin show about the saved card. */
    public function savedCard(): ?array
    {
        if (! $this->hasSavedCard()) {
            return null;
        }

        return [
            'brand' => $this->card_brand,
            'last4' => $this->card_last4,
            'exp_month' => $this->card_exp_month,
            'exp_year' => $this->card_exp_year,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /** @return array<string, bool> */
    public function prefs(): array
    {
        return array_merge(self::DEFAULT_PREFS, $this->notification_prefs ?? []);
    }

    public function wants(string $pref): bool
    {
        return (bool) ($this->prefs()[$pref] ?? false);
    }

    public function initials(): string
    {
        return collect(preg_split('/\s+/', trim($this->name)))
            ->filter()->take(2)
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function projectRequests(): HasMany
    {
        return $this->hasMany(ProjectRequest::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function loginCodes(): HasMany
    {
        return $this->hasMany(LoginCode::class);
    }
}
