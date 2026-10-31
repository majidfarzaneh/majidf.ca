<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost');
    }

    public function test_admin_signs_in_as_a_client_and_back(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Majid']);
        $client = User::factory()->create(['company' => 'Acme']);

        $this->actingAs($admin, 'web')->postJson("/api/admin/clients/{$client->id}/impersonate")->assertOk();
        $this->assertAuthenticatedAs($client, 'web');
        $this->nextRequest();

        $this->getJson('/api/me')->assertOk()
            ->assertJsonPath('user.id', $client->id)
            ->assertJsonPath('impersonator.name', 'Majid');
        $this->getJson('/api/admin/dashboard')->assertForbidden();
        $this->nextRequest();

        // What the admin does as the client is logged as the client, noting who was really there.
        $this->postJson('/api/portal/tickets', ['subject' => 'Site down', 'body' => 'Help'])->assertCreated();
        $this->assertSame('Majid', AuditLog::query()->where('event', 'like', 'ticket.%')->latest('id')->first()->meta['impersonated_by']);
        $this->nextRequest();

        $this->postJson('/api/auth/stop-impersonating')->assertOk()
            ->assertJsonPath('user.id', $admin->id)
            ->assertJsonPath('impersonator', null);
        $this->assertAuthenticatedAs($admin, 'web');
        $this->nextRequest();
        $this->getJson('/api/admin/dashboard')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.impersonated', 'actor_id' => $admin->id, 'client_id' => $client->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.impersonate_stopped', 'actor_id' => $admin->id, 'client_id' => $client->id]);
    }

    public function test_cannot_impersonate_admins_or_inactive_clients(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $inactive = User::factory()->create(['is_active' => false]);

        $this->actingAs($admin, 'web')->postJson("/api/admin/clients/{$other->id}/impersonate")->assertNotFound();
        $this->postJson("/api/admin/clients/{$inactive->id}/impersonate")->assertStatus(422);
        $this->assertAuthenticatedAs($admin, 'web');
    }

    public function test_clients_cannot_impersonate_or_stop_without_an_impersonator(): void
    {
        $client = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($client, 'web')->postJson("/api/admin/clients/{$other->id}/impersonate")->assertForbidden();
        $this->postJson('/api/auth/stop-impersonating')->assertForbidden();
        $this->assertAuthenticatedAs($client, 'web');
    }

    /** Like a browser's next request: same session cookie, nobody resolved yet. */
    private function nextRequest(): void
    {
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->app['auth']->forgetGuards();
    }
}
