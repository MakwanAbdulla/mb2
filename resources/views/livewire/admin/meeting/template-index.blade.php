<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('admin.meetings.index') }}" wire:navigate
               class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Alla möten</a>
            <h1 class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">Mötesmallar</h1>
        </div>
        <a href="{{ route('admin.meetings.templates.create') }}" wire:navigate
           class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
            + Ny mall
        </a>
    </div>

    @if ($templates->isEmpty())
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-10 text-center">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Inga mallar ännu. Skapa en för att snabba upp återkommande möten.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Namn</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Typ</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Sektion</th>
                        <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-300">Punkter</th>
                        <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-300">Möten</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                    @foreach ($templates as $t)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">{{ $t->name }}</td>
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400 text-xs">{{ $t->typeLabel() }}</td>
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400 text-xs">{{ $t->section?->name ?? 'Global' }}</td>
                            <td class="px-4 py-3 text-center tabular-nums text-zinc-600 dark:text-zinc-300">{{ $t->items_count }}</td>
                            <td class="px-4 py-3 text-center tabular-nums text-zinc-600 dark:text-zinc-300">{{ $t->meetings_count }}</td>
                            <td class="px-4 py-3 text-right space-x-3">
                                <a href="{{ route('admin.meetings.templates.edit', $t) }}" wire:navigate
                                   class="text-xs text-complement dark:text-complement-light hover:underline">Redigera</a>
                                @if ($t->meetings_count === 0)
                                    <button wire:click="delete({{ $t->id }})"
                                            wire:confirm="Ta bort mallen '{{ $t->name }}'?"
                                            class="text-xs text-red-600 dark:text-red-400 hover:underline">Ta bort</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</div>
