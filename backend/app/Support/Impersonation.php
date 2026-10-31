<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * "Log in as client": an admin swaps their session to a client's, keeping their own id in the
 * session so they can switch back. Nothing is stored server-side beyond the session itself.
 */
class Impersonation
{
    private const KEY = 'impersonator_id';

    public static function start(User $admin, User $client): void
    {
        Auth::guard('web')->login($client); // no remember: the admin's own remember cookie stays
        request()->session()->regenerate();
        request()->session()->put(self::KEY, $admin->id);
    }

    /** Switch back to the admin. Returns them, or null when the session wasn't impersonating. */
    public static function stop(): ?User
    {
        $admin = self::impersonator();
        request()->session()->forget(self::KEY);
        if (! $admin) {
            return null;
        }

        Auth::guard('web')->login($admin);
        request()->session()->regenerate();

        return $admin;
    }

    /** The admin behind the current session, if it's an impersonated one. */
    public static function impersonator(): ?User
    {
        if (! request()->hasSession() || ! ($id = request()->session()->get(self::KEY))) {
            return null;
        }
        $admin = User::query()->whereKey($id)->where('is_active', true)->first();

        return $admin?->isAdmin() ? $admin : null;
    }
}
