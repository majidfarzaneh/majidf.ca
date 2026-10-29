<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['user_id', 'kind', 'text', 'subject_type', 'subject_id', 'created_at'])]
class Activity extends Model
{
    /** kind => [icon, color] as used by the portal timeline. */
    public const KINDS = [
        'invoice' => ['fa-solid fa-file-invoice', '#fac364'],
        'payment' => ['fa-solid fa-circle-check', '#b6d957'],
        'milestone' => ['fa-solid fa-flag-checkered', '#5699f6'],
        'ticket' => ['fa-solid fa-life-ring', '#b6d957'],
        'service' => ['fa-solid fa-layer-group', '#5699f6'],
        'renewal' => ['fa-solid fa-lock', '#71717a'],
        'request' => ['fa-solid fa-paper-plane', '#d998cb'],
        'note' => ['fa-solid fa-circle-info', '#a1a1aa'],
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public static function log(User|int $user, string $kind, string $text, ?Model $subject = null): self
    {
        return static::create([
            'user_id' => $user instanceof User ? $user->id : $user,
            'kind' => $kind,
            'text' => $text,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ]);
    }
}
