<?php

namespace App\Mail;

use App\Models\Membership\Member;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WorkspacePasswordMail extends Mailable
{
    public string  $bodyHtml    = '';
    public array   $detailsRows = [];
    public ?string $ctaUrl      = null;
    public ?string $ctaLabel    = null;

    private ?array $rendered = null;

    public function __construct(
        public Member $member,
        public string $workspaceEmail,
        public string $tempPassword,
        public bool   $isNew = true,
    ) {}

    private function rendered(): array
    {
        return $this->rendered ??= app(MailTemplateService::class)->render('workspace_password', $this->variables());
    }

    public function envelope(): Envelope
    {
        $subject = $this->rendered()['subject'] ?? ($this->isNew
            ? 'Ditt Google Workspace-konto är klart – Armagedon'
            : 'Ditt lösenord har återställts – Armagedon');

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $this->bodyHtml = $this->rendered()['html'] ?? $this->fallbackHtml();

        $this->detailsRows = [
            ['E-postadress', $this->workspaceEmail],
            ['Tillfälligt lösenord', $this->tempPassword],
        ];

        $this->ctaUrl   = 'https://accounts.google.com';
        $this->ctaLabel = 'Logga in på Google';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        return [
            'fornamn'      => $this->member->first_name,
            'workspace'    => $this->workspaceEmail,
            'losenord'     => $this->tempPassword,
            'ny_eller_ny'  => $this->isNew ? 'skapat' : 'återställt',
        ];
    }

    private function fallbackHtml(): string
    {
        $action = $this->isNew ? 'skapat' : 'återställt';
        return "<p>Hej {$this->member->first_name},</p>"
            . "<p>Ditt Google Workspace-konto har {$action}.</p>"
            . "<p><strong>E-postadress:</strong> {$this->workspaceEmail}<br>"
            . "<strong>Tillfälligt lösenord:</strong> {$this->tempPassword}</p>"
            . "<p>Du ombeds byta lösenord direkt vid första inloggningen.</p>";
    }
}
