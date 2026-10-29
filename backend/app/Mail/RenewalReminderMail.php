<?php

namespace App\Mail;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RenewalReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Service $service) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->service->name.' renews on '.$this->service->renews_at->format('M j'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.renewal', with: [
            'service' => $this->service,
            'name' => strtok($this->service->user->name, ' '),
            'url' => config('business.portal_url').'/services',
        ]);
    }
}
