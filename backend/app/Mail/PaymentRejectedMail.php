<?php

namespace App\Mail;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Payment not confirmed · '.$this->payment->invoices->pluck('number')->implode(', '));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.payment-rejected', with: [
            'payment' => $this->payment,
            'name' => strtok($this->payment->user->name, ' '),
            'amount' => Money::format($this->payment->amount_cents, true),
            'url' => config('business.portal_url').'/invoices',
        ]);
    }
}
