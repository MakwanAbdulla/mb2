<?php

namespace App\Mail;

use App\Models\User;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MagicLinkMail extends Mailable
{
    public string $bodyHtml;

    public ?string $ctaUrl = null;

    public ?string $ctaLabel = null;

    public function __construct(
        public User $user,
        public string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('magic_link', $this->variables());
        $subject = $rendered['subject'] ?? 'Din inloggningslänk';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered = app(MailTemplateService::class)->render('magic_link', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Klicka på länken nedan för att logga in.</p>';
        $this->ctaUrl = $this->loginUrl;
        $this->ctaLabel = 'Logga in';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        return [
            'app_name' => config('app.name'),
            'fornamn' => explode(' ', $this->user->name)[0],
            'login_url' => $this->loginUrl,
        ];
    }
}
