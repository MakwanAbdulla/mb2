<!DOCTYPE html>
<html lang="sv">
<head>
    @include('partials.head')
</head>
<body class="flex min-h-screen flex-col items-center justify-center bg-zinc-50 p-4 dark:bg-warm-dark">
    <div class="mb-8">
        <img src="{{ asset('armagedon-logo-light.svg') }}" alt="{{ config('app.name') }}" class="h-16">
    </div>

    <div class="w-full max-w-sm">
        <div class="flex flex-col gap-6">
            <div class="text-center">
                <h1 class="text-xl font-bold text-zinc-900">Kioskläge</h1>
                <p class="mt-1 text-sm text-zinc-500">Logga in med kioskontot</p>
            </div>

            @if ($errors->any())
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                    <ul class="list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('kiosk.login.post') }}" class="flex flex-col gap-4">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-medium text-zinc-700">E-postadress</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                           autofocus
                           class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20" />
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-zinc-700">Lösenord</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password"
                           class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20" />
                </div>

                <button type="submit"
                        class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                    Logga in
                </button>
            </form>
        </div>
    </div>

    @livewireScripts
</body>
</html>
