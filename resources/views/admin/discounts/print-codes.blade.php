<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rabattkoder – {{ $discount->name }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 11px;
            color: #1a1a1a;
            background: #fff;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }

        .page-header {
            padding: 12px 16px;
            border-bottom: 2px solid #fddd10;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .page-header h1 { font-size: 15px; font-weight: 700; }
        .page-header p { font-size: 10px; color: #666; margin-top: 2px; }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 12px;
            padding: 0 16px 16px;
        }

        /* ------------------------------------------------------------------ */
        /* Individual voucher card                                              */
        /* ------------------------------------------------------------------ */
        .card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px 10px 10px;
            text-align: center;
            page-break-inside: avoid;
            break-inside: avoid;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }

        /* Logo */
        .logo-wrap {
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            padding-bottom: 6px;
        }
        .logo-wrap img {
            max-width: 120px;
            max-height: 40px;
            object-fit: contain;
        }

        /* QR — member self-service (large, prominent) */
        .qr-wrap {
            width: 120px;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .qr-wrap svg { width: 120px; height: 120px; }

        .scan-hint {
            font-size: 9px;
            color: #888;
            line-height: 1.3;
        }

        /* Divider */
        .divider {
            width: 100%;
            border: none;
            border-top: 1px dashed #ddd;
            margin: 2px 0;
        }

        /* Code128 barcode — staff scanner station (smaller, secondary) */
        .barcode-wrap {
            width: 100%;
        }
        .barcode-wrap svg {
            width: 100%;
            height: auto;
            max-height: 32px;
        }

        .code-text {
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.06em;
        }

        .discount-name {
            font-size: 9px;
            font-weight: 600;
            color: #444;
        }

        .target-label {
            font-size: 9px;
            color: #888;
        }

        .batch-label {
            font-size: 8px;
            color: #aaa;
            font-style: italic;
        }

        /* Redeemed state */
        .card-redeemed {
            opacity: 0.4;
        }
        .card-redeemed .code-text {
            text-decoration: line-through;
        }
        .redeemed-badge {
            display: inline-block;
            background: #f0f0f0;
            color: #999;
            font-size: 8px;
            padding: 1px 6px;
            border-radius: 20px;
        }

        @media print {
            .no-print { display: none !important; }
            body { font-size: 10px; }
            .grid { gap: 10px; }
        }
    </style>
</head>
<body>

<div class="page-header">
    <div>
        <h1>{{ $discount->name }}</h1>
        <p>
            {{ $codesWithBarcodes->count() }} koder
            &nbsp;·&nbsp; Skrivet ut {{ now()->format('d/m Y H:i') }}
        </p>
    </div>
    <button onclick="window.print()" class="no-print"
            style="padding: 6px 14px; background: #fddd10; border: none; border-radius: 5px; font-weight: 600; cursor: pointer; font-size: 12px;">
        Skriv ut
    </button>
</div>

<div class="grid">
    @foreach($codesWithBarcodes as $item)
        @php
            $code       = $item['model'];
            $isRedeemed = $code->isUsedUp();
        @endphp
        <div class="card {{ $isRedeemed ? 'card-redeemed' : '' }}">

            {{-- Logo --}}
            @if($discount->logo !== 'none')
                <div class="logo-wrap">
                    <img src="{{ asset($discount->logo === 'bsk' ? 'bsk-logo.png' : 'armagedon-logo.png') }}"
                         alt="{{ $discount->logo === 'bsk' ? 'Borås Spelkonvent' : 'Armagedon' }}">
                </div>
            @endif

            {{-- QR code — member scans with phone --}}
            <div class="qr-wrap">
                {!! $item['qr'] !!}
            </div>

            @if($item['ctaUrl'])
                <p class="scan-hint">Skanna med mobilen<br>för att registrera dig</p>
            @else
                <p class="scan-hint">Skanna vid<br>skanningsstationen</p>
            @endif

            <hr class="divider">

            {{-- Code text --}}
            <div class="code-text">{{ $code->code }}</div>

            {{-- Discount info --}}
            <div class="target-label">{{ $item['target'] }}</div>

            @if($code->batch_label)
                <div class="batch-label">{{ $code->batch_label }}</div>
            @endif

            <hr class="divider">

            {{-- Code128 barcode — staff scanner --}}
            <div class="barcode-wrap">
                {!! $item['barcode'] !!}
            </div>

            @if($isRedeemed)
                <div class="redeemed-badge">Inlöst {{ $code->redeemed_at->format('d/m Y') }}</div>
            @endif

        </div>
    @endforeach
</div>

</body>
</html>
