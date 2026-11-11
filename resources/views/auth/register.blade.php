<x-layouts.auth>
    <div class="flex flex-col gap-6">
        @php
            $membershipIntent = str_contains(session('url.intended', ''), 'bli-medlem');
        @endphp

        <div class="text-center">
            <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Skapa konto</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ $membershipIntent
                    ? 'Steg 1 av 2 — skapa ett konto, sedan fyller du i ditt medlemskap'
                    : 'Fyll i dina uppgifter nedan' }}
            </p>
        </div>

        @if($membershipIntent)
            <div class="flex items-center gap-3 rounded-lg border border-brand/30 bg-brand/10 px-4 py-3 text-sm dark:border-brand/20 dark:bg-brand/5">
                <x-bootstrap-icon name="1-circle-fill" class="size-4 shrink-0 text-brand-dark dark:text-brand" />
                <span class="text-zinc-700 dark:text-zinc-300">
                    Efter kontot är skapat går du direkt till anmälan om medlemskap.
                </span>
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('register') }}" class="flex flex-col gap-4">
            @csrf

            <div>
                <label for="name" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Namn</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required autocomplete="name"
                       placeholder="Förnamn Efternamn"
                       class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">E-postadress</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                       placeholder="namn@exempel.se"
                       class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Lösenord</label>
                <input id="password" name="password" type="password" required autocomplete="new-password"
                       placeholder="Minst 8 tecken"
                       class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Bekräfta lösenord</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                       placeholder="Upprepa lösenordet"
                       class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                Registrera
            </button>
        </form>

        <p class="text-center text-sm text-zinc-600 dark:text-zinc-400">
            Har du redan ett konto?
            <a href="{{ route('login') }}" wire:navigate class="font-medium text-complement hover:underline dark:text-complement-light">Logga in</a>
        </p>
    </div>
</x-layouts.auth>
