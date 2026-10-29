<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\LoginCodes;
use App\Services\StripeGateway;
use App\Support\OfflineMethods;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private LoginCodes $codes) {}

    /**
     * Email a one-time code. Responds the same whether or not the email has an account, and the
     * limits count either way, so neither the response nor a 429 reveals which emails exist.
     */
    public function requestCode(Request $request): JsonResponse
    {
        $email = mb_strtolower($request->validate(['email' => ['required', 'email:rfc', 'max:255']])['email']);
        $resend = (int) config('business.login_code_resend_seconds');

        $this->throttle('email', [
            ['otp-send-gap:'.$email, 1, $resend],          // one code per email per resend window
            ['otp-send-email:'.$email, 5, 3600],           // stops inbox flooding from many IPs
            ['otp-send-ip:'.$request->ip(), 10, 3600],     // stops one IP walking through many emails
        ]);

        $user = User::query()->where('email', $email)->where('is_active', true)->first();
        if ($user) {
            $this->codes->send($user, $request->ip());
        }

        return response()->json([
            'message' => 'If that email has an account, a code is on its way.',
            'expires_in' => (int) config('business.login_code_ttl_minutes') * 60,
            'resend_in' => $resend,
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'code' => ['required', 'digits:6'],
        ]);
        $email = mb_strtolower($data['email']);

        $this->throttle('code', [
            ['otp-verify-email:'.$email, 10, 900],         // guesses against one account, from any IP
            ['otp-verify-ip:'.$request->ip(), 20, 600],    // guesses from one IP, across accounts
        ]);

        $user = User::query()->where('email', $email)->where('is_active', true)->first();
        if (! $user || ! $this->codes->verify($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired.']);
        }

        RateLimiter::clear('otp-verify-email:'.$email);

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $this->me($request);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
            'stripe' => [
                'enabled' => app(StripeGateway::class)->enabled(),
                'key' => config('services.stripe.key'),
            ],
            'business' => [
                'name' => config('business.name'),
                'owner_name' => config('business.owner_name'),
                'email' => config('business.email'),
                'currency' => config('business.currency'),
                'tax_label' => config('business.tax_label'),
                'tax_rate' => config('business.tax_rate'),
                'payment_terms_days' => config('business.payment_terms_days'),
            ],
            'offline_methods' => collect(OfflineMethods::enabled())->map(fn ($m, $key) => ['method' => $key] + $m)->values(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    /**
     * Check every [key, max, decaySeconds] limit before counting any of them, so a refused request
     * doesn't use up the others. Refusals are 429s with the wait on `$field` and in Retry-After.
     *
     * @param  list<array{string, int, int}>  $limits
     */
    private function throttle(string $field, array $limits): void
    {
        $wait = 0;
        foreach ($limits as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }

        if ($wait > 0) {
            $message = 'Too many attempts. Try again in '.($wait < 60 ? $wait.' s' : ceil($wait / 60).' min').'.';
            // Same shape as a validation error, so the login form shows it under the field.
            throw new HttpResponseException(response()->json(
                ['message' => $message, 'errors' => [$field => [$message]], 'retry_after' => $wait],
                429,
                ['Retry-After' => $wait],
            ));
        }

        foreach ($limits as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }
}
