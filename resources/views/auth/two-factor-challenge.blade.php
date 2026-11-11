<x-layouts.auth>
    <div class="flex flex-col gap-6" x-data="{ recovery: false }">

        <div class="text-center">
            <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Tvåfaktorsverifiering</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400" x-show="!recovery">
                Ange engångskoden från din autentiseringsapp.
            </p>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400" x-show="recovery" x-cloak>
                Ange en av dina återställningskoder.
            </p>
        </div>

        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('two-factor.login.store') }}">
            @csrf

            <div x-show="!recovery" class="flex flex-col gap-4">
                <div>
                    <label for="code" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                        Engångskod
                    </label>
                    <input id="code" name="code" type="text" inputmode="numeric"
                           autofocus autocomplete="one-time-code"
                           class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-complement focus:ring-1 focus:ring-complement dark:border-zinc-600 dark:bg-zinc-800 dark:text-white text-center tracking-widest text-lg"
                           placeholder="123 456" />
                </div>
                <button type="submit"
                        class="w-full rounded-lg bg-complement px-4 py-2.5 text-sm font-semibold text-white hover:bg-complement/90 transition-colors dark:bg-complement dark:hover:bg-complement/80">
                    Verifiera
                </button>
            </div>

            <div x-show="recovery" x-cloak class="flex flex-col gap-4">
                <div>
                    <label for="recovery_code" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                        Återställningskod
                    </label>
                    <input id="recovery_code" name="recovery_code" type="text"
                           autocomplete="one-time-code"
                           class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-complement focus:ring-1 focus:ring-complement dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                           placeholder="xxxx-xxxx-xxxx-xxxx" />
                </div>
                <button type="submit"
                        class="w-full rounded-lg bg-complement px-4 py-2.5 text-sm font-semibold text-white hover:bg-complement/90 transition-colors dark:bg-complement dark:hover:bg-complement/80">
                    Verifiera med återställningskod
                </button>
            </div>
        </form>

        <button type="button" @click="recovery = !recovery"
                class="text-center text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 transition-colors">
            <span x-show="!recovery">Använd återställningskod istället</span>
            <span x-show="recovery" x-cloak>Använd engångskod istället</span>
        </button>

    </div>
</x-layouts.auth>
