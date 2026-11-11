<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Rental\Rental;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RentalAssignedMail extends Mailable
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
        $rendered = app(MailTemplateService::class)->render('rental_assigned', $this->variables());
        $subject  = $rendered['subject'] ?? 'Hyra tilldelad – ' . $this->rental->item->type->name . ' ' . $this->rental->item->label;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('rental_assigned', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Du har tilldelats en hyra.</p>';

        $this->detailsRows = [
            ['Typ',        $this->rental->item->type->name],
            ['Etikett',    $this->rental->item->label],
            ['Startdatum', $this->rental->started_at->locale('sv')->isoFormat('D MMMM YYYY')],
            ['Avgift',     $this->rental->formattedPrice()],
        ];

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        return [
            'fornamn'    => $this->rental->member->first_name,
            'typ'        => $this->rental->item->type->name,
            'etikett'    => $this->rental->item->label,
            'pris'       => $this->rental->formattedPrice(),
            'startdatum' => $this->rental->started_at->locale('sv')->isoFormat('D MMMM YYYY'),
        ];
    }
}
