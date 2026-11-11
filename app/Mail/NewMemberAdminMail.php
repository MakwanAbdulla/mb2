<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Membership\Member;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NewMemberAdminMail extends Mailable
{
    use WithMailTracking;

    public string  $bodyHtml;
    public array   $detailsRows = [];
    public ?string $ctaUrl      = null;
    public ?string $ctaLabel    = null;

    public function __construct(public Member $member)
    {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('membership_applied_admin', $this->variables());
        $subject  = $rendered['subject'] ?? 'Ny medlemsansökan: ' . $this->member->full_name;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('membership_applied_admin', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>En ny medlemsansökan har inkommit.</p>';

        $this->detailsRows = array_values(array_filter([
            ['Namn',     $this->member->full_name],
            ['E-post',   $this->member->email ?? '–'],
            ['Telefon',  $this->member->phone ?? null],
            ['Typ',      $this->membershipTypeLabel($this->member->membership_type ?? '')],
            ['Status',   $this->member->status],
            ['Inkommit', now()->locale('sv')->isoFormat('D MMMM YYYY, HH:mm')],
        ]));

        $this->ctaUrl   = route('admin.members.show', $this->member);
        $this->ctaLabel = 'Visa i admin';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        return [
            'namn'      => $this->member->full_name,
            'epost'     => $this->member->email ?? '–',
            'typ'       => $this->membershipTypeLabel($this->member->membership_type ?? ''),
            'datum'     => now()->locale('sv')->isoFormat('D MMMM YYYY, HH:mm'),
            'admin_url' => route('admin.members.show', $this->member),
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
