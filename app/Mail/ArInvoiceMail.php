<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Accounting\Invoice;
use App\Services\InvoicePdfService;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ArInvoiceMail extends Mailable
{
    use WithMailTracking;

    public string $bodyHtml;
    public array  $detailsRows = [];

    public function __construct(public Invoice $invoice)
    {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('ar_invoice', $this->variables());
        $subject  = $rendered['subject']
            ?? 'Faktura #' . $this->invoice->invoice_number . ' från ' . config('app.name', 'Armagedon');

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('ar_invoice', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Bifogat finns er faktura.</p>';

        $this->detailsRows = array_values(array_filter([
            ['Fakturanummer', $this->invoice->invoice_number],
            ['Belopp', number_format($this->invoice->total / 100, 2, ',', ' ') . ' kr'],
            $this->invoice->due_date
                ? ['Förfallodatum', $this->invoice->due_date->format('d/m Y')]
                : null,
            $this->invoice->ocr_number
                ? ['OCR-nummer', $this->invoice->ocr_number]
                : null,
            $this->invoice->bankAccount?->bankgiro
                ? ['Bankgiro', $this->invoice->bankAccount->bankgiro]
                : ($this->invoice->bankAccount?->swish_number
                    ? ['Swish', $this->invoice->bankAccount->swish_number]
                    : null),
        ]));

        return new Content(view: 'emails.template-rendered');
    }

    public function attachments(): array
    {
        $pdfService = app(InvoicePdfService::class);
        $response   = $pdfService->forInvoice($this->invoice, inline: false);

        return [
            Attachment::fromData(
                fn () => $response->getContent(),
                "faktura-{$this->invoice->invoice_number}.pdf"
            )->withMime('application/pdf'),
        ];
    }

    private function variables(): array
    {
        return [
            'fornamn'       => $this->invoice->customer?->name ?? '',
            'faktura_nr'    => (string) $this->invoice->invoice_number,
            'belopp'        => number_format($this->invoice->total / 100, 2, ',', ' ') . ' kr',
            'forfallodatum' => $this->invoice->due_date?->format('d/m Y') ?? '–',
        ];
    }
}
