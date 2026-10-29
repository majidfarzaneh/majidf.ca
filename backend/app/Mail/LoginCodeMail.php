<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent synchronously so sign-in never depends on the queue worker. */
class LoginCodeMail extends Mailable
{
    public function __construct(public User $user, public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->code.' is your sign-in code');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.login-code', with: [
            'name' => strtok($this->user->name, ' '),
            'code' => $this->code,
            'ttl' => config('business.login_code_ttl_minutes'),
        ]);
    }
}
