<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Membership\Member;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class FamilyInvitationMail extends Mailable
{
    use WithMailTracking;

    public string  $bodyHtml;
    public ?string $ctaUrl   = null;
    public ?string $ctaLabel = null;

    public function __construct(
        public Member $invitedMember,
        public ?Member $invitedBy,
    ) {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('family_invitation', $this->variables());
        $subject  = $rendered['subject'] ?? 'Du har blivit inbjuden till en familjegrupp';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('family_invitation', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Du har blivit inbjuden till en familjegrupp. Logga in för att svara.</p>';
        // The accept/decline buttons live on the dashboard's Familj tab —
        // family.manage is only open to the group's adults, not the invitee.
        $this->ctaUrl   = route('dashboard').'#familj';
        $this->ctaLabel = 'Svara på inbjudan';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        return [
            'fornamn'   => $this->invitedMember->first_name,
            'avsandare' => $this->invitedBy?->full_name ?? 'En familjeansvarig',
        ];
    }
}
