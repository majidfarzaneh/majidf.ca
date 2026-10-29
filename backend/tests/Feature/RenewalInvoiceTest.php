<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RenewalInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->admin()->create();
        $this->client = User::factory()->create();
    }

    private function service(array $overrides = []): Service
    {
        return Service::create($overrides + [
            'user_id' => $this->client->id, 'name' => 'VPS', 'type' => 'hosting', 'status' => 'active',
            'price_cents' => 2900, 'billing_cycle' => 'monthly', 'auto_invoice' => false,
            'renews_at' => '2026-11-15', 'renewal_reminded_at' => now(),
        ]);
    }

    public function test_admin_invoices_the_next_renewal_now(): void
    {
        $this->travelTo('2026-11-05');
        $service = $this->service();

        $this->actingAs($this->admin)->getJson("/api/admin/services/{$service->id}")
            ->assertJsonPath('data.renewal', ['from' => '2026-11-15', 'to' => '2026-12-14']);

        $res = $this->actingAs($this->admin)->postJson("/api/admin/services/{$service->id}/renewal-invoice")
            ->assertCreated()->assertJsonPath('invoice.status', 'unpaid')->assertJsonPath('autopay', null);

        $invoice = Invoice::with('items')->findOrFail($res->json('invoice.id'));
        $this->assertSame('VPS · Nov 2026', $invoice->description);
        $this->assertSame(2900, $invoice->subtotal_cents);
        $this->assertStringContainsString('(Nov 2026 – Dec 14, 2026)', $invoice->items->sole()->description);
        Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $m) => $m->kind === 'issued' && $m->hasTo($this->client->email));

        // Rolled forward a cycle, with a fresh renewal reminder for the next one.
        $service->refresh();
        $this->assertSame('2026-12-15', $service->renews_at->toDateString());
        $this->assertNull($service->renewal_reminded_at);
        $res->assertJsonPath('service.renewal.from', '2026-12-15');
    }

    public function test_admin_can_create_it_as_a_draft(): void
    {
        $service = $this->service();

        $id = $this->actingAs($this->admin)->postJson("/api/admin/services/{$service->id}/renewal-invoice", ['send' => false])
            ->assertCreated()->assertJsonPath('invoice.status', 'draft')->json('invoice.id');

        Mail::assertNothingQueued();
        $this->assertSame(InvoiceStatus::Draft, Invoice::findOrFail($id)->status);
        $this->assertSame('2026-12-15', $service->refresh()->renews_at->toDateString());
    }

    public function test_the_daily_job_does_not_bill_a_period_again(): void
    {
        $this->travelTo('2026-11-05');
        $service = $this->service(['auto_invoice' => true]);
        $this->actingAs($this->admin)->postJson("/api/admin/services/{$service->id}/renewal-invoice")->assertCreated();

        $this->travelTo('2026-11-15 08:00');
        $this->artisan('billing:run')->assertSuccessful();

        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_services_without_a_renewal_are_refused(): void
    {
        $oneTime = $this->service(['billing_cycle' => 'one_time']);
        $noDate = $this->service(['renews_at' => null]);
        $cancelled = $this->service(['status' => 'cancelled']);

        foreach ([$oneTime, $noDate, $cancelled] as $service) {
            $this->actingAs($this->admin)->postJson("/api/admin/services/{$service->id}/renewal-invoice")
                ->assertStatus(422)->assertJsonValidationErrors('renewal');
        }
        $this->assertSame(0, Invoice::query()->count());
        $this->actingAs($this->admin)->getJson("/api/admin/services/{$oneTime->id}")
            ->assertJsonPath('data.renewal.blocked', 'Only monthly, quarterly or yearly services have renewals.');
    }

    public function test_clients_cannot_invoice_renewals(): void
    {
        $service = $this->service();

        $this->actingAs($this->client)->postJson("/api/admin/services/{$service->id}/renewal-invoice")->assertForbidden();
    }
}
