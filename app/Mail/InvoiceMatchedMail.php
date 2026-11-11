<?php

namespace App\Mail;

use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\Invoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

class InvoiceMatchedMail extends Mailable
{
    public function __construct(
        public BankTransaction $transaction,
        public Invoice         $invoice,
    ) {}

    public function envelope(): Envelope
    {
        $amount = number_format(abs($this->transaction->amount) / 100, 2, ',', ' ');
        $number = $this->invoice->invoice_number ?? "#{$this->invoice->id}";

        $subject = $this->invoice->isPayable()
            ? "Faktura {$number} betald — {$amount} kr"
            : "Betalning mottagen — Faktura {$number} {$amount} kr";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invoice-matched');
    }

    public function attachments(): array
    {
        // Attach the first PDF document associated with the invoice, if any
        $doc = $this->invoice->documents()
            ->where('mime_type', 'like', '%pdf%')
            ->first();

        if (! $doc || ! $doc->fileExists()) {
            return [];
        }

        return [
            Attachment::fromStorageDisk($doc->disk, $doc->path)
                ->as($doc->original_name)
                ->withMime($doc->mime_type),
        ];
    }
}
