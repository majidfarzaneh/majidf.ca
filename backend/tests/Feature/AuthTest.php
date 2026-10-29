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

    public function test_code_expires_after_two_minutes(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->postJson('/api/auth/code', ['email' => $user->email])
            ->assertOk()->assertJson(['expires_in' => 120, 'resend_in' => 60]);
        $code = Mail::sent(LoginCodeMail::class)->first()->code;

        $this->travel(121)->seconds();

        $this->postJson('/api/auth/verify', ['email' => $user->email, 'code' => $code])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_another_code_waits_for_the_resend_window(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->postJson('/api/auth/code', ['email' => $user->email])->assertOk();
        $this->postJson('/api/auth/code', ['email' => $user->email])
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonValidationErrors('email');
        Mail::assertSentCount(1);

        $this->travel(61)->seconds();

        $this->postJson('/api/auth/code', ['email' => $user->email])->assertOk();
        Mail::assertSentCount(2);
    }

    public function test_codes_for_one_email_are_capped_across_ips(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.$i"])
                ->postJson('/api/auth/code', ['email' => $user->email])->assertOk();
            $this->travel(61)->seconds();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->postJson('/api/auth/code', ['email' => $user->email])->assertStatus(429);
        Mail::assertSentCount(5);
    }

    public function test_one_ip_cannot_request_codes_for_many_emails(): void
    {
        Mail::fake();

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/auth/code', ['email' => "someone$i@example.com"])->assertOk();
        }

        // Unknown emails count too, so the limit doesn't reveal which accounts exist.
        $this->postJson('/api/auth/code', ['email' => 'someone11@example.com'])->assertStatus(429);
    }

    public function test_guesses_against_one_email_are_capped_across_ips(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.$i"])
                ->postJson('/api/auth/verify', ['email' => $user->email, 'code' => '123456'])->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->postJson('/api/auth/verify', ['email' => $user->email, 'code' => '123456'])
            ->assertStatus(429)->assertJsonValidationErrors('code');
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
