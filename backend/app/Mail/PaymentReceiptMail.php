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

class PaymentReceiptMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Receipt · '.Money::format($this->payment->amount_cents, true).' received');
    }

    public function content(): Content
    {
        $this->payment->loadMissing('invoices', 'user');

        return new Content(view: 'mail.receipt', with: [
            'payment' => $this->payment,
            'name' => strtok($this->payment->user->name, ' '),
            'amount' => Money::format($this->payment->amount_cents, true),
            'method' => $this->payment->methodLabel(),
            'url' => config('business.portal_url').'/invoices',
        ]);
    }
}
