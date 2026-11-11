<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Shop\Order;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ShopOrderConfirmationMail extends Mailable
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
        $rendered = app(MailTemplateService::class)->render('shop_order_confirmed', $this->variables());
        $subject  = $rendered['subject']
            ?? "Orderbekräftelse {$this->order->payment_reference} – Armagedon";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('shop_order_confirmed', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Tack för din beställning!</p>';

        $lineRows = $this->order->lines->map(fn ($l) => [
            ($l->quantity > 1 ? $l->quantity . ' × ' : '') . $l->description,
            number_format($l->line_total_ore / 100, 2, ',', ' ') . ' kr',
        ])->toArray();

        if (($this->order->discount_ore ?? 0) > 0) {
            $lineRows[] = ['Rabatt', '−' . number_format($this->order->discount_ore / 100, 2, ',', ' ') . ' kr'];
        }

        $lineRows[] = ['Totalt', number_format($this->order->total_ore / 100, 2, ',', ' ') . ' kr'];

        $this->detailsRows = array_merge([
            ['Order',  $this->order->payment_reference],
            ['Datum',  $this->order->paid_at?->locale('sv')->isoFormat('D MMMM YYYY') ?? '–'],
        ], $lineRows);

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
            'belopp'      => number_format($this->order->total_ore / 100, 2, ',', ' ') . ' kr',
        ];
    }
}
