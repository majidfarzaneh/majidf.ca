<?php

namespace Tests\Feature;

use App\Mail\LoginCodeMail;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withHeader('Origin', 'http://localhost'); // stateful SPA requests (Sanctum)
        $this->admin = User::factory()->admin()->create(['name' => 'Majid']);
        $this->client = User::factory()->create(['company' => 'Olive & Fig']);
    }

    private function latest(): AuditLog
    {
        return AuditLog::query()->latest('id')->firstOrFail();
    }

    public function test_client_sign_in_is_logged_with_ip_and_failures_too(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->postJson('/api/auth/code', ['email' => $this->client->email])->assertOk();
        $this->assertSame(['auth.code_requested', 'client', $this->client->id], [$this->latest()->event, $this->latest()->actor_type, $this->latest()->client_id]);

        $code = Mail::sent(LoginCodeMail::class)->first()->code;
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/api/auth/verify', ['email' => $this->client->email, 'code' => $wrong])->assertStatus(422);
        $this->assertSame('auth.failed', $this->latest()->event);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/api/auth/verify', ['email' => $this->client->email, 'code' => $code])->assertOk();
        $login = $this->latest();
        $this->assertSame(['auth.login', 'Signed in', 'client', 'Olive & Fig', '203.0.113.7'], [$login->event, $login->text, $login->actor_type, $login->actor_name, $login->ip]);
    }

    public function test_admin_actions_are_logged_against_the_client(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/invoices', [
            'user_id' => $this->client->id, 'description' => 'Work', 'tax_rate' => 13, 'send' => true,
            'items' => [['description' => 'Build', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated();

        $events = AuditLog::query()->orderBy('id')->get();
        $this->assertSame(['invoice.created', 'invoice.sent'], $events->pluck('event')->all());
        $this->assertSame(['admin', 'Majid', $this->client->id], [$events[0]->actor_type, $events[0]->actor_name, $events[0]->client_id]);
        $this->assertStringContainsString('(emailed)', $events[1]->text);
    }

    public function test_the_scheduler_logs_as_the_system(): void
    {
        $this->travelTo('2026-10-15 08:00');
        Invoice::create([
            'user_id' => $this->client->id, 'number' => 'INV-9', 'description' => 'Work', 'status' => 'unpaid',
            'subtotal_cents' => 1000, 'tax_cents' => 0, 'total_cents' => 1000, 'issued_at' => '2026-10-01', 'due_at' => '2026-10-15', 'grace_days' => 3,
        ]);

        $this->artisan('billing:run')->assertSuccessful();

        $log = $this->latest();
        $this->assertSame(['invoice.reminder.due_today', 'system', null, $this->client->id], [$log->event, $log->actor_type, $log->ip, $log->client_id]);
        $this->assertAdminNotified('INV-9 is due today');
    }

    public function test_client_ticket_rings_the_admin_bell(): void
    {
        $this->actingAs($this->client)->postJson('/api/portal/tickets', ['subject' => 'Site down', 'body' => 'Help'])->assertCreated();

        $this->assertSame('ticket.opened', $this->latest()->event);

        $bell = $this->actingAs($this->admin)->getJson('/api/admin/notifications')->assertOk()
            ->assertJsonPath('unread', 1)->assertJsonPath('data.0.kind', 'ticket')->assertJsonPath('data.0.read', false);
        $this->assertStringContainsString('Site down', $bell->json('data.0.title'));
        $this->assertStringStartsWith('/tickets/', $bell->json('data.0.path'));

        $this->actingAs($this->admin)->postJson('/api/admin/notifications/'.$bell->json('data.0.id').'/read')->assertOk()->assertJsonPath('unread', 0);
    }

    public function test_project_requests_and_replies_notify_and_mark_all_read_clears_the_bell(): void
    {
        $this->actingAs($this->client)->postJson('/api/portal/project-requests', ['types' => ['web'], 'budget' => array_key_first(\App\Models\ProjectRequest::BUDGETS)])->assertCreated();
        $ticket = $this->actingAs($this->client)->postJson('/api/portal/tickets', ['subject' => 'Q', 'body' => 'Hi'])->json('data.number');
        $this->actingAs($this->client)->postJson("/api/portal/tickets/{$ticket}/messages", ['body' => 'Any news?'])->assertOk();

        $this->assertAdminNotified('New project request from Olive & Fig');
        $this->assertAdminNotified('Reply on');
        $this->actingAs($this->admin)->getJson('/api/admin/notifications')->assertJsonPath('unread', 3);

        $this->actingAs($this->admin)->postJson('/api/admin/notifications/read-all')->assertOk();
        $this->actingAs($this->admin)->getJson('/api/admin/notifications')->assertJsonPath('unread', 0);
    }

    public function test_the_log_filters_by_client_actor_and_category(): void
    {
        $other = User::factory()->create();
        $this->actingAs($this->admin)->postJson('/api/admin/clients/'.$this->client->id.'/invite')->assertOk();
        $this->actingAs($this->admin)->postJson('/api/admin/clients/'.$other->id.'/invite')->assertOk();
        $this->actingAs($this->client)->postJson('/api/portal/tickets', ['subject' => 'Q', 'body' => 'Hi'])->assertCreated();

        $this->actingAs($this->admin)->getJson('/api/admin/activity-log?client_id='.$this->client->id)
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.event', 'ticket.opened')
            ->assertJsonPath('data.0.path', '/tickets/'.\App\Models\Ticket::query()->value('id'));
        $this->actingAs($this->admin)->getJson('/api/admin/activity-log?actor=admin')->assertJsonCount(2, 'data');
        $this->actingAs($this->admin)->getJson('/api/admin/activity-log?category=ticket')->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson('/api/admin/activity-log?search=Olive')->assertJsonCount(2, 'data');
    }

    public function test_clients_cannot_read_the_log_or_the_bell(): void
    {
        $this->actingAs($this->client)->getJson('/api/admin/activity-log')->assertForbidden();
        $this->actingAs($this->client)->getJson('/api/admin/notifications')->assertForbidden();
    }

    public function test_deleting_a_client_keeps_their_history_readable(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/clients/'.$this->client->id.'/invite')->assertOk();
        $this->actingAs($this->admin)->deleteJson('/api/admin/clients/'.$this->client->id)->assertOk();

        $invite = AuditLog::query()->where('event', 'client.invited')->sole();
        $this->assertSame([null, 'Olive & Fig'], [$invite->client_id, $invite->client_name]);
        $this->assertSame('client.deleted', $this->latest()->event);
    }
}
