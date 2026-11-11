<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MembershipApplied extends Mailable
{
    use WithMailTracking;

    public string $bodyHtml;
    public array  $detailsRows = [];

    public function __construct(
        public Member $member,
        public ?MembershipPeriod $period = null,
    ) {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('membership_applied', $this->variables());
        $subject  = $rendered['subject'] ?? 'Ansökan mottagen – Armagedon';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('membership_applied', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Din ansökan har mottagits.</p>';

        $this->detailsRows = array_filter([
            ['Namn', $this->member->full_name],
            ['Typ', $this->membershipTypeLabel($this->period?->membership_type ?? $this->member->membership_type ?? '')],
            $this->period ? ['År', $this->period->year] : null,
            ['Status', 'Väntar på betalning'],
        ]);

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        return [
            'fornamn'   => $this->member->first_name,
            'efternamn' => $this->member->last_name,
            'typ'       => $this->membershipTypeLabel($this->period?->membership_type ?? $this->member->membership_type ?? ''),
            'ar'        => (string) ($this->period?->year ?? now()->year),
        ];
    }

    private function membershipTypeLabel(string $type): string
    {
        return match ($type) {
            'single'   => 'Vuxen',
            'family'   => 'Familj',
            'youth'    => 'Ungdom',
            'child'    => 'Barn',
            'student'  => 'Student',
            'senior'   => 'Senior',
            'honorary' => 'Hedersmedlem',
            default    => ucfirst($type),
        };
    }
}
