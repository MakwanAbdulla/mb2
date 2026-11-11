<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Shop\Order;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrderReadyForPickupMail extends Mailable
{
    use WithMailTracking;

    public string  $bodyHtml;
    public array   $detailsRows = [];
    public ?string $ctaUrl      = null;
    public ?string $ctaLabel    = null;

    public function __construct(public Order $order)
    {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('order_ready_for_pickup', $this->variables());
        $subject  = $rendered['subject'] ?? "Din beställning {$this->order->payment_reference} är redo att hämtas";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('order_ready_for_pickup', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Din beställning är redo att hämtas.</p>';

        $this->detailsRows = [
            ['Order', $this->order->payment_reference],
        ];

        $this->ctaUrl   = route('shop.my-orders');
        $this->ctaLabel = 'Mina beställningar';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        $name = $this->order->user?->name ?? $this->order->guest_name ?? '';

        return [
            'fornamn'     => explode(' ', trim($name))[0] ?: 'du',
            'ordernummer' => $this->order->payment_reference,
        ];
    }
}
