<?php

namespace Tests\Feature;

use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The SPA is a first-party frontend; Sanctum only starts a session for stateful origins.
        $this->withHeader('Origin', 'http://localhost');
    }

    public function test_client_signs_in_with_emailed_code(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'sara@example.com']);

        $this->postJson('/api/auth/code', ['email' => 'SARA@example.com'])->assertOk();

        $code = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code, $user) {
            $code = $mail->code;

            return $mail->hasTo($user->email);
        });

        $this->postJson('/api/auth/verify', ['email' => 'sara@example.com', 'code' => $code])
            ->assertOk()->assertJsonPath('user.email', 'sara@example.com');
        $this->assertAuthenticatedAs($user);

        // A code works only once.
        auth()->guard('web')->logout();
        $this->postJson('/api/auth/verify', ['email' => 'sara@example.com', 'code' => $code])->assertStatus(422);
    }

    public function test_unknown_email_gets_same_response_and_no_mail(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/code', ['email' => 'nobody@example.com'])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_wrong_code_is_rejected_and_locks_after_max_attempts(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->postJson('/api/auth/code', ['email' => $user->email]);
        $code = Mail::sent(LoginCodeMail::class)->first()->code;
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/verify', ['email' => $user->email, 'code' => $wrong])->assertStatus(422);
        }

        $this->postJson('/api/auth/verify', ['email' => $user->email, 'code' => $code])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_inactive_client_cannot_sign_in(): void
    {
        Mail::fake();
        $user = User::factory()->create(['is_active' => false]);

        $this->postJson('/api/auth/code', ['email' => $user->email])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_clients_cannot_reach_admin_api(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/admin/dashboard')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/dashboard')->assertOk();
    }

    public function test_guests_cannot_reach_portal(): void
    {
        $this->getJson('/api/portal/dashboard')->assertUnauthorized();
    }
}
