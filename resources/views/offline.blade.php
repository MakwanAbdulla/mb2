<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingen anslutning — Armagedon Membrum</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, sans-serif;
            background: #231f20;
            color: #a1a1aa;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100dvh;
            padding: 24px;
        }
        .card { text-align: center; max-width: 360px; }
        .icon { width: 64px; height: 64px; margin: 0 auto 24px; color: #52525b; }
        h1 { font-size: 1.25rem; font-weight: 700; color: #f4f4f5; margin: 0 0 8px; }
        p  { font-size: 0.9rem; line-height: 1.6; margin: 0 0 24px; }
        a  {
            display: inline-block;
            padding: 10px 20px;
            background: #fddd10;
            color: #231f20;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 700;
        }
        a:hover { background: #fee81b; }
    </style>
</head>
<body>
    <div class="card">
        <svg class="icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M3 3l18 18M8.111 8.111A5.25 5.25 0 0 0 3.75 12a5.25 5.25 0 0 0 5.25 5.25h8.25m2.25-2.25A5.25 5.25 0 0 0 12 6.75c-.725 0-1.417.147-2.045.412M15 15l-6-6" />
        </svg>
        <h1>Ingen internetanslutning</h1>
        <p>Det går inte att nå Membrum just nu. Kontrollera din anslutning och försök igen.</p>
        <a href="/" onclick="location.reload(); return false;">Försök igen</a>
    </div>
</body>
</html>
