<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Återkommande bokningar</h1>
        <a href="{{ route('booking.admin.recurring-bookings.create') }}" wire:navigate
           class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
            + Ny serie
        </a>
    </div>

    @if ($series->isEmpty())
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-10 text-center">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Inga återkommande bokningar ännu.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Titel</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Rum</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Frekvens</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Tid</th>
                        <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-300">Framtida</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                    @foreach ($series as $s)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">
                                {{ $s->title }}
                                @if ($s->last_conflicts)
                                    <span title="{{ count($s->last_conflicts) }} krockar vid senaste generering"
                                          class="ml-1 inline-block size-2 rounded-full bg-amber-400"></span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $s->resourceNames() }}</td>
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400 text-xs">{{ $s->frequencyLabel() }}</td>
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400 tabular-nums text-xs">
                                {{ substr($s->start_time, 0, 5) }}–{{ substr($s->end_time, 0, 5) }}
                            </td>
                            <td class="px-4 py-3 text-center tabular-nums text-zinc-600 dark:text-zinc-300">{{ $s->future_count }}</td>
                            <td class="px-4 py-3 text-right space-x-3">
                                <a href="{{ route('booking.admin.recurring-bookings.edit', ['redigera' => $s->id]) }}" wire:navigate
                                   class="text-xs text-complement dark:text-complement-light hover:underline">Redigera</a>
                                <button wire:click="delete({{ $s->id }})"
                                        wire:confirm="Ta bort serien '{{ $s->title }}' och alla framtida tillfällen?"
                                        class="text-xs text-red-600 dark:text-red-400 hover:underline">Ta bort</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</div>
