<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\LoginCodes;
use App\Services\StripeGateway;
use App\Support\OfflineMethods;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private LoginCodes $codes) {}

    /** Email a one-time code. Responds the same whether or not the email has an account. */
    public function requestCode(Request $request): JsonResponse
    {
        $email = mb_strtolower($request->validate(['email' => ['required', 'email:rfc', 'max:255']])['email']);

        $this->throttle('login-code:'.$email.'|'.$request->ip(), 5, 600);

        $user = User::query()->where('email', $email)->where('is_active', true)->first();
        if ($user) {
            $this->codes->send($user, $request->ip());
        }

        return response()->json(['message' => 'If that email has an account, a code is on its way.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'code' => ['required', 'digits:6'],
        ]);
        $email = mb_strtolower($data['email']);

        $this->throttle('login-verify:'.$request->ip(), 20, 600);

        $user = User::query()->where('email', $email)->where('is_active', true)->first();
        if (! $user || ! $this->codes->verify($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired.']);
        }

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

    private function throttle(string $key, int $max, int $decay): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' min.',
            ])->status(429);
        }
        RateLimiter::hit($key, $decay);
    }
}
