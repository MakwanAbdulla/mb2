<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Avprenumerera — Armagedon</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-zinc-50 dark:bg-warm-dark flex items-center justify-center p-6">
    <div class="w-full max-w-md">
        <div class="mb-8 text-center">
            <img src="/armagedon-logo-light.svg" alt="Armagedon" class="mx-auto h-12 dark:hidden">
            <img src="/armagedon-logo-dark.svg" alt="Armagedon" class="mx-auto h-12 hidden dark:block">
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            @if($alreadyOptedOut)
                <div class="text-center">
                    <x-bootstrap-icon name="check-circle" class="mx-auto mb-3 size-10 text-green-500" />
                    <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Redan avprenumererad</h1>
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                        <strong>{{ $email }}</strong> är redan avregistrerad
                        @if($category)
                            från kategorin <em>{{ \App\Support\NotificationCategory::label($category) }}</em>.
                        @else
                            från våra utskick.
                        @endif
                    </p>
                </div>
            @else
                <h1 class="mb-2 text-xl font-bold text-zinc-900 dark:text-white">Avprenumerera</h1>

                @if($category)
                    <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">
                        Du är på väg att avregistrera <strong>{{ $email }}</strong> från
                        kategorin <strong>{{ \App\Support\NotificationCategory::label($category) }}</strong>.
                    </p>

                    @php $warningLevel = \App\Support\NotificationCategory::warningLevel($category); @endphp

                    @if($warningLevel === \App\Support\NotificationCategory::WARNING_STRONG)
                        <div class="mb-4 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950">
                            <x-bootstrap-icon name="exclamation-triangle-fill" class="mt-0.5 size-5 shrink-0 text-red-600 dark:text-red-400" />
                            <p class="text-sm text-red-700 dark:text-red-300">
                                {{ \App\Support\NotificationCategory::warningText($category) }}
                            </p>
                        </div>
                    @elseif($warningLevel === \App\Support\NotificationCategory::WARNING_SOFT)
                        <div class="mb-4 flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950">
                            <x-bootstrap-icon name="info-circle-fill" class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                            <p class="text-sm text-amber-700 dark:text-amber-300">
                                {{ \App\Support\NotificationCategory::warningText($category) }}
                            </p>
                        </div>
                    @endif
                @else
                    <p class="mb-6 text-sm text-zinc-500 dark:text-zinc-400">
                        Du är på väg att avregistrera <strong>{{ $email }}</strong> från alla framtida utskick.
                        @if($mailing)
                            Det här utlöstes av utskicket <em>{{ $mailing->title }}</em>.
                        @endif
                    </p>
                    <div class="mb-4 flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950">
                        <x-bootstrap-icon name="info-circle-fill" class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                        <p class="text-sm text-amber-700 dark:text-amber-300">
                            Du kan fortfarande logga in och ändra dina aviseringsinställningar om du vill ta bort specifika kategorier istället.
                        </p>
                    </div>
                @endif

                @php
                    $confirmParams = ['mailing' => $mailing?->id ?? 0, 'email' => $encodedEmail];
                    if ($category) { $confirmParams['category'] = $category; }
                @endphp

                <form method="POST"
                      action="{{ URL::signedRoute('mailing.unsubscribe.confirm', $confirmParams) }}">
                    @csrf
                    <button type="submit"
                            class="w-full rounded-xl bg-red-600 px-4 py-3 text-sm font-semibold text-white hover:bg-red-700">
                        Ja, avprenumerera mig
                    </button>
                </form>

                <p class="mt-4 text-center text-xs text-zinc-400">
                    Du kan alltid kontakta oss direkt om du vill prenumerera igen.
                </p>
            @endif
        </div>
    </div>
</body>
</html>
