<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Membership\AccountDeletionRequest;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountDeletionReceived extends Mailable
{
    use WithMailTracking;

    public string $bodyHtml;
    public array  $detailsRows = [];

    public function __construct(public AccountDeletionRequest $deletionRequest)
    {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('account_deletion_received', $this->variables());
        $subject  = $rendered['subject'] ?? 'Din begäran om radering är mottagen – Armagedon';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('account_deletion_received', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Din begäran om radering har mottagits och granskas inom 30 dagar.</p>';

        $this->detailsRows = array_values(array_filter([
            ['Status', 'Väntar på granskning'],
            $this->deletionRequest->reason ? ['Din anledning', $this->deletionRequest->reason] : null,
        ]));

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        return [
            'fornamn'   => $this->deletionRequest->member?->first_name ?? '',
            'anledning' => $this->deletionRequest->reason ?? '',
        ];
    }
}
