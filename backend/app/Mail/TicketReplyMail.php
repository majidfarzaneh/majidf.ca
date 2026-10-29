<?php

namespace App\Mail;

use App\Models\TicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketReplyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public TicketMessage $message) {}

    public function envelope(): Envelope
    {
        $ticket = $this->message->ticket;

        return new Envelope(subject: 'Re: '.$ticket->subject.' ['.$ticket->reference().']');
    }

    public function content(): Content
    {
        $ticket = $this->message->ticket;

        return new Content(view: 'mail.ticket-reply', with: [
            'ticket' => $ticket,
            'author' => config('business.owner_name'),
            'body' => $this->message->body,
            'url' => config('business.portal_url').'/tickets/'.$ticket->number,
        ]);
    }
}
