<?php

namespace Tests\Feature;

use App\Mail\ServiceAddedMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ServiceAddedTest extends TestCase
{
    use RefreshDatabase;

    private function addService(User $client, array $overrides = [])
    {
        return $this->actingAs(User::factory()->admin()->create())->postJson('/api/admin/services', $overrides + [
            'user_id' => $client->id,
            'name' => 'oliveandfig.ca hosting',
            'type' => 'hosting',
            'status' => 'active',
            'price' => 29,
            'billing_cycle' => 'monthly',
            'renews_at' => today()->addMonth()->toDateString(),
            'auto_invoice' => true,
        ]);
    }

    public function test_client_is_emailed_when_a_service_is_added(): void
    {
        Mail::fake();
        $client = User::factory()->create();

        $this->addService($client)->assertCreated();

        Mail::assertQueued(ServiceAddedMail::class, function (ServiceAddedMail $mail) use ($client) {
            $html = $mail->render();

            return $mail->hasTo($client->email)
                && str_contains($html, 'oliveandfig.ca hosting')
                && str_contains($html, '/ mo')
                && str_contains($html, 'issued automatically');
        });
    }

    public function test_admin_can_add_a_service_without_emailing(): void
    {
        Mail::fake();

        $this->addService(User::factory()->create(), ['notify' => false])->assertCreated();

        Mail::assertNothingQueued();
    }

    public function test_respects_the_clients_new_services_preference(): void
    {
        Mail::fake();
        $client = User::factory()->create(['notification_prefs' => ['svc' => false]]);

        $this->addService($client)->assertCreated();

        Mail::assertNothingQueued();
    }

    public function test_cancelled_services_send_nothing(): void
    {
        Mail::fake();

        $this->addService(User::factory()->create(), ['status' => 'cancelled', 'auto_invoice' => false])->assertCreated();

        Mail::assertNothingQueued();
    }

    public function test_client_can_turn_new_service_emails_off(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->putJson('/api/portal/profile/notifications', ['svc' => false])->assertOk();

        $this->assertFalse($client->fresh()->wants('svc'));
    }
}
