<?php

namespace App\Http\Controllers;

use App\Models\Accounting\Invoice;
use App\Models\Shop\Order;
use App\Services\InvoicePdfService;
use Illuminate\Http\Response;

class InvoiceController extends Controller
{
    public function __construct(private InvoicePdfService $pdf) {}

    // ── Shop orders ───────────────────────────────────────────────────────────

    public function view(Order $order): Response
    {
        $this->authorizeOrder($order);
        return $this->pdf->forOrder($order, inline: true);
    }

    public function download(Order $order): Response
    {
        $this->authorizeOrder($order);
        return $this->pdf->forOrder($order, inline: false);
    }

    // ── Manual AR invoices ────────────────────────────────────────────────────

    public function viewArInvoice(Invoice $invoice): Response
    {
        abort_unless(auth()->user()->canAny(['accounting.view', 'accounting.manage']), 403);
        abort_unless($invoice->isReceivable(), 404);

        return $this->pdf->forInvoice($invoice, inline: true);
    }

    public function downloadArInvoice(Invoice $invoice): Response
    {
        abort_unless(auth()->user()->canAny(['accounting.view', 'accounting.manage']), 403);
        abort_unless($invoice->isReceivable(), 404);

        return $this->pdf->forInvoice($invoice, inline: false);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function authorizeOrder(Order $order): void
    {
        $user = auth()->user();

        if (! $user->hasPermissionTo('members.manage') && $order->user_id !== $user->id) {
            abort(403);
        }
    }
}
