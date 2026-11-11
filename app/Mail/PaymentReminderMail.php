<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Membership\Member;
use App\Models\Shop\Order;
use App\Models\SystemSetting;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PaymentReminderMail extends Mailable
{
    use WithMailTracking;

    public string $bodyHtml;
    public array  $detailsRows;
    public string $ctaUrl;
    public string $ctaLabel;

    public function __construct(
        public Order  $order,
        public Member $member,
    ) {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $service  = app(MailTemplateService::class);
        $rendered = $service->render('payment_reminder', $this->variables());
        $subject  = $rendered ? $rendered['subject'] : 'Påminnelse – obetald faktura – Armagedon';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $service  = app(MailTemplateService::class);
        $rendered = $service->render('payment_reminder', $this->variables());

        $this->bodyHtml    = $rendered ? $rendered['html'] : '<p>Din faktura är fortfarande obetald.</p>';
        $this->detailsRows = $this->buildDetailsRows();
        $this->ctaUrl      = route('membership.payment') . '?order=' . $this->order->id;
        $this->ctaLabel    = 'Betala nu';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        $period      = $this->order->lines->first()?->orderable;
        $typeLabel   = $this->membershipTypeLabel($period?->membership_type ?? '');
        $daysSince   = (int) $this->order->created_at->diffInDays(now());

        return [
            'fornamn'    => $this->member->first_name,
            'efternamn'  => $this->member->last_name,
            'typ'        => $typeLabel . 'smedlemskap ' . ($period?->year ?? now()->year),
            'belopp'     => number_format($this->order->total_ore / 100, 2, ',', ' ') . ' kr',
            'ocr_nummer' => $this->order->ocr_number ?? $this->order->payment_reference,
            'betala_url' => route('membership.payment') . '?order=' . $this->order->id,
            'dagar_sedan' => (string) $daysSince,
        ];
    }

    private function buildDetailsRows(): array
    {
        $bg = SystemSetting::get('payment.bankgiro', '');

        $rows = [
            ['Belopp',     number_format($this->order->total_ore / 100, 2, ',', ' ') . ' kr'],
            ['OCR-nummer', $this->order->ocr_number ?? $this->order->payment_reference],
        ];

        if ($bg) {
            $rows[] = ['Bankgiro', $bg];
        }

        return $rows;
    }

    private function membershipTypeLabel(string $type): string
    {
        return match ($type) {
            'single'  => 'Vuxen',
            'family'  => 'Familj',
            'youth'   => 'Ungdom',
            'child'   => 'Barn',
            'student' => 'Student',
            'senior'  => 'Senior',
            default   => ucfirst($type),
        };
    }
}
