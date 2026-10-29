<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\InvoicePdf;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Every client email about an invoice. `kind` is one of:
 * issued, due_soon, due_today, overdue (still payable in the grace period), closed (payment window over)
 * or autopay_failed (the saved card was declined; `reason` says why).
 */
class InvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const KINDS = ['issued', 'due_soon', 'due_today', 'overdue', 'closed', 'autopay_failed'];

    public function __construct(public Invoice $invoice, public string $kind = 'issued', public ?string $reason = null) {}

    /** The right reminder for an invoice's current state (used by the manual "Send reminder" button). */
    public static function reminderKindFor(Invoice $invoice): string
    {
        return match (true) {
            $invoice->isExpired() => 'closed',
            $invoice->isOverdue() => 'overdue',
            $invoice->due_at?->isToday() ?? false => 'due_today',
            default => 'due_soon',
        };
    }

    public function envelope(): Envelope
    {
        $n = $this->invoice->number;
        $due = $this->invoice->due_at?->format('M j');
        $until = $this->invoice->pay_until?->format('M j');

        return new Envelope(subject: match ($this->kind) {
            'due_soon' => "Reminder: invoice {$n} is due on {$due}",
            'due_today' => "Invoice {$n} is due today",
            'overdue' => "Overdue: invoice {$n} · pay by {$until}",
            'closed' => "Invoice {$n}: online payment closed",
            'autopay_failed' => "Automatic payment for invoice {$n} didn't go through",
            default => "Invoice {$n} from ".config('business.name'),
        });
    }

    public function content(): Content
    {
        $invoice = $this->invoice;
        $due = $invoice->due_at?->format('F j');
        $until = $invoice->pay_until?->format('F j');
        $days = $invoice->due_at ? (int) today()->diffInDays($invoice->due_at, false) : null;
        $portal = config('business.portal_url').'/invoices';

        [$eyebrow, $heading, $intro] = match ($this->kind) {
            'due_soon' => ['reminder', $days === 1 ? 'Due tomorrow' : "Due in {$days} days", "a friendly reminder that invoice {$invoice->number} is due on {$due}."],
            'due_today' => ['due today', 'Due today', "invoice {$invoice->number} is due today. It only takes a minute to pay in the portal."],
            'overdue' => ['overdue', 'Payment overdue', "invoice {$invoice->number} was due on {$due}. You can still pay it online until {$until}."],
            'autopay_failed' => ['auto-pay failed', 'Automatic payment failed', "we tried to charge your saved card for invoice {$invoice->number}, but it didn't go through".($this->reason ? ': '.rtrim($this->reason, '.').'.' : '.')." Please pay it in the client portal by {$due}. You can update your card there too."],
            'closed' => ['payment window closed', 'Online payment closed', "invoice {$invoice->number} was due on {$due} and the online payment window ended on {$until}. Please reply to this email so we can sort it out."],
            default => ['new invoice', 'New invoice '.$invoice->number, 'here is your invoice. You can pay it by card from the client portal.'],
        };

        return new Content(view: 'mail.invoice', with: [
            'invoice' => $invoice,
            'name' => strtok($invoice->user->name, ' '),
            'amount' => Money::format($invoice->total_cents, true),
            'urgent' => in_array($this->kind, ['overdue', 'closed', 'autopay_failed'], true),
            'eyebrow' => $eyebrow,
            'heading' => $heading,
            'intro' => $intro,
            'payUntil' => in_array($this->kind, ['overdue', 'due_today'], true) ? $invoice->pay_until?->format('M j, Y') : null,
            'url' => $this->kind === 'closed' ? 'mailto:'.config('business.email').'?subject='.rawurlencode('Invoice '.$invoice->number) : $portal,
            'button' => $this->kind === 'closed' ? 'Email '.config('business.owner_name') : 'View & pay invoice',
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => app(InvoicePdf::class)->render($this->invoice), $this->invoice->number.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
