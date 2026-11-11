<!DOCTYPE html>
<html lang="sv">
<head>
<meta charset="UTF-8">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10pt; color: #1a1a1a; line-height: 1.5; }

    .page { padding: 30px 36px; }

    /* Header */
    .header { display: table; width: 100%; border-bottom: 3px solid #231f20; padding-bottom: 14px; margin-bottom: 20px; }
    .header-left { display: table-cell; vertical-align: middle; width: 55%; }
    .header-right { display: table-cell; text-align: right; vertical-align: top; }
    .logo { max-height: 52px; max-width: 200px; }
    .org-sub { font-size: 8pt; color: #555; margin-top: 4px; }
    .doc-title { font-size: 20pt; font-weight: bold; color: #231f20; text-transform: uppercase; }
    .invoice-number { font-size: 9pt; color: #555; margin-top: 4px; }

    /* Two-column meta */
    .meta { display: table; width: 100%; margin-bottom: 24px; }
    .meta-col { display: table-cell; width: 50%; vertical-align: top; }
    .meta-label { font-size: 7.5pt; text-transform: uppercase; color: #888; letter-spacing: 0.05em; margin-bottom: 1px; }
    .meta-value { font-size: 9.5pt; font-weight: bold; }
    .meta-block { margin-bottom: 12px; }

    /* Recipient */
    .recipient { border-left: 3px solid #fddd10; padding: 8px 12px; background: #fafafa; margin-bottom: 24px; }
    .recipient .name { font-size: 11pt; font-weight: bold; }
    .recipient .detail { font-size: 8.5pt; color: #555; }

    /* Line items table */
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    table.items th { background: #231f20; color: #fff; font-size: 8pt; text-transform: uppercase;
                     letter-spacing: 0.05em; padding: 6px 8px; text-align: left; }
    table.items th.right { text-align: right; }
    table.items td { padding: 7px 8px; border-bottom: 1px solid #e8e8e8; font-size: 9.5pt; vertical-align: top; }
    table.items td.right { text-align: right; }
    table.items tr:last-child td { border-bottom: none; }

    /* Total row */
    .total-row { display: table; width: 100%; margin-bottom: 20px; }
    .total-spacer { display: table-cell; width: 55%; }
    .total-box { display: table-cell; width: 45%; }
    table.total { width: 100%; border-collapse: collapse; }
    table.total td { padding: 5px 8px; font-size: 9.5pt; }
    table.total td.right { text-align: right; }
    table.total tr.grand td { font-weight: bold; font-size: 11pt; border-top: 2px solid #231f20; padding-top: 8px; }

    /* Payment box */
    .payment-box { background: #fffbea; border: 2px solid #fddd10; border-radius: 4px; padding: 14px 16px; margin-bottom: 20px; }
    .payment-box h3 { font-size: 9pt; text-transform: uppercase; color: #555; letter-spacing: 0.06em; margin-bottom: 8px; }
    table.pay { width: 100%; border-collapse: collapse; }
    table.pay td { padding: 3px 0; font-size: 9.5pt; }
    table.pay td.label { color: #666; width: 35%; }
    table.pay td.value { font-weight: bold; }

    /* Status stamp */
    .stamp { display: inline-block; border: 3px solid #22c55e; border-radius: 4px;
             color: #16a34a; font-size: 14pt; font-weight: bold; padding: 4px 10px;
             text-transform: uppercase; letter-spacing: 0.06em; transform: rotate(-5deg);
             position: absolute; top: 30px; right: 36px; opacity: 0.85; }
    .stamp.pending { border-color: #f59e0b; color: #b45309; }

    /* Footer */
    .footer { border-top: 1px solid #ddd; padding-top: 10px; margin-top: 16px;
              font-size: 7.5pt; color: #999; text-align: center; }
</style>
</head>
<body>
<div class="page">

    {{-- Paid stamp --}}
    @if($isPaid)
        <div class="stamp">Betald</div>
    @else
        <div class="stamp pending">Obetald</div>
    @endif

    {{-- Header: logo + invoice title --}}
    <div class="header">
        <div class="header-left">
            @php
                $logoFile = match($brand) {
                    'bsk'   => public_path('bsk-logo.png'),
                    default => public_path('armagedon-logo.png'),
                };
                $logoB64 = file_exists($logoFile)
                    ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoFile))
                    : null;
            @endphp

            @if($logoB64)
                <img src="{{ $logoB64 }}" class="logo" alt="Logo">
            @else
                <span style="font-size:18pt;font-weight:bold;">
                    {{ $brand === 'bsk' ? 'BORÅS SPELKONVENT' : 'ARMAGEDON' }}
                </span>
            @endif

            {{-- Always show Armagedon as legal entity --}}
            @if($brand === 'bsk')
                <div class="org-sub">Arrangerat av Armagedon – Sällskapsspelföreningen i Borås</div>
            @endif
            <div class="org-sub">Org.nr 802401-5678 &nbsp;·&nbsp; info@armagedon.se &nbsp;·&nbsp; armagedon.se</div>
        </div>
        <div class="header-right">
            <div class="doc-title">Faktura</div>
            <div class="invoice-number">{{ $invoiceNumber }}</div>
        </div>
    </div>

    {{-- Meta --}}
    <div class="meta">
        <div class="meta-col">
            <div class="meta-block">
                <div class="meta-label">Fakturadatum</div>
                <div class="meta-value">{{ $invoiceDate?->format('Y-m-d') ?? '–' }}</div>
            </div>
            <div class="meta-block">
                <div class="meta-label">Förfallodatum</div>
                <div class="meta-value">{{ $dueDate?->format('Y-m-d') ?? '–' }}</div>
            </div>
            @if($isPaid && $paidAt)
            <div class="meta-block">
                <div class="meta-label">Betalad</div>
                <div class="meta-value" style="color:#16a34a;">{{ $paidAt->format('Y-m-d') }}</div>
            </div>
            @endif
        </div>
        <div class="meta-col">
            @if($ocrNumber)
            <div class="meta-block">
                <div class="meta-label">OCR-nummer</div>
                <div class="meta-value" style="font-size:12pt;letter-spacing:0.05em;">{{ $ocrNumber }}</div>
            </div>
            @endif
            @if($reference && $reference !== $invoiceNumber)
            <div class="meta-block">
                <div class="meta-label">Er referens</div>
                <div class="meta-value">{{ $reference }}</div>
            </div>
            @endif
        </div>
    </div>

    {{-- Recipient --}}
    @if(! empty($recipient['name']))
    <div class="recipient">
        <div class="name">{{ $recipient['name'] }}</div>
        @if(! empty($recipient['address']))
        <div class="detail">{{ $recipient['address'] }}</div>
        <div class="detail">{{ $recipient['postal_code'] }} {{ $recipient['city'] }}</div>
        @endif
        @if(! empty($recipient['email']))
        <div class="detail">{{ $recipient['email'] }}</div>
        @endif
    </div>
    @endif

    {{-- Line items --}}
    <table class="items">
        <thead>
            <tr>
                <th>Beskrivning</th>
                <th class="right" style="width:60px;">Antal</th>
                <th class="right" style="width:100px;">À-pris</th>
                <th class="right" style="width:100px;">Belopp</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lines as $line)
            @if(!empty($line['is_discount']))
            <tr style="color:#2d7a2d;">
                <td style="font-style:italic;padding-left:16px;">{{ $line['description'] }}</td>
                <td class="right"></td>
                <td class="right"></td>
                <td class="right" style="font-style:italic;">{{ number_format($line['total'] / 100, 2, ',', ' ') }} kr</td>
            </tr>
            @else
            <tr>
                <td>{{ $line['description'] }}</td>
                <td class="right">{{ $line['quantity'] }}</td>
                <td class="right">{{ number_format($line['unit_price'] / 100, 2, ',', ' ') }} kr</td>
                <td class="right">{{ number_format($line['total'] / 100, 2, ',', ' ') }} kr</td>
            </tr>
            @endif
            @endforeach
        </tbody>
    </table>

    {{-- Family members if applicable --}}
    @if(isset($familyMembers) && $familyMembers->count() > 1)
    <div style="background:#f8f8f8;border:1px solid #e0e0e0;border-radius:3px;padding:10px 12px;margin-bottom:16px;font-size:9pt;">
        <div style="font-size:7.5pt;text-transform:uppercase;color:#888;letter-spacing:0.05em;margin-bottom:6px;">Fakturan avser följande familjemedlemmar</div>
        <table style="width:100%;border-collapse:collapse;">
            @foreach($familyMembers as $fm)
            <tr>
                <td style="padding:2px 0;width:50%;">{{ $fm->full_name }}</td>
                <td style="padding:2px 0;color:#555;font-size:8.5pt;">{{ $fm->email ?? '' }}</td>
            </tr>
            @endforeach
        </table>
    </div>
    @endif

    {{-- Totals --}}
    <div class="total-row">
        <div class="total-spacer"></div>
        <div class="total-box">
            <table class="total">
                <tr>
                    <td>Delsumma</td>
                    <td class="right">{{ number_format(($totalOre - $vatOre) / 100, 2, ',', ' ') }} kr</td>
                </tr>
                <tr>
                    <td>Moms</td>
                    <td class="right">{{ number_format($vatOre / 100, 2, ',', ' ') }} kr</td>
                </tr>
                <tr class="grand">
                    <td>Att betala</td>
                    <td class="right">{{ number_format($totalOre / 100, 2, ',', ' ') }} kr</td>
                </tr>
            </table>
        </div>
    </div>

    {{-- Payment instructions (only if unpaid) --}}
    @if(! $isPaid)
    <div class="payment-box">
        <h3>Betalningsinformation</h3>
        <table class="pay">
            <tr>
                <td class="label">Belopp</td>
                <td class="value">{{ number_format($totalOre / 100, 2, ',', ' ') }} kr</td>
            </tr>
            @if($ocrNumber)
            <tr>
                <td class="label">OCR-nummer</td>
                <td class="value" style="font-size:11pt;letter-spacing:0.04em;">{{ $ocrNumber }}</td>
            </tr>
            @endif
            @if($bankgiro)
            <tr>
                <td class="label">Bankgiro</td>
                <td class="value">{{ $bankgiro }}</td>
            </tr>
            @endif
            @if($dueDate)
            <tr>
                <td class="label">Förfaller</td>
                <td class="value">{{ $dueDate->format('Y-m-d') }}</td>
            </tr>
            @endif
        </table>
        @if($ocrNumber)
        <div style="margin-top:8px;font-size:8pt;color:#777;">
            Ange OCR-numret exakt som betalningsreferens för att betalningen registreras automatiskt.
        </div>
        @endif
    </div>
    @endif

    {{-- Footer --}}
    <div class="footer">
        Armagedon – Sällskapsspelföreningen i Borås &nbsp;·&nbsp; Org.nr 802401-5678 &nbsp;·&nbsp; info@armagedon.se<br>
        Fakturanummer {{ $invoiceNumber }}
    </div>

</div>
</body>
</html>
