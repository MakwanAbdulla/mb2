<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Rental\Rental;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RentalTerminatedMail extends Mailable
{
    use WithMailTracking;

    public string $bodyHtml;
    public array  $detailsRows = [];

    public function __construct(public Rental $rental)
    {
        $this->rental->loadMissing('item.type', 'member');
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('rental_terminated', $this->variables());
        $subject  = $rendered['subject'] ?? 'Hyra avslutad – ' . $this->rental->item->type->name . ' ' . $this->rental->item->label;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('rental_terminated', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Din hyra har avslutats.</p>';

        $this->detailsRows = [
            ['Typ',       $this->rental->item->type->name],
            ['Etikett',   $this->rental->item->label],
            ['Slutdatum', $this->rental->ended_at?->locale('sv')->isoFormat('D MMMM YYYY') ?? '–'],
        ];

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        return [
            'fornamn'   => $this->rental->member->first_name,
            'typ'       => $this->rental->item->type->name,
            'etikett'   => $this->rental->item->label,
            'slutdatum' => $this->rental->ended_at?->locale('sv')->isoFormat('D MMMM YYYY') ?? '–',
        ];
    }
}
