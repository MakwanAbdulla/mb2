<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Avprenumererad — Armagedon</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-zinc-50 dark:bg-warm-dark flex items-center justify-center p-6">
    <div class="w-full max-w-md">
        <div class="mb-8 text-center">
            <img src="/armagedon-logo-light.svg" alt="Armagedon" class="mx-auto h-12 dark:hidden">
            <img src="/armagedon-logo-dark.svg" alt="Armagedon" class="mx-auto h-12 hidden dark:block">
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-white p-8 text-center shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <x-bootstrap-icon name="check-circle-fill" class="mx-auto mb-3 size-12 text-green-500" />
            <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Du är avprenumererad</h1>
            @if($category)
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    <strong>{{ $email }}</strong> får inte längre utskick i kategorin
                    <strong>{{ \App\Support\NotificationCategory::label($category) }}</strong>.
                </p>
                <p class="mt-4 text-xs text-zinc-400">
                    Övriga utskick, inklusive viktiga systemmeddelanden, skickas fortfarande.
                </p>
            @else
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    <strong>{{ $email }}</strong> kommer inte längre att få e-postutskick från oss.
                </p>
                <p class="mt-4 text-xs text-zinc-400">
                    Viktiga meddelanden om ditt konto kan fortfarande skickas.
                </p>
            @endif
        </div>
    </div>
</body>
</html>
