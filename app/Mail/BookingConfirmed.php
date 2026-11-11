<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Booking\Booking;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BookingConfirmed extends Mailable
{
    use WithMailTracking;

    public string $bodyHtml;
    public array  $detailsRows = [];

    public function __construct(public Booking $booking)
    {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $key      = $this->templateKey();
        $rendered = app(MailTemplateService::class)->render($key, $this->variables());
        $subject  = $rendered['subject'] ?? ($this->booking->status === 'confirmed'
            ? 'Bokning bekräftad – ' . $this->booking->title
            : 'Bokningsförfrågan mottagen – ' . $this->booking->title);

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $key            = $this->templateKey();
        $rendered       = app(MailTemplateService::class)->render($key, $this->variables());
        $this->bodyHtml = $rendered['html'] ?? ($this->booking->status === 'confirmed'
            ? '<p>Din bokning är bekräftad.</p>'
            : '<p>Din bokningsförfrågan har mottagits och väntar på godkännande.</p>');

        $this->detailsRows = [
            ['Rubrik', $this->booking->title],
            ['Lokal',  $this->booking->resource?->name ?? '–'],
            ['Datum',  $this->booking->starts_at->locale('sv')->isoFormat('dddd D MMMM YYYY')],
            ['Tid',    $this->booking->starts_at->format('H:i') . ' – ' . $this->booking->ends_at->format('H:i')],
        ];

        return new Content(view: 'emails.template-rendered');
    }

    private function templateKey(): string
    {
        return $this->booking->status === 'confirmed' ? 'booking_confirmed' : 'booking_pending_approval';
    }

    private function variables(): array
    {
        $name = $this->booking->user?->name ?? '';

        return [
            'fornamn'  => explode(' ', trim($name))[0] ?: 'du',
            'titel'    => $this->booking->title,
            'lokal'    => $this->booking->resource?->name ?? '–',
            'datum'    => $this->booking->starts_at->locale('sv')->isoFormat('dddd D MMMM YYYY'),
            'starttid' => $this->booking->starts_at->format('H:i'),
            'sluttid'  => $this->booking->ends_at->format('H:i'),
        ];
    }
}
