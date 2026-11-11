<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 space-y-6">

    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Mina möten</h1>

    @php
        $upcoming = $meetings->filter(fn ($m) => $m->scheduled_at->isFuture());
        $past     = $meetings->filter(fn ($m) => $m->scheduled_at->isPast());
    @endphp

    @if ($meetings->isEmpty())
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-10 text-center">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Du har inga möten att visa.</p>
        </div>
    @else
        @if ($upcoming->isNotEmpty())
            <div class="space-y-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Kommande</h2>
                @foreach ($upcoming as $m)
                    <x-meeting-card :meeting="$m" />
                @endforeach
            </div>
        @endif

        @if ($past->isNotEmpty())
            <div class="space-y-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Tidigare</h2>
                @foreach ($past as $m)
                    <x-meeting-card :meeting="$m" />
                @endforeach
            </div>
        @endif
    @endif

</div>
