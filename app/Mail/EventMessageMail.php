<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Events\Event;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EventMessageMail extends Mailable
{
    use WithMailTracking;

    public function __construct(
        public readonly Event  $event,
        public readonly string $recipientFirstName,
        public readonly string $messageBody,
    ) {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('event_message', $this->variables());
        $subject  = $rendered['subject'] ?? ('Meddelande om ' . $this->event->title);

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered = app(MailTemplateService::class)->render('event_message', $this->variables());

        return new Content(
            view: 'emails.template-rendered',
            with: [
                'bodyHtml'      => $rendered['html'] ?? '',
                'detailsRows'   => [],
                'ctaUrl'        => route('events.show', $this->event->slug),
                'ctaLabel'      => 'Se evenemang',
                'qrUrl'         => null,
                'trackingToken' => $this->trackingToken,
            ],
        );
    }

    private function variables(): array
    {
        return [
            'fornamn'    => $this->recipientFirstName,
            'event_namn' => $this->event->title,
            'meddelande' => $this->messageBody,
            'event_url'  => route('events.show', $this->event->slug),
        ];
    }
}
