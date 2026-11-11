<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Content\Mailing;
use App\Models\Content\MailingRecipient;
use App\Models\Membership\Member;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\URL;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

class MailingMail extends Mailable
{
    use WithMailTracking;

    public string $bodyHtml;

    public function __construct(
        public readonly Mailing          $mailing,
        public readonly MailingRecipient $recipient,
    ) {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->resolveVariables($this->mailing->subject),
        );
    }

    public function headers(): \Illuminate\Mail\Mailables\Headers
    {
        $url = $this->buildUnsubscribeUrl();

        return new \Illuminate\Mail\Mailables\Headers(
            text: [
                'List-Unsubscribe'      => "<{$url}>",
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        );
    }

    public function content(): Content
    {
        $body = $this->resolveVariables($this->mailing->body_html);

        // Wrap in email template and inline CSS
        $wrapped = view('emails.template-rendered', [
            'bodyHtml'      => $body,
            'trackingToken' => $this->trackingToken,
        ])->render();

        $inliner        = new CssToInlineStyles();
        $this->bodyHtml = $inliner->convert($wrapped);

        return new Content(view: 'emails.mailing-inlined');
    }

    private function resolveVariables(string $text): string
    {
        $vars = $this->buildVariables();

        foreach ($vars as $key => $value) {
            $text = str_replace('{{' . $key . '}}', $value, $text);
        }

        return $text;
    }

    private function buildVariables(): array
    {
        $member = $this->recipient->member_id
            ? Member::find($this->recipient->member_id)
            : null;

        return [
            'fornamn'          => $member?->first_name ?? '',
            'efternamn'        => $member?->last_name ?? '',
            'namn'             => $member ? trim($member->first_name . ' ' . $member->last_name) : '',
            'email'            => $this->recipient->email,
            'medlemsnummer'    => $member?->membership_number ?? '',
            'medlemskapstyp'   => $member ? $this->memberTypeLabel($member->membership_type) : '',
            'foreningsnamn'    => config('app.association_name', 'Armagedon'),
            'datum'            => now()->isoFormat('D MMMM YYYY'),
            'ar'               => now()->format('Y'),
            'avprenumerera'    => $this->buildUnsubscribeUrl(),
        ];
    }

    private function buildUnsubscribeUrl(): string
    {
        $params = [
            'mailing' => $this->mailing->id,
            'email'   => base64_encode($this->recipient->email),
        ];

        if ($this->mailing->category) {
            $params['category'] = $this->mailing->category;
        }

        return URL::signedRoute('mailing.unsubscribe', $params);
    }

    private function memberTypeLabel(string $type): string
    {
        return match ($type) {
            Member::TYPE_SINGLE   => 'Enskild',
            Member::TYPE_FAMILY   => 'Familj',
            Member::TYPE_YOUTH    => 'Ungdom',
            Member::TYPE_CHILD    => 'Barn',
            Member::TYPE_STUDENT  => 'Student',
            Member::TYPE_SENIOR   => 'Senior',
            Member::TYPE_HONORARY => 'Hedersmedlem',
            default               => $type,
        };
    }
}
