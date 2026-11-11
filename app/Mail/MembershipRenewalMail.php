<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Membership\Member;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MembershipRenewalMail extends Mailable
{
    use WithMailTracking;

    public string $bodyHtml;
    public array  $detailsRows = [];
    public string $ctaUrl;
    public string $ctaLabel;

    public function __construct(
        public Member $member,
        public int    $renewalYear,
    ) {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $service  = app(MailTemplateService::class);
        $rendered = $service->render('membership_renewal', $this->variables());
        $subject  = $rendered ? $rendered['subject'] : 'Dags att förnya ditt Armagedon-medlemskap!';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $service  = app(MailTemplateService::class);
        $rendered = $service->render('membership_renewal', $this->variables());

        $this->bodyHtml = $rendered ? $rendered['html'] : '<p>Dags att förnya ditt medlemskap!</p>';
        $this->ctaUrl   = route('membership.apply');
        $this->ctaLabel = 'Förnya medlemskap';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        $currentPeriod = $this->member->membershipPeriods()
            ->where('year', $this->renewalYear - 1)
            ->where('status', 'active')
            ->first();

        return [
            'fornamn'       => $this->member->first_name,
            'efternamn'     => $this->member->last_name,
            'nuvarande_typ' => $this->membershipTypeLabel($currentPeriod?->membership_type ?? ''),
            'ar'            => (string) $this->renewalYear,
            'fornya_url'    => route('membership.apply'),
        ];
    }

    private function membershipTypeLabel(string $type): string
    {
        return match ($type) {
            'single'  => 'Vuxen',
            'family'  => 'Familj',
            'youth'   => 'Ungdom',
            'child'   => 'Barn',
            'student' => 'Student',
            'senior'  => 'Senior',
            default   => 'Medlem',
        };
    }
}
