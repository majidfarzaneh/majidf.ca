<?php

namespace App\Services;

use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class LoginCodes
{
    public function send(User $user, ?string $ip = null): void
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        $user->loginCodes()->whereNull('used_at')->delete();
        $user->loginCodes()->create([
            'code_hash' => $this->hash($code),
            'expires_at' => now()->addMinutes(config('business.login_code_ttl_minutes')),
            'ip_address' => $ip,
        ]);

        Mail::to($user)->send(new LoginCodeMail($user, $code));
    }

    /** Check a code, consuming it on success. Wrong guesses count against the code. */
    public function verify(User $user, string $code): bool
    {
        $login = $user->loginCodes()->whereNull('used_at')->where('expires_at', '>', now())->latest('id')->first();

        if (! $login || $login->attempts >= config('business.login_code_max_attempts')) {
            return false;
        }

        if (! hash_equals($login->code_hash, $this->hash($code))) {
            $login->increment('attempts');

            return false;
        }

        $login->update(['used_at' => now()]);

        return true;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, config('app.key'));
    }
}
