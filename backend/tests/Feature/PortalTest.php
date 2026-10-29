<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);
        $this->sara = User::query()->where('email', 'sara@oliveandfig.ca')->firstOrFail();
    }

    public function test_dashboard_summarises_the_account(): void
    {
        $this->actingAs($this->sara)->getJson('/api/portal/dashboard')
            ->assertOk()
            ->assertJsonPath('kpis.outstanding_cents', 96000 + 24000)
            ->assertJsonPath('kpis.due_count', 2)
            ->assertJsonPath('kpis.in_development', 1)
            ->assertJsonPath('kpis.open_tickets', 2)
            ->assertJsonCount(30, 'uptime.days')
            ->assertJsonStructure(['kpis' => ['next_renewal' => ['date', 'name', 'price_label']], 'progress', 'activity']);
    }

    public function test_client_opens_and_replies_to_ticket(): void
    {
        $number = $this->actingAs($this->sara)->postJson('/api/portal/tickets', ['subject' => 'Broken link', 'body' => 'The footer link 404s.'])
            ->assertCreated()->json('data.number');

        $this->postJson("/api/portal/tickets/{$number}/messages", ['body' => 'Also on mobile.'])
            ->assertOk()->assertJsonCount(2, 'data.messages');

        $other = User::factory()->create();
        $this->actingAs($other)->getJson("/api/portal/tickets/{$number}")->assertNotFound();
    }

    public function test_client_sends_project_request(): void
    {
        $this->actingAs($this->sara)->postJson('/api/portal/project-requests', [
            'types' => ['web', 'shop'], 'budget' => 'b2', 'description' => 'A new store.',
        ])->assertCreated()->assertJsonPath('data.type_labels', ['Website', 'Online store']);

        $this->postJson('/api/portal/project-requests', ['types' => [], 'budget' => 'b2'])->assertStatus(422);
    }

    public function test_client_updates_profile_and_notifications(): void
    {
        $this->actingAs($this->sara)->putJson('/api/portal/profile', [
            'name' => 'Sara A.', 'company' => 'Olive & Fig', 'email' => 'sara@oliveandfig.ca', 'phone' => '',
        ])->assertOk()->assertJsonPath('data.name', 'Sara A.');

        $this->putJson('/api/portal/profile/notifications', ['news' => true])
            ->assertOk()->assertJsonPath('data.prefs.news', true)->assertJsonPath('data.prefs.inv', true);
    }

    public function test_admin_client_overview(): void
    {
        $admin = User::query()->where('role', 'admin')->firstOrFail();

        $this->actingAs($admin)->getJson('/api/admin/clients')->assertOk()
            ->assertJsonPath('data.0.outstanding_cents', 120000);
        $this->getJson("/api/admin/clients/{$this->sara->id}")->assertOk()->assertJsonCount(7, 'services');
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('kpis.unpaid_count', 2);
    }
}
