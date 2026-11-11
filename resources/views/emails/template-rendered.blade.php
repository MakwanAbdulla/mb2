<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; color: #231f20; line-height: 1.65; max-width: 580px; margin: 0 auto; padding: 0; background: #f4f4f5; }
        .wrapper { background: #ffffff; border-radius: 8px; margin: 24px auto; overflow: hidden; }
        .header { background: #231f20; padding: 20px 28px; }
        .header img { height: 36px; display: block; }
        .header-text { color: #fddd10; font-size: 13px; font-weight: 600; letter-spacing: 0.04em; }
        .body { padding: 28px 28px 8px; }
        .body p { margin: 0 0 16px; font-size: 15px; }
        .details-table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 14px; }
        .details-table td { padding: 8px 10px; border-bottom: 1px solid #e4e4e7; vertical-align: top; }
        .details-table td:first-child { font-weight: 600; width: 40%; color: #52525b; white-space: nowrap; }
        .btn { display: inline-block; background: #fddd10; color: #231f20 !important; padding: 12px 24px; border-radius: 6px; font-weight: 700; font-size: 15px; text-decoration: none; margin: 8px 0 20px; }
        .divider { border: none; border-top: 1px solid #e4e4e7; margin: 20px 0; }
        .footer { padding: 16px 28px 24px; font-size: 12px; color: #a1a1aa; }
        .footer a { color: #6b7280; }
    </style>
</head>
<body>
<div class="wrapper">

    <div class="header">
        <span class="header-text">ARMAGEDON</span>
    </div>

    <div class="body">
        {!! $bodyHtml !!}

        @if(! empty($detailsRows))
            <table class="details-table">
                @foreach($detailsRows as $row)
                    <tr>
                        <td>{{ $row[0] }}</td>
                        <td>{{ $row[1] }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @if(! empty($ctaUrl) && ! empty($ctaLabel))
            <a href="{{ $ctaUrl }}" class="btn">{{ $ctaLabel }}</a>
        @endif

        @if(! empty($qrUrl))
            <div style="margin: 20px 0; text-align: center;">
                <p style="margin: 0 0 8px; font-size: 13px; color: #71717a; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;">Incheckning</p>
                <img src="{{ $qrUrl }}" alt="QR-kod för incheckning" width="150" height="150"
                     style="display:inline-block; border: 1px solid #e4e4e7; border-radius: 8px;" />
                <p style="margin: 8px 0 0; font-size: 12px; color: #a1a1aa;">Visa denna QR-kod för personalen vid incheckning.</p>
            </div>
        @endif
    </div>

    <hr class="divider">

    @isset($trackingToken)
    <img src="{{ route('mail.track.open', $trackingToken) }}"
         width="1" height="1" alt=""
         style="display:block;width:1px;height:1px;border:0;margin:0;padding:0;" />
    @endisset

    <div class="footer">
        Armagedon – Sällskapsspelföreningen i Borås<br>
        <a href="mailto:info@armagedon.se">info@armagedon.se</a>
        &nbsp;·&nbsp;
        <a href="{{ url('/') }}">armagedon.se</a>
    </div>

</div>
</body>
</html>
