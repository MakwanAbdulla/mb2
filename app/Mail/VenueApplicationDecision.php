<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Booking\VenueApplication;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VenueApplicationDecision extends Mailable
{
    use WithMailTracking;

    public string $bodyHtml;
    public array  $detailsRows = [];

    public function __construct(public VenueApplication $application)
    {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render($this->templateKey(), $this->variables());
        $subject  = $rendered['subject'] ?? ($this->application->status === 'approved'
            ? 'Ansökan godkänd – ' . $this->application->event_name
            : 'Ansökan nekad – ' . $this->application->event_name);

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render($this->templateKey(), $this->variables());
        $this->bodyHtml = $rendered['html'] ?? ($this->application->status === 'approved'
            ? '<p>Er ansökan om att låna lokalen har godkänts.</p>'
            : '<p>Er ansökan om att låna lokalen har tyvärr inte kunnat godkännas.</p>');

        $this->detailsRows = [
            ['Arrangemang', $this->application->event_name],
            ['Ansvarig',    $this->application->responsible_name],
            ['Period',      $this->formatPeriod()],
            ['Deltagare',   $this->application->participants],
        ];

        return new Content(view: 'emails.template-rendered');
    }

    private function templateKey(): string
    {
        return $this->application->status === 'approved'
            ? 'venue_application_approved'
            : 'venue_application_rejected';
    }

    private function variables(): array
    {
        $firstName = explode(' ', trim($this->application->responsible_name))[0] ?: $this->application->responsible_name;

        return [
            'ansvarig'    => $firstName,
            'arrangemang' => $this->application->event_name,
            'period'      => $this->formatPeriod(),
            'admin_svar'  => $this->application->admin_notes ?? '',
        ];
    }

    private function formatPeriod(): string
    {
        return $this->application->starts_at->locale('sv')->isoFormat('D MMMM YYYY, H:mm')
            . ' – '
            . $this->application->ends_at->locale('sv')->isoFormat('D MMMM YYYY, H:mm');
    }
}
