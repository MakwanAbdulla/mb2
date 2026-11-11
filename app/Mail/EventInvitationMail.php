<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Events\EventInvitation;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EventInvitationMail extends Mailable
{
    use WithMailTracking;

    public function __construct(public readonly EventInvitation $invitation)
    {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('event_invitation', $this->variables());
        $subject  = $rendered['subject'] ?? ('Du är inbjuden – ' . $this->invitation->event->title);

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered = app(MailTemplateService::class)->render('event_invitation', $this->variables());

        return new Content(
            view: 'emails.template-rendered',
            with: [
                'bodyHtml'      => $rendered['html'] ?? '',
                'detailsRows'   => [],
                'ctaUrl'        => null,
                'ctaLabel'      => null,
                'qrUrl'         => null,
                'trackingToken' => $this->trackingToken,
            ],
        );
    }

    private function variables(): array
    {
        $event  = $this->invitation->event;
        $member = $this->invitation->member;

        $fornamn = $member
            ? explode(' ', trim($member->name ?? ''))[0] ?: 'Medlem'
            : 'Hej';

        $datum = $event->occurrences
            ->map(fn ($o) => $o->starts_at->locale('sv')->isoFormat('D MMMM YYYY, HH:mm'))
            ->join(', ');

        return [
            'fornamn'      => $fornamn,
            'event_namn'   => $event->title,
            'datum'        => $datum ?: '–',
            'plats'        => $event->location_name ?? '–',
            'inbjudan_url' => route('events.accept-invitation', ['event' => $event->slug, 'token' => $this->invitation->token]),
            'event_url'    => route('events.show', $event->slug),
        ];
    }
}
