<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MembershipPaymentFailed extends Mailable
{
    use WithMailTracking;

    public string $bodyHtml;

    public array $detailsRows = [];

    public ?string $ctaUrl = null;

    public ?string $ctaLabel = null;

    public function __construct(
        public Member $member,
        public MembershipPeriod $period,
    ) {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('membership_payment_failed', $this->variables());
        $subject = $rendered['subject'] ?? 'Din betalning gick inte igenom – Armagedon';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered = app(MailTemplateService::class)->render('membership_payment_failed', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Din betalning gick inte igenom.</p>';

        $this->detailsRows = array_values(array_filter([
            ['Namn', $this->member->full_name],
            ['Typ', $this->membershipTypeLabel($this->period->membership_type)],
            ['År', $this->period->year],
            ['Status', 'Betalning misslyckades'],
        ]));

        $this->ctaUrl = route('membership.payment');
        $this->ctaLabel = 'Försök igen';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        return [
            'fornamn' => $this->member->first_name,
            'efternamn' => $this->member->last_name,
            'typ' => $this->membershipTypeLabel($this->period->membership_type),
            'ar' => (string) $this->period->year,
            'betala_url' => route('membership.payment'),
        ];
    }

    private function membershipTypeLabel(string $type): string
    {
        return match ($type) {
            'single' => 'Vuxen',
            'family' => 'Familj',
            'youth' => 'Ungdom',
            'child' => 'Barn',
            'student' => 'Student',
            'senior' => 'Senior',
            'honorary' => 'Hedersmedlem',
            default => ucfirst($type),
        };
    }
}
