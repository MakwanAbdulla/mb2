<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Membership\Member;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MemberInvitationMail extends Mailable
{
    use WithMailTracking;

    public string  $bodyHtml;
    public ?string $ctaUrl   = null;
    public ?string $ctaLabel = null;

    public function __construct(
        public Member $member,
        public string $activationUrl,
    ) {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('member_invitation', $this->variables());
        $subject  = $rendered['subject'] ?? 'Välkommen till Armagedon — aktivera ditt konto';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('member_invitation', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Aktivera ditt konto via länken nedan.</p>';
        $this->ctaUrl   = $this->activationUrl;
        $this->ctaLabel = 'Aktivera konto';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        return [
            'fornamn'      => $this->member->first_name,
            'fullnamn'     => $this->member->full_name,
            'aktivera_url' => $this->activationUrl,
        ];
    }
}
