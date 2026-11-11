<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Membership\AccountDeletionRequest;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountDeletionRejected extends Mailable
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
        $rendered = app(MailTemplateService::class)->render('account_deletion_rejected', $this->variables());
        $subject  = $rendered['subject'] ?? 'Angående din begäran om radering – Armagedon';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('account_deletion_rejected', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Din begäran om radering har tyvärr inte kunnat beviljas.</p>';

        $this->detailsRows = array_values(array_filter([
            $this->deletionRequest->admin_note ? ['Anledning', $this->deletionRequest->admin_note] : null,
        ]));

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        return [
            'fornamn'    => $this->deletionRequest->member?->first_name ?? '',
            'admin_svar' => $this->deletionRequest->admin_note ?? '',
        ];
    }
}
