<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Aktiviteter</h1>
        @if (! $showForm)
            <button wire:click="create"
                    class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                + Ny aktivitet
            </button>
        @endif
    </div>

    {{-- Formulär --}}
    @if ($showForm)
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 space-y-4">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                {{ $editingId ? 'Redigera aktivitet' : 'Ny aktivitet' }}
            </h2>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Namn *</label>
                    <input wire:model="name" type="text"
                           class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron @error('name') border-red-300 dark:border-red-600 @enderror" />
                    @error('name') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Sorteringsordning</label>
                    <input wire:model="sortOrder" type="number" min="0"
                           class="w-24 rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
                </div>
            </div>

            <label class="flex items-center gap-2.5 cursor-pointer">
                <input type="checkbox" wire:model="active"
                       class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron">
                <span class="text-sm text-zinc-700 dark:text-zinc-300">Aktiv (visas i bokningsformulär)</span>
            </label>

            <div class="flex gap-3 pt-1">
                <button wire:click="save"
                        class="rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                    Spara
                </button>
                <button wire:click="cancel"
                        class="rounded-lg border border-zinc-300 dark:border-zinc-600 px-4 py-2 text-sm text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                    Avbryt
                </button>
            </div>
        </div>
    @endif

    {{-- Lista --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm">
        @if ($activities->isEmpty())
            <p class="px-6 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Inga aktiviteter ännu.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Namn</th>
                        <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-300">Ordning</th>
                        <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-300">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                    @foreach ($activities as $act)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">{{ $act->name }}</td>
                            <td class="px-4 py-3 text-center text-zinc-500 dark:text-zinc-400 tabular-nums">{{ $act->sort_order }}</td>
                            <td class="px-4 py-3 text-center">
                                @if ($act->active)
                                    <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900/40 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-300">Aktiv</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-zinc-100 dark:bg-zinc-700 px-2 py-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">Inaktiv</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right space-x-3">
                                <button wire:click="edit({{ $act->id }})"
                                        class="text-xs text-complement dark:text-complement-light hover:underline">Redigera</button>
                                <button wire:click="delete({{ $act->id }})"
                                        wire:confirm="Ta bort '{{ $act->name }}'?"
                                        class="text-xs text-red-600 dark:text-red-400 hover:underline">Ta bort</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>
