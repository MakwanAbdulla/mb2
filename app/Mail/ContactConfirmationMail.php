<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\ContactMessage;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ContactConfirmationMail extends Mailable
{
    use WithMailTracking;

    public string $bodyHtml;
    public array  $detailsRows = [];

    public function __construct(public ContactMessage $contact)
    {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('contact_confirmation', $this->variables());
        $subject  = $rendered['subject'] ?? 'Vi har mottagit ditt meddelande – Armagedon';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('contact_confirmation', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Tack för ditt meddelande. Vi återkommer så snart vi kan.</p>';

        $this->detailsRows = [
            ['Ämne', $this->contact->subject],
            ['Ditt meddelande', $this->contact->message],
        ];

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        $name = $this->contact->name;

        return [
            'fornamn'    => explode(' ', trim($name))[0] ?: $name,
            'amne'       => $this->contact->subject,
            'meddelande' => $this->contact->message,
        ];
    }
}
