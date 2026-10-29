<?php

namespace App\Mail;

use App\Enums\ServiceStatus;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells a client that a service was added to their account (Admin → Add service). */
class ServiceAddedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Service $service) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->service->name.' was added to your account');
    }

    public function content(): Content
    {
        $service = $this->service;
        $recurring = $service->billing_cycle?->isRecurring() ?? false;

        return new Content(view: 'mail.service-added', with: [
            'service' => $service,
            'name' => strtok($service->user->name, ' '),
            'intro' => $service->status === ServiceStatus::InDevelopment
                ? "I've started on your new {$this->kind()} and added it to your client portal, where you can follow its progress."
                : "your new {$this->kind()} is now in your client portal.",
            'price' => $service->priceLabel() === '—' ? null : $service->priceLabel(),
            'renews' => $recurring ? $service->renews_at?->format('M j, Y') : null,
            'autoInvoice' => $recurring && $service->auto_invoice,
            'url' => config('business.portal_url').'/services',
        ]);
    }

    private function kind(): string
    {
        return strtolower($this->service->type->label()).' service';
    }
}
