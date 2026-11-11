<!DOCTYPE html>
<html lang="sv" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Underhåll — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="font-sans antialiased bg-warm-dark text-white">
    <div class="min-h-screen flex flex-col items-center justify-center px-4 text-center">
        <img src="/armagedon-logo-dark.svg" alt="{{ config('app.name') }}" class="h-16 w-auto mb-8 opacity-90">
        <h1 class="text-xl font-semibold text-white">Systemet underhålls just nu</h1>
        <p class="mt-2 text-zinc-400 text-sm">Vi är strax tillbaka. Försök igen om en stund.</p>
    </div>
</body>
</html>
