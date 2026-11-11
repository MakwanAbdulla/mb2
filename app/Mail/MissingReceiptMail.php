<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\CorporateCard;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MissingReceiptMail extends Mailable
{
    use WithMailTracking;

    public string  $bodyHtml;
    public array   $detailsRows = [];
    public ?string $ctaUrl      = null;
    public ?string $ctaLabel    = null;

    public function __construct(
        public CorporateCard   $card,
        public BankTransaction $transaction,
    ) {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('missing_receipt', $this->variables());
        $date     = $this->transaction->transaction_date?->format('d/m Y') ?? '–';
        $amount   = number_format(abs($this->transaction->amount) / 100, 2, ',', ' ');
        $subject  = $rendered['subject'] ?? "Kvitto saknas – {$amount} kr ({$date})";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('missing_receipt', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Vi har ett köp på ditt föreningskort som saknar kvitto.</p>';

        $this->detailsRows = [
            ['Datum',  $this->transaction->transaction_date?->format('d/m Y') ?? '–'],
            ['Butik',  $this->transaction->description],
            ['Belopp', number_format(abs($this->transaction->amount) / 100, 2, ',', ' ') . ' kr'],
        ];

        $this->ctaUrl   = route('expense-claims.create', ['card' => $this->card->id]);
        $this->ctaLabel = 'Skicka in kvitto';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        return [
            'fornamn'     => $this->card->member?->first_name ?? '',
            'belopp'      => number_format(abs($this->transaction->amount) / 100, 2, ',', ' ') . ' kr',
            'beskrivning' => $this->transaction->description,
            'datum'       => $this->transaction->transaction_date?->format('d/m Y') ?? '–',
        ];
    }
}
