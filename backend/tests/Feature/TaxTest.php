<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\RenewalInvoicer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TaxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['business.tax_rate' => 13, 'business.tax_label' => 'HST']);
        $this->admin = User::factory()->admin()->create();
    }

    private function renewalInvoice(User $client, array $service = []): Invoice
    {
        $service = Service::create($service + [
            'user_id' => $client->id, 'name' => 'VPS', 'type' => 'hosting', 'status' => 'active',
            'price_cents' => 10000, 'billing_cycle' => 'monthly', 'renews_at' => today(),
        ]);

        return app(RenewalInvoicer::class)->issue($service, send: false)['invoice'];
    }

    public function test_renewals_use_the_business_default(): void
    {
        $invoice = $this->renewalInvoice(User::factory()->create());

        $this->assertSame([13.0, 'HST', 1300, 11300], [$invoice->tax_rate, $invoice->tax_label, $invoice->tax_cents, $invoice->total_cents]);
    }

    public function test_an_exempt_client_pays_no_tax(): void
    {
        $invoice = $this->renewalInvoice(User::factory()->create(['tax_rate' => 0]));

        $this->assertSame([0.0, 0, 10000], [$invoice->tax_rate, $invoice->tax_cents, $invoice->total_cents]);
    }

    public function test_a_client_rate_and_label_apply(): void
    {
        $invoice = $this->renewalInvoice(User::factory()->create(['tax_rate' => 5, 'tax_label' => 'GST']));

        $this->assertSame([5.0, 'GST', 500], [$invoice->tax_rate, $invoice->tax_label, $invoice->tax_cents]);
    }

    public function test_a_service_override_beats_the_client(): void
    {
        $client = User::factory()->create(['tax_rate' => 0]);

        $taxed = $this->renewalInvoice($client, ['tax_rate' => 13, 'tax_label' => 'HST']);
        $exempt = $this->renewalInvoice(User::factory()->create(), ['tax_rate' => 0]);

        $this->assertSame(1300, $taxed->tax_cents);
        $this->assertSame(0, $exempt->tax_cents);
    }

    public function test_admin_sets_a_clients_tax_and_can_go_back_to_the_default(): void
    {
        $client = User::factory()->create();
        $base = ['name' => $client->name, 'email' => $client->email];

        $this->actingAs($this->admin)->putJson("/api/admin/clients/{$client->id}", $base + ['tax_rate' => 0])
            ->assertOk()->assertJsonPath('data.tax_rate', 0)->assertJsonPath('data.tax.source', 'client');

        $this->actingAs($this->admin)->putJson("/api/admin/clients/{$client->id}", $base + ['tax_rate' => null, 'tax_label' => 'GST'])
            ->assertOk()->assertJsonPath('data.tax_rate', null)->assertJsonPath('data.tax_label', null)
            ->assertJsonPath('data.tax', ['rate' => 13, 'label' => 'HST', 'source' => 'default']);

        $this->actingAs($this->admin)->putJson("/api/admin/clients/{$client->id}", $base + ['tax_rate' => 75])
            ->assertStatus(422)->assertJsonValidationErrors('tax_rate');
    }

    public function test_admin_sets_a_services_tax(): void
    {
        $client = User::factory()->create(['tax_rate' => 5, 'tax_label' => 'GST']);
        $service = Service::create([
            'user_id' => $client->id, 'name' => 'VPS', 'type' => 'hosting', 'status' => 'active',
            'price_cents' => 10000, 'billing_cycle' => 'monthly', 'renews_at' => today(),
        ]);

        $this->actingAs($this->admin)->getJson("/api/admin/services/{$service->id}")
            ->assertJsonPath('data.tax', ['rate' => 5, 'label' => 'GST', 'source' => 'client']);

        $this->actingAs($this->admin)->putJson("/api/admin/services/{$service->id}", [
            'name' => 'VPS', 'type' => 'hosting', 'status' => 'active', 'tax_rate' => 0,
        ])->assertOk()->assertJsonPath('data.tax.rate', 0)->assertJsonPath('data.tax.source', 'service');
    }
}
