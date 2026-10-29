<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Mail\AdminAlertMail;
use App\Mail\InvoiceMail;
use App\Mail\PaymentReceiptMail;
use App\Mail\PaymentRejectedMail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\InvoiceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OfflinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        config(['business.offline.bank_details' => "Bank: RBC\nTransit: 01234\nAccount: 1234567"]);

        $this->admin = User::factory()->admin()->create();
        $this->client = User::factory()->create();
        $manager = app(InvoiceManager::class);
        $this->invoice = $manager->send($manager->save(new Invoice, [
            'user_id' => $this->client->id, 'description' => 'Care plan', 'tax_rate' => 0, 'due_at' => today()->addDays(10)->toDateString(),
        ], [['description' => 'Care plan', 'quantity' => 1, 'unit_price' => 240]]));
    }

    private function submit(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->client)->post('/api/portal/payments/offline', $overrides + [
            'invoice_ids' => [$this->invoice->id],
            'method' => 'etransfer',
            'reference' => 'CA9xYz',
            'note' => 'Sent from my business account',
            'receipt' => UploadedFile::fake()->image('etransfer.png'),
        ], ['Accept' => 'application/json']);
    }

    public function test_offline_methods_and_instructions_are_exposed(): void
    {
        $this->actingAs($this->client)->getJson('/api/me')->assertOk()
            ->assertJsonPath('offline_methods.0.method', 'etransfer')
            ->assertJsonPath('offline_methods.1.method', 'bank')
            ->assertJsonPath('offline_methods.1.lines.1', ['Transit', '01234'])
            ->assertJsonPath('offline_methods.2.method', 'cheque');
    }

    public function test_bank_transfer_is_hidden_without_bank_details(): void
    {
        config(['business.offline.bank_details' => null]);

        $this->actingAs($this->client)->getJson('/api/me')->assertJsonCount(2, 'offline_methods');
        $this->submit(['method' => 'bank'])->assertJsonValidationErrors('method');
    }

    public function test_client_submits_receipt_and_invoice_goes_into_review(): void
    {
        $id = $this->submit()->assertCreated()->assertJsonPath('data.status', 'review')->json('data.id');

        $payment = Payment::findOrFail($id);
        Storage::disk('local')->assertExists($payment->receipt_path);
        $this->assertSame('etransfer.png', $payment->receipt_name);
        Mail::assertQueued(AdminAlertMail::class, fn ($m) => str_contains($m->heading, 'Payment proof to review'));

        $this->getJson('/api/portal/invoices')->assertOk()
            ->assertJsonPath('data.0.display_status', 'In review')
            ->assertJsonPath('data.0.payable', false)
            ->assertJsonPath('data.0.in_review', true);

        // A second submission for the same invoice is refused while the first is pending.
        $this->submit()->assertJsonValidationErrors('invoice_ids');
    }

    public function test_receipt_must_be_an_image_or_pdf(): void
    {
        $this->submit(['receipt' => UploadedFile::fake()->create('notes.exe', 10, 'application/x-msdownload')])->assertJsonValidationErrors('receipt');
        $this->submit(['receipt' => UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf')])->assertJsonValidationErrors('receipt');
        $this->submit(['receipt' => null])->assertJsonValidationErrors('receipt');
        $this->submit(['receipt' => UploadedFile::fake()->create('cheque.pdf', 200, 'application/pdf'), 'method' => 'cheque'])->assertCreated();
    }

    public function test_clients_cannot_submit_or_download_for_someone_else(): void
    {
        $other = User::factory()->create();
        $this->submit([], $other)->assertJsonValidationErrors('invoice_ids');

        $id = $this->submit()->json('data.id');
        $this->actingAs($other)->get("/api/portal/payments/{$id}/receipt")->assertNotFound();
        $this->actingAs($other)->get("/api/admin/payments/{$id}/receipt")->assertForbidden();
        $this->actingAs($this->client)->get("/api/portal/payments/{$id}/receipt")->assertOk();
        $this->actingAs($this->admin)->get("/api/admin/payments/{$id}/receipt")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_admin_approves_and_invoice_is_paid_with_receipt_email(): void
    {
        $id = $this->submit()->json('data.id');

        $this->actingAs($this->admin)->getJson('/api/admin/payments?status=review')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/dashboard')->assertJsonPath('kpis.payments_to_review', 1);

        $this->postJson("/api/admin/payments/{$id}/approve", ['paid_on' => today()->toDateString()])
            ->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.reviewed_by', $this->admin->name);

        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
        Mail::assertQueued(PaymentReceiptMail::class, fn ($m) => $m->hasTo($this->client->email));
        $this->postJson("/api/admin/payments/{$id}/approve")->assertStatus(422);
    }

    public function test_admin_rejects_and_client_can_pay_again(): void
    {
        $id = $this->submit()->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/admin/payments/{$id}/reject", [])->assertJsonValidationErrors('reason');
        $this->postJson("/api/admin/payments/{$id}/reject", ['reason' => 'No e-Transfer with that reference arrived.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        Mail::assertQueued(PaymentRejectedMail::class, fn ($m) => $m->hasTo($this->client->email));
        $this->actingAs($this->client)->getJson('/api/portal/invoices')
            ->assertJsonPath('data.0.display_status', 'Unpaid')
            ->assertJsonPath('data.0.accepts_offline', true)
            ->assertJsonPath('data.0.rejection.reason', 'No e-Transfer with that reference arrived.');

        $this->submit()->assertCreated();
    }

    public function test_deleting_a_client_removes_their_receipts(): void
    {
        $path = Payment::findOrFail($this->submit()->json('data.id'))->receipt_path;

        $this->actingAs($this->admin)->deleteJson("/api/admin/clients/{$this->client->id}")->assertOk();

        Storage::disk('local')->assertMissing($path);
    }

    public function test_expired_invoices_accept_offline_proof(): void
    {
        $this->invoice->update(['due_at' => today()->subDays(10), 'grace_days' => 3]);
        $this->assertTrue($this->invoice->refresh()->isExpired());

        $this->submit()->assertCreated();
    }

    public function test_no_reminders_while_proof_is_in_review(): void
    {
        $this->invoice->update(['due_at' => today()]);
        $this->submit()->assertCreated();
        $before = Mail::queued(InvoiceMail::class)->count();

        $this->artisan('billing:run')->assertSuccessful();

        $this->assertSame($before, Mail::queued(InvoiceMail::class)->count());
    }
}
