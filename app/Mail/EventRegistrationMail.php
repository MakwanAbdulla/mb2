<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Events\EventRegistration;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EventRegistrationMail extends Mailable
{
    use WithMailTracking;

    public function __construct(
        public EventRegistration $registration,
        private string $templateKey,
    ) {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render($this->templateKey, $this->variables());
        $subject  = $rendered['subject'] ?? ('Evenemangsanmälan – ' . $this->registration->event->title);

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered    = app(MailTemplateService::class)->render($this->templateKey, $this->variables());
        $bodyHtml    = $rendered['html'] ?? '';
        $event       = $this->registration->event;
        $tier        = $this->registration->priceTier;

        $detailsRows = array_values(array_filter([
            ['Evenemang', $event->title],
            $tier ? ['Biljett', $tier->label] : null,
            $tier && $tier->price_ore > 0
                ? ['Pris', number_format($tier->price_ore / 100, 2, ',', ' ') . ' kr']
                : null,
        ]));

        $isPendingPayment = $this->templateKey === 'event_registration_pending_payment';
        $isConfirmed      = \in_array($this->templateKey, ['event_registration_confirmed', 'event_reminder']);

        return new Content(
            view: 'emails.template-rendered',
            with: [
                'bodyHtml'      => $bodyHtml,
                'detailsRows'   => $detailsRows,
                'ctaUrl'        => $isPendingPayment ? route('events.payment', $event->slug) : null,
                'ctaLabel'      => $isPendingPayment ? 'Betala nu' : null,
                'qrUrl'         => ($isConfirmed && $this->registration->check_in_token)
                    ? route('ticket.qr', $this->registration->check_in_token)
                    : null,
                'trackingToken' => $this->trackingToken,
            ],
        );
    }

    /**
     * Resolve recipient email address.
     */
    public function recipientEmail(): ?string
    {
        return $this->registration->user?->email ?? $this->registration->guest_email;
    }

    /**
     * Send (queue) the appropriate mail for a registration's current status.
     * Loads required relations if not already loaded.
     */
    public static function sendFor(EventRegistration $registration): void
    {
        $key = match ($registration->status) {
            EventRegistration::STATUS_CONFIRMED        => 'event_registration_confirmed',
            EventRegistration::STATUS_PENDING_PAYMENT  => 'event_registration_pending_payment',
            EventRegistration::STATUS_PENDING_APPROVAL => 'event_registration_pending_approval',
            EventRegistration::STATUS_WAITLISTED       => 'event_registration_waitlisted',
            EventRegistration::STATUS_CANCELLED        => 'event_registration_cancelled',
            default                                    => null,
        };

        if (! $key) {
            return;
        }

        $registration->loadMissing('event.occurrences', 'priceTier');

        $email = $registration->user?->email ?? $registration->guest_email;
        if (! $email) {
            return;
        }

        try {
            Mail::to($email)->queue(new self($registration, $key));
        } catch (\Throwable $e) {
            Log::error('Failed to queue EventRegistrationMail', [
                'registration_id' => $registration->id,
                'key'             => $key,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    private function variables(): array
    {
        $reg   = $this->registration;
        $event = $reg->event;
        $tier  = $reg->priceTier;

        $name    = $reg->user?->name ?? $reg->guest_name ?? '';
        $fornamn = explode(' ', trim($name))[0] ?: 'Deltagare';

        $datum = $event->occurrences
            ->map(fn ($o) => $o->starts_at->locale('sv')->isoFormat('D MMMM YYYY, HH:mm'))
            ->join(', ');

        return [
            'fornamn'       => $fornamn,
            'event_namn'    => $event->title,
            'biljett_typ'   => $tier?->label ?? '',
            'datum'         => $datum ?: '–',
            'plats'         => $event->location_name ?? '–',
            'belopp'        => $tier
                ? number_format($tier->price_ore / 100, 2, ',', ' ') . ' kr'
                : 'Gratis',
            'betala_url'    => route('events.payment', $event->slug),
            'instruktioner' => $event->registration_instructions ?? '',
        ];
    }
}
