<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Booking\Booking;
use App\Models\SystemSetting;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BookingReminder extends Mailable
{
    use WithMailTracking;

    public string  $bodyHtml;
    public array   $detailsRows = [];
    public ?string $ctaUrl      = null;
    public ?string $ctaLabel    = null;

    public function __construct(public Booking $booking)
    {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('booking_reminder', $this->variables());
        $subject  = $rendered['subject']
            ?? 'Påminnelse: ' . $this->booking->title . ' ' . $this->booking->starts_at->format('j/n H:i');

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('booking_reminder', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Du har en bokning imorgon.</p>';

        $this->detailsRows = array_values(array_filter([
            ['Rubrik', $this->booking->title],
            ['Lokal', $this->booking->resource?->name ?? '–'],
            ['Tid',
                $this->booking->starts_at->locale('sv')->isoFormat('dddd D MMMM, H:mm')
                . ' – ' . $this->booking->ends_at->format('H:i'),
            ],
            $this->booking->resource?->location
                ? ['Plats', $this->booking->resource->location]
                : null,
        ]));

        $this->ctaUrl   = route('membership.show');
        $this->ctaLabel = 'Mina bokningar';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        $name  = $this->booking->user?->name ?? '';
        $extra = SystemSetting::get('reminder_email_message', '');

        return [
            'fornamn'         => explode(' ', trim($name))[0] ?: 'du',
            'titel'           => $this->booking->title,
            'lokal'           => $this->booking->resource?->name ?? '–',
            'datum'           => $this->booking->starts_at->locale('sv')->isoFormat('dddd D MMMM, H:mm'),
            'sluttid'         => $this->booking->ends_at->format('H:i'),
            'plats'           => $this->booking->resource?->location ?? '',
            'extrameddelande' => $extra,
        ];
    }
}
