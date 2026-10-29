<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\AdminNotice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Something the admin should act on or know about: shows in the admin's notification bell.
 * `kind` picks the icon (payment, invoice, ticket, request, autopay, client); `path` is the admin page to open.
 */
class AdminAlert
{
    /** @param  array<string, string|null>  $lines */
    public static function send(string $kind, string $title, array $lines = [], ?string $path = null): void
    {
        try {
            Notification::send(
                User::query()->where('role', Role::Admin)->where('is_active', true)->get(),
                new AdminNotice($kind, $title, array_filter($lines, fn ($v) => $v !== null && $v !== ''), $path),
            );
        } catch (Throwable $e) {
            Log::error('Admin notification failed', ['title' => $title, 'error' => $e->getMessage()]);
        }
    }
}
