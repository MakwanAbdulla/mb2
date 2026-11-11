<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Booking\VenueApplication;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VenueApplicationReceived extends Mailable
{
    use WithMailTracking;

    public string  $bodyHtml;
    public array   $detailsRows = [];
    public ?string $ctaUrl      = null;
    public ?string $ctaLabel    = null;

    public function __construct(public VenueApplication $application)
    {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('venue_application_received', $this->variables());
        $subject  = $rendered['subject'] ?? 'Ny lokalansökan: ' . $this->application->event_name;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('venue_application_received', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>En ny lokalansökan har inkommit.</p>';

        $this->detailsRows = array_values(array_filter([
            ['Arrangemang', $this->application->event_name],
            ['Ansvarig',    $this->application->responsible_name],
            ['E-post',      $this->application->responsible_email],
            $this->application->responsible_phone ? ['Telefon', $this->application->responsible_phone] : null,
            ['Period',      $this->formatPeriod()],
            ['Deltagare',   $this->application->participants],
            ['Alkohol',     $this->application->has_alcohol ? 'Ja' : 'Nej'],
            ['Övernattning', $this->application->needs_overnight ? 'Ja' : 'Nej'],
        ]));

        $this->ctaUrl   = route('booking.admin.venue-applications.show', $this->application);
        $this->ctaLabel = 'Granska ansökan';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        return [
            'arrangemang' => $this->application->event_name,
            'ansvarig'    => $this->application->responsible_name,
            'epost'       => $this->application->responsible_email,
            'period'      => $this->formatPeriod(),
            'deltagare'   => (string) $this->application->participants,
            'granska_url' => route('booking.admin.venue-applications.show', $this->application),
        ];
    }

    private function formatPeriod(): string
    {
        return $this->application->starts_at->locale('sv')->isoFormat('D MMMM YYYY, H:mm')
            . ' – '
            . $this->application->ends_at->locale('sv')->isoFormat('D MMMM YYYY, H:mm');
    }
}
