<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountDeletionApproved extends Mailable
{
    use WithMailTracking;

    public string $bodyHtml;
    public array  $detailsRows = [];

    public function __construct(public string $firstName)
    {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('account_deletion_approved', $this->variables());
        $subject  = $rendered['subject'] ?? 'Ditt konto hos Armagedon har raderats';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('account_deletion_approved', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Dina personuppgifter har anonymiserats i enlighet med GDPR artikel 17.</p>';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        return ['fornamn' => $this->firstName];
    }
}
