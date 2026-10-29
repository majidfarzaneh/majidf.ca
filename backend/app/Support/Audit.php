<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The admin activity log. Who did it (the signed-in client or admin, or the system for the
 * scheduler, queue and Stripe webhooks), which client it concerns, and a sentence an admin reads.
 */
class Audit
{
    /**
     * @param  string  $event  dotted key, e.g. "invoice.sent", used for filtering
     * @param  User|null  $client  the client it concerns; defaults to the actor when that's a client
     * @param  User|null  $actor  who did it, when not the signed-in user (e.g. at sign-in)
     */
    public static function log(string $event, string $text, ?Model $subject = null, ?User $client = null, array $meta = [], ?User $actor = null): void
    {
        try {
            // The scheduler, queue and Stripe webhooks have no signed-in user: that's the system.
            $actor ??= Auth::user(); // the guard this request authenticated with (sanctum in the API)
            $client ??= $actor && ! $actor->isAdmin() ? $actor : null;

            AuditLog::create([
                'actor_id' => $actor?->id,
                'actor_type' => $actor ? ($actor->isAdmin() ? 'admin' : 'client') : 'system',
                'actor_name' => $actor ? ($actor->isAdmin() ? $actor->name : ($actor->company ?: $actor->name)) : null,
                'client_id' => $client?->id,
                'client_name' => $client ? ($client->company ?: $client->name) : null,
                'event' => $event,
                'text' => mb_substr($text, 0, 500),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'ip' => $actor ? request()->ip() : null,
                'meta' => $meta ?: null,
            ]);
        } catch (Throwable $e) {
            // The log must never break the action it records.
            Log::error('Audit log write failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
