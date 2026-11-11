<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Survey\SurveyInvitation;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SurveyInvitationMail extends Mailable
{
    use WithMailTracking;

    public string  $bodyHtml;
    public array   $detailsRows = [];
    public ?string $ctaUrl      = null;
    public ?string $ctaLabel    = null;

    public function __construct(public SurveyInvitation $invitation)
    {
        $this->invitation->loadMissing('survey');
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('survey_invitation', $this->variables());
        $subject  = $rendered['subject'] ?? 'Inbjudan att fylla i enkät: ' . $this->invitation->survey->title;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('survey_invitation', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Du har blivit inbjuden att fylla i en enkät.</p>';

        $this->ctaUrl   = route('surveys.respond', $this->invitation->token);
        $this->ctaLabel = 'Fyll i enkäten';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        $survey = $this->invitation->survey;

        return [
            'enkattitel'  => $survey->title,
            'beskrivning' => $survey->description ?? '',
            'svara_url'   => route('surveys.respond', $this->invitation->token),
        ];
    }
}
