<x-layouts.auth>
    <div class="flex flex-col gap-6" x-data="passkeyLogin()">
        @php
            $membershipIntent = str_contains(session('url.intended', ''), 'bli-medlem');
        @endphp

        <div class="text-center">
            <h1 class="text-xl font-bold text-zinc-900 dark:text-white">
                {{ $membershipIntent ? 'Logga in för att bli medlem' : 'Logga in' }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ $membershipIntent ? 'Ange dina inloggningsuppgifter för att fortsätta anmälan' : 'Ange din e-postadress och ditt lösenord' }}
            </p>
        </div>

        @if($membershipIntent)
            <div class="flex items-center gap-3 rounded-lg border border-brand/30 bg-brand/10 px-4 py-3 text-sm dark:border-brand/20 dark:bg-brand/5">
                <x-bootstrap-icon name="person-plus-fill" class="size-4 shrink-0 text-brand-dark dark:text-brand" />
                <span class="text-zinc-700 dark:text-zinc-300">
                    Har du inget konto?
                    <a href="{{ route('register') }}" wire:navigate class="font-semibold text-complement hover:underline dark:text-complement-light">Skapa ett gratis</a>
                    — det tar under en minut.
                </span>
            </div>
        @endif

        @if (session('status'))
            <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                {{ session('status') }}
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

        <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">E-postadress</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                       placeholder="namn@exempel.se"
                       class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <label for="password" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Lösenord</label>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" wire:navigate tabindex="-1" class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                            Glömt lösenordet?
                        </a>
                    @endif
                </div>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       placeholder="Lösenord"
                       class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
            </div>

            <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                <input type="checkbox" name="remember" class="h-4 w-4 rounded border-zinc-300" />
                Kom ihåg mig
            </label>

            <button type="submit"
                    class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                Logga in
            </button>
        </form>

        <div class="relative flex items-center gap-3">
            <div class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></div>
            <span class="text-xs text-zinc-400">eller</span>
            <div class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></div>
        </div>

        {{-- Magic link --}}
        @if(session('magic_link_status'))
            <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                {{ session('magic_link_status') }}
            </div>
        @endif

        <div x-data="{ open: false }">
            <button type="button" @click="open = !open"
                    class="flex w-full items-center justify-center gap-3 rounded-lg border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">
                <x-bootstrap-icon name="envelope-open" class="size-4 shrink-0 text-zinc-500 dark:text-zinc-400" />
                Logga in med e-postlänk
            </button>
            <div x-show="open" x-cloak x-transition class="mt-3">
                <form method="POST" action="{{ route('magic-link.request') }}" class="flex gap-2">
                    @csrf
                    <input type="email" name="email" required placeholder="namn@exempel.se"
                           class="flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-complement focus:ring-1 focus:ring-complement dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                    <button type="submit"
                            class="rounded-lg bg-complement px-4 py-2 text-sm font-semibold text-white hover:bg-complement/90 dark:hover:bg-complement/80">
                        Skicka
                    </button>
                </form>
                <p class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">Vi skickar en länk till din e-postadress — klicka på den för att logga in.</p>
            </div>
        </div>

        {{-- Passkey --}}
        <div x-show="passkeyError" x-cloak
             class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
            <span x-text="passkeyError"></span>
        </div>

        <button type="button" @click="login()" :disabled="passkeyLoading"
                class="flex w-full items-center justify-center gap-3 rounded-lg border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">
            <x-bootstrap-icon name="fingerprint" class="size-4 shrink-0 text-zinc-500 dark:text-zinc-400" />
            <span x-show="!passkeyLoading">Logga in med passnyckel</span>
            <span x-show="passkeyLoading" x-cloak>Väntar på enhet…</span>
        </button>

        <a href="{{ route('auth.google') }}"
           class="flex w-full items-center justify-center gap-3 rounded-lg border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">
            <svg class="size-4 shrink-0" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
            </svg>
            Logga in med Google
        </a>

        <a href="{{ route('auth.discord') }}"
           class="flex w-full items-center justify-center gap-3 rounded-lg border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">
            <x-bootstrap-icon name="discord" class="size-4 shrink-0 text-indigo-500" />
            Logga in med Discord
        </a>

        @if (Route::has('register'))
            <p class="text-center text-sm text-zinc-600 dark:text-zinc-400">
                Har du inget konto?
                <a href="{{ route('register') }}" wire:navigate class="font-medium text-complement hover:underline dark:text-complement-light">Registrera dig</a>
            </p>
        @endif
    </div>
</x-layouts.auth>

@push('scripts')
<script type="module">
    import { startAuthentication } from 'https://cdn.jsdelivr.net/npm/@simplewebauthn/browser@13/+esm';

    window.passkeyLogin = function() {
        return {
            passkeyLoading: false,
            passkeyError: null,

            async login() {
                this.passkeyLoading = true;
                this.passkeyError   = null;

                try {
                    const optResp = await fetch('{{ route('passkey.login-options') }}', {
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    });
                    if (!optResp.ok) throw new Error('Kunde inte hämta inloggningsalternativ.');

                    const { options } = await optResp.json();
                    const credential  = await startAuthentication({ optionsJSON: options });

                    const loginResp = await fetch('{{ route('passkey.login') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify({ credential }),
                    });
                    if (!loginResp.ok) throw new Error('Inloggning med passnyckel misslyckades.');

                    const { redirect } = await loginResp.json();
                    window.location.href = redirect ?? '/dashboard';
                } catch (err) {
                    if (err.name === 'NotAllowedError') {
                        this.passkeyError = 'Inloggningen avbröts.';
                    } else {
                        this.passkeyError = err.message ?? 'Något gick fel. Försök logga in med lösenord istället.';
                    }
                } finally {
                    this.passkeyLoading = false;
                }
            },
        };
    };
</script>
@endpush
