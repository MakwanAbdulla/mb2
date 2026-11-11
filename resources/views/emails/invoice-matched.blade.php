<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: sans-serif; color: #1c1a1b; background: #f8f8f8; margin: 0; padding: 0; }
        .wrapper { max-width: 560px; margin: 32px auto; background: #fff; border-radius: 8px; border: 1px solid #e4e4e7; overflow: hidden; }
        .header { background: #fddd10; padding: 20px 28px; }
        .header h1 { margin: 0; font-size: 18px; color: #231f20; }
        .body { padding: 24px 28px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f1f1f1; font-size: 14px; }
        .row:last-child { border-bottom: none; }
        .label { color: #71717a; }
        .value { font-weight: 500; }
        .amount { font-size: 22px; font-weight: 700; color: #231f20; margin: 16px 0; }
        .btn { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #fddd10; color: #231f20; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; }
        .footer { padding: 16px 28px; font-size: 12px; color: #a1a1aa; border-top: 1px solid #f1f1f1; }
        .badge-in  { background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
        .badge-out { background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <h1>
            @if ($invoice->isPayable())
                Leverantörsfaktura betald
            @else
                Betalning mottagen
            @endif
        </h1>
    </div>
    <div class="body">

        <p style="font-size:14px; color:#3f3f46; margin-top:0">
            @if ($invoice->isPayable())
                En utgående betalning i kontoutdraget har matchats mot
                en leverantörsfaktura och markerats som betald.
            @else
                En inkommande betalning har matchats mot en utställd faktura
                och markerats som betald.
            @endif
        </p>

        <div class="amount">
            {{ number_format(abs($transaction->amount) / 100, 2, ',', ' ') }} kr
            @if ($transaction->amount > 0)
                <span class="badge-in">Inbetalning</span>
            @else
                <span class="badge-out">Utbetalning</span>
            @endif
        </div>

        <div style="border:1px solid #e4e4e7; border-radius:6px; overflow:hidden; margin-bottom:16px">
            <div class="row" style="padding: 8px 12px">
                <span class="label">Fakturanummer</span>
                <span class="value">{{ $invoice->invoice_number ?? "#{$invoice->id}" }}</span>
            </div>
            @if ($invoice->isPayable() && $invoice->vendor)
            <div class="row" style="padding: 8px 12px">
                <span class="label">Leverantör</span>
                <span class="value">{{ $invoice->vendor->name }}</span>
            </div>
            @elseif ($invoice->isReceivable() && $invoice->customer)
            <div class="row" style="padding: 8px 12px">
                <span class="label">Kund</span>
                <span class="value">{{ $invoice->customer->name }}</span>
            </div>
            @endif
            <div class="row" style="padding: 8px 12px">
                <span class="label">Förfallodag</span>
                <span class="value">{{ $invoice->due_date?->format('d/m Y') ?? '–' }}</span>
            </div>
            <div class="row" style="padding: 8px 12px">
                <span class="label">Betald</span>
                <span class="value">{{ $invoice->paid_at?->format('d/m Y') ?? now()->format('d/m Y') }}</span>
            </div>
        </div>

        <div style="border:1px solid #e4e4e7; border-radius:6px; overflow:hidden">
            <div class="row" style="padding: 8px 12px; background:#fafafa">
                <span class="label" style="font-weight:600">Banktransaktion</span>
            </div>
            <div class="row" style="padding: 8px 12px">
                <span class="label">Datum</span>
                <span class="value font-mono">{{ $transaction->booking_date?->format('d/m Y') ?? $transaction->transaction_date?->format('d/m Y') ?? '–' }}</span>
            </div>
            <div class="row" style="padding: 8px 12px">
                <span class="label">Text</span>
                <span class="value">{{ $transaction->description }}</span>
            </div>
            @if ($transaction->reference)
            <div class="row" style="padding: 8px 12px">
                <span class="label">Referens</span>
                <span class="value" style="font-family:monospace">{{ $transaction->reference }}</span>
            </div>
            @endif
        </div>

        @if ($invoice->isPayable())
            <a href="{{ route('admin.accounting.invoices.payable.show', $invoice) }}" class="btn">
                Öppna fakturan →
            </a>
        @else
            <a href="{{ route('admin.accounting.invoices.receivable.show', $invoice) }}" class="btn">
                Öppna fakturan →
            </a>
        @endif

        @if ($invoice->documents->isEmpty())
            <p style="font-size:12px; color:#a1a1aa; margin-top:12px">
                Ingen fakturakopia uppladdad — bifoga ett dokument på fakturasidan om det behövs.
            </p>
        @endif
    </div>
    <div class="footer">
        Armagedon · Bokföring · Automatisk avisering vid fakturamatchning
    </div>
</div>
</body>
</html>
