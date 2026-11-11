<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Shop\Order;
use App\Models\Shop\OrderFulfillment;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrderShippedMail extends Mailable
{
    use WithMailTracking;

    public string  $bodyHtml;
    public array   $detailsRows = [];
    public ?string $ctaUrl      = null;
    public ?string $ctaLabel    = null;

    public function __construct(
        public Order $order,
        public OrderFulfillment $fulfillment,
    ) {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('order_shipped', $this->variables());
        $subject  = $rendered['subject'] ?? "Din beställning {$this->order->payment_reference} är skickad";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('order_shipped', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Din beställning är på väg!</p>';

        $this->detailsRows = array_values(array_filter([
            ['Order',          $this->order->payment_reference],
            ['Skickad',        $this->fulfillment->shipped_at?->locale('sv')->isoFormat('D MMMM YYYY') ?? now()->locale('sv')->isoFormat('D MMMM YYYY')],
            $this->fulfillment->tracking_number
                ? ['Spårningsnummer', $this->fulfillment->tracking_number]
                : null,
            $this->fulfillment->notes
                ? ['Notering', $this->fulfillment->notes]
                : null,
        ]));

        $this->ctaUrl   = route('shop.my-orders');
        $this->ctaLabel = 'Mina beställningar';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        $name = $this->order->user?->name ?? $this->order->guest_name ?? '';

        return [
            'fornamn'          => explode(' ', trim($name))[0] ?: 'du',
            'ordernummer'      => $this->order->payment_reference,
            'sparningsnummer'  => $this->fulfillment->tracking_number ?? '–',
        ];
    }
}
