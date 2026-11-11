<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Booking\Booking;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BookingStatusChanged extends Mailable
{
    use WithMailTracking;

    public string $bodyHtml;
    public array  $detailsRows = [];

    public function __construct(
        public Booking $booking,
        public string $oldStatus,
    ) {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render($this->templateKey(), $this->variables());
        $subject  = $rendered['subject'] ?? match ($this->booking->status) {
            'confirmed' => 'Bokning bekräftad – ' . $this->booking->title,
            'cancelled' => 'Bokning avbokad – ' . $this->booking->title,
            'rejected'  => 'Bokning nekad – ' . $this->booking->title,
            default     => 'Bokningsstatus ändrad – ' . $this->booking->title,
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render($this->templateKey(), $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Status på din bokning har uppdaterats.</p>';

        $this->detailsRows = [
            ['Rubrik', $this->booking->title],
            ['Lokal',  $this->booking->resource?->name ?? '–'],
            ['Datum',  $this->booking->starts_at->locale('sv')->isoFormat('dddd D MMMM YYYY')],
            ['Tid',    $this->booking->starts_at->format('H:i') . ' – ' . $this->booking->ends_at->format('H:i')],
        ];

        return new Content(view: 'emails.template-rendered');
    }

    private function templateKey(): string
    {
        return match ($this->booking->status) {
            'confirmed' => 'booking_status_confirmed',
            'cancelled' => 'booking_status_cancelled',
            default     => 'booking_status_rejected',
        };
    }

    private function variables(): array
    {
        $name = $this->booking->user?->name ?? '';

        return [
            'fornamn'   => explode(' ', trim($name))[0] ?: 'du',
            'titel'     => $this->booking->title,
            'lokal'     => $this->booking->resource?->name ?? '–',
            'datum'     => $this->booking->starts_at->locale('sv')->isoFormat('dddd D MMMM YYYY'),
            'starttid'  => $this->booking->starts_at->format('H:i'),
            'sluttid'   => $this->booking->ends_at->format('H:i'),
            'anledning' => $this->booking->rejection_reason ?? '',
        ];
    }
}
