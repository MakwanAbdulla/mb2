<?php

namespace App\Services;

use App\Models\Accounting\Invoice;
use App\Models\Accounting\InvoiceSequence;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\Shop\Order;
use App\Models\SystemSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class InvoicePdfService
{
    /**
     * Generate and return a PDF Response for a shop Order.
     * Assigns an invoice number on first call.
     */
    public function forOrder(Order $order, bool $inline = true): Response
    {
        $this->ensureOrderInvoiceNumber($order);

        $order->load(['lines', 'lines.discountUse.discountCode']);

        $member = $order->user?->member;

        $bankgiro      = SystemSetting::get('payment.bankgiro', '');
        $familyMembers = MembershipPeriod::where('order_id', $order->id)
            ->with('member')->get()->pluck('member')->filter()->values();

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoiceNumber' => $order->invoice_number,
            'invoiceDate'   => $order->invoice_issued_at,
            'dueDate'       => $order->invoice_issued_at?->copy()->addDays(30),
            'brand'         => $order->invoice_brand ?? 'armagedon',
            'isPaid'        => $order->isPaid(),
            'paidAt'        => $order->paid_at,
            'ocrNumber'     => $order->ocr_number,
            'bankgiro'      => $bankgiro,
            'recipient'     => $this->recipientFromMember($member),
            'lines'         => $order->lines->flatMap(function ($l) {
                $gross = $l->unit_price_ore * ($l->quantity ?? 1);
                $rows  = [[
                    'description' => $l->description ?? 'Tjänst',
                    'quantity'    => $l->quantity ?? 1,
                    'unit_price'  => $l->unit_price_ore,
                    'total'       => $gross,
                ]];
                if ($l->discount_ore > 0) {
                    $code  = $l->discountUse?->discountCode?->code;
                    $rows[] = [
                        'description' => 'Rabatt' . ($code ? ' ' . $code : ''),
                        'quantity'    => null,
                        'unit_price'  => null,
                        'total'       => -$l->discount_ore,
                        'is_discount' => true,
                    ];
                }
                return $rows;
            }),
            'totalOre'      => $order->total_ore,
            'vatOre'        => 0,
            'familyMembers' => $familyMembers,
            'reference'     => $order->payment_reference,
        ])->setPaper('A4', 'portrait');

        $filename = 'faktura-' . $order->invoice_number . '.pdf';
        return $this->respond($pdf, $filename, $inline);
    }

    /**
     * Generate and return a PDF Response for a manual AR Invoice.
     */
    public function forInvoice(Invoice $invoice, bool $inline = true): Response
    {
        $invoice->load(['lines.account', 'vendor', 'customer', 'bankAccount']);

        // Resolve bankgiro: prefer the invoice's linked bank account, fall back to system setting
        $bankgiro = match ($invoice->payment_method) {
            Invoice::PM_BANKGIRO => $invoice->bankAccount?->bankgiro ?? SystemSetting::get('payment.bankgiro', ''),
            default              => SystemSetting::get('payment.bankgiro', ''),
        };

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoiceNumber' => $invoice->invoice_number ?? '–',
            'invoiceDate'   => $invoice->invoice_date,
            'dueDate'       => $invoice->due_date,
            'brand'         => 'armagedon',
            'isPaid'        => $invoice->isPaid(),
            'paidAt'        => $invoice->paid_at,
            'ocrNumber'     => $invoice->ocr_number,
            'bankgiro'      => $bankgiro,
            'recipient'     => $this->recipientFromVendorOrCustomer($invoice),
            'lines'         => $invoice->lines->map(fn ($l) => [
                'description' => $l->description,
                'quantity'    => 1,
                'unit_price'  => $l->amount_ex_vat,
                'total'       => $l->amount_ex_vat + $l->vat_amount,
            ]),
            'totalOre'      => $invoice->total,
            'vatOre'        => $invoice->vat_amount,
            'familyMembers' => collect(),
            'reference'     => $invoice->invoice_number,
        ])->setPaper('A4', 'portrait');

        $filename = 'faktura-' . ($invoice->invoice_number ?? $invoice->id) . '.pdf';
        return $this->respond($pdf, $filename, $inline);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function ensureOrderInvoiceNumber(Order $order): void
    {
        if ($order->invoice_number) return;

        $number = InvoiceSequence::next(InvoiceSequence::SERIES_SHOP, now()->year);

        $order->updateQuietly([
            'invoice_number'    => $number,
            'invoice_issued_at' => Carbon::now(),
        ]);
    }

    private function recipientFromMember(?Member $member): array
    {
        if (! $member) return [];
        return [
            'name'        => $member->full_name,
            'address'     => $member->address ?? '',
            'postal_code' => $member->postal_code ?? '',
            'city'        => $member->city ?? '',
            'email'       => $member->email ?? '',
        ];
    }

    private function recipientFromVendorOrCustomer(Invoice $invoice): array
    {
        // AR invoice goes to a customer (external party)
        if ($invoice->customer) {
            return [
                'name'  => $invoice->customer->name,
                'email' => $invoice->customer->email ?? '',
            ];
        }
        return [];
    }

    private function respond(\Barryvdh\DomPDF\PDF $pdf, string $filename, bool $inline): Response
    {
        $disposition = $inline ? 'inline' : 'attachment';
        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
        ]);
    }
}
