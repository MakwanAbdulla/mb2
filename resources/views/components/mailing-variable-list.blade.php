<div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
    <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Tillgängliga variabler</p>
    <div class="space-y-1.5 text-sm">
        @foreach([
            ['{{fornamn}}',        'Mottagarens förnamn'],
            ['{{efternamn}}',      'Mottagarens efternamn'],
            ['{{namn}}',           'För- och efternamn'],
            ['{{email}}',          'E-postadress'],
            ['{{medlemsnummer}}',  'Medlemsnummer'],
            ['{{medlemskapstyp}}', 'Typ av medlemskap'],
            ['{{foreningsnamn}}',  'Föreningens namn'],
            ['{{datum}}',          'Dagens datum'],
            ['{{ar}}',             'Innevarande år'],
            ['{{avprenumerera}}',  'Länk för att avprenumerera'],
        ] as [$var, $desc])
            <div x-data
                 @click="navigator.clipboard?.writeText('{{ $var }}').then(() => $dispatch('copied', '{{ $var }}'))"
                 class="flex cursor-pointer items-center justify-between rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                <code class="font-mono text-xs text-violet-700 dark:text-violet-400">{{ $var }}</code>
                <span class="ml-2 truncate text-xs text-zinc-400">{{ $desc }}</span>
            </div>
        @endforeach
    </div>
    <p class="mt-3 text-xs text-zinc-400 dark:text-zinc-500">Klicka för att kopiera. Klistra in i ämne eller brödtext.</p>
</div>
