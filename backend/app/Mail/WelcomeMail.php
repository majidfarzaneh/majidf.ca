<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your client portal is ready');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.welcome', with: [
            'name' => strtok($this->user->name, ' '),
            'email' => $this->user->email,
            'url' => config('business.portal_url').'/login?email='.urlencode($this->user->email),
        ]);
    }
}
