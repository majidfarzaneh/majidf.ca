<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/** A short notification to the admin: new ticket, client reply, payment, project request. */
class AdminAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param array<string, string> $lines */
    public function __construct(
        public string $eyebrow,
        public string $heading,
        public array $lines,
        public string $path,
    ) {}

    public static function dispatch(string $eyebrow, string $heading, array $lines, string $path): void
    {
        if ($to = config('business.admin_email')) {
            Mail::to($to)->queue(new self($eyebrow, $heading, $lines, $path));
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.$this->eyebrow.'] '.$this->heading);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.admin-alert', with: [
            'eyebrow' => $this->eyebrow,
            'heading' => $this->heading,
            'lines' => $this->lines,
            'url' => config('business.admin_url').$this->path,
        ]);
    }
}
