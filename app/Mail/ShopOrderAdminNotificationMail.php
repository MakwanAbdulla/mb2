<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Shop\Order;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ShopOrderAdminNotificationMail extends Mailable
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
        $rendered = app(MailTemplateService::class)->render('shop_order_admin', $this->variables());
        $subject  = $rendered['subject'] ?? "Ny beställning {$this->order->payment_reference} – Armagedon";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('shop_order_admin', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>En ny beställning har lagts.</p>';

        $lineRows = $this->order->lines->map(fn ($l) => [
            ($l->quantity > 1 ? $l->quantity . ' × ' : '') . $l->description,
            number_format($l->line_total_ore / 100, 2, ',', ' ') . ' kr',
        ])->toArray();

        if (($this->order->discount_ore ?? 0) > 0) {
            $lineRows[] = ['Rabatt', '−' . number_format($this->order->discount_ore / 100, 2, ',', ' ') . ' kr'];
        }

        $lineRows[] = ['Totalt', number_format($this->order->total_ore / 100, 2, ',', ' ') . ' kr'];

        $this->detailsRows = array_merge([
            ['Order',             $this->order->payment_reference],
            ['Datum',             $this->order->paid_at?->locale('sv')->isoFormat('D MMMM YYYY, HH:mm') ?? '–'],
            ['Kund',              $this->order->user?->name ?? $this->order->guest_name ?? '–'],
            ['E-post',            $this->order->user?->email ?? $this->order->guest_email ?? '–'],
            ['Betalningsmetod',   $this->order->payment_method ?? '–'],
        ], $lineRows);

        $this->ctaUrl   = route('admin.shop.orders.show', $this->order);
        $this->ctaLabel = 'Visa order i admin';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        return [
            'ordernummer' => $this->order->payment_reference,
            'kund'        => $this->order->user?->name ?? $this->order->guest_name ?? '–',
            'belopp'      => number_format($this->order->total_ore / 100, 2, ',', ' ') . ' kr',
        ];
    }
}
