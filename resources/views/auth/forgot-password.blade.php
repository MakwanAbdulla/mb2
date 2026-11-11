<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <div class="text-center">
            <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Återställ lösenord</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Ange din e-postadress så skickar vi en återställningslänk</p>
        </div>

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

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">E-postadress</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                       placeholder="namn@exempel.se"
                       class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                Skicka återställningslänk
            </button>
        </form>

        <p class="text-center text-sm text-zinc-600 dark:text-zinc-400">
            <a href="{{ route('login') }}" wire:navigate class="font-medium text-complement hover:underline dark:text-complement-light">Tillbaka till inloggning</a>
        </p>
    </div>
</x-layouts.auth>
