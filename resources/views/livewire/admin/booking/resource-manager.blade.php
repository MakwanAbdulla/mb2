<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Rum &amp; resurser</h1>
        @if (! $showForm)
            <button wire:click="create"
                    class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                + Nytt rum
            </button>
        @endif
    </div>

    {{-- Formulär --}}
    @if ($showForm)
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 space-y-5">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                {{ $editingId ? 'Redigera rum' : 'Nytt rum' }}
            </h2>

            @php $inp = 'w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron'; @endphp

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Namn *</label>
                    <input wire:model="name" type="text"
                           class="{{ $inp }} @error('name') border-red-300 dark:border-red-600 @enderror" />
                    @error('name') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Kategori</label>
                    <select wire:model="categoryId" class="{{ $inp }}">
                        <option value="">Ingen kategori</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                    Plats <span class="font-normal text-zinc-400">(valfritt)</span>
                </label>
                <input wire:model="location" type="text" class="{{ $inp }}" />
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                    Beskrivning <span class="font-normal text-zinc-400">(valfritt)</span>
                </label>
                <textarea wire:model="description" rows="2" class="{{ $inp }}"></textarea>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Min tid (min) *</label>
                    <input wire:model="minDuration" type="number" min="5" max="1440"
                           class="{{ $inp }} @error('minDuration') border-red-300 @enderror" />
                    @error('minDuration') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                        Max tid (min) <span class="font-normal text-zinc-400">(valfritt)</span>
                    </label>
                    <input wire:model="maxDuration" type="number" min="5" max="1440"
                           class="{{ $inp }} @error('maxDuration') border-red-300 @enderror" />
                    @error('maxDuration') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Kapacitet *</label>
                    <input wire:model="capacity" type="number" min="1" max="100"
                           class="{{ $inp }} @error('capacity') border-red-300 @enderror" />
                    @error('capacity') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-end gap-6">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Kalendercolor</label>
                    <div class="flex items-center gap-2">
                        <input wire:model="color" type="color"
                               class="h-9 w-14 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600 p-0.5" />
                        <input wire:model="color" type="text" maxlength="7"
                               class="w-28 rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm font-mono focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
                    </div>
                </div>
                <div class="space-y-2 pb-1">
                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input type="checkbox" wire:model="requiresApproval"
                               class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron">
                        <span class="text-sm text-zinc-700 dark:text-zinc-300">Kräver godkännande</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input type="checkbox" wire:model="active"
                               class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron">
                        <span class="text-sm text-zinc-700 dark:text-zinc-300">Aktiv</span>
                    </label>
                </div>
            </div>

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
        @if ($resources->isEmpty())
            <p class="px-6 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Inga rum ännu.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Rum</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Kategori</th>
                        <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-300">Kapacitet</th>
                        <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-300">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                    @foreach ($resources as $resource)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    @if ($resource->color)
                                        <span class="inline-block h-3 w-3 rounded-full flex-shrink-0"
                                              style="background-color: {{ $resource->color }}"></span>
                                    @endif
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ $resource->name }}</span>
                                </div>
                                @if ($resource->location)
                                    <p class="mt-0.5 ml-5 text-xs text-zinc-400">{{ $resource->location }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">
                                {{ $resource->category?->name ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-center text-zinc-500 dark:text-zinc-400 tabular-nums">{{ $resource->capacity }}</td>
                            <td class="px-4 py-3 text-center">
                                @if ($resource->active)
                                    <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900/40 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-300">Aktiv</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-zinc-100 dark:bg-zinc-700 px-2 py-0.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">Inaktiv</span>
                                @endif
                                @if ($resource->requires_approval)
                                    <span class="ml-1 inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900/40 px-2 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-300">Granskas</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right space-x-3">
                                <button wire:click="edit({{ $resource->id }})"
                                        class="text-xs text-complement dark:text-complement-light hover:underline">Redigera</button>
                                <button wire:click="delete({{ $resource->id }})"
                                        wire:confirm="Ta bort '{{ $resource->name }}'? Alla bokningar kopplade till detta rum påverkas."
                                        class="text-xs text-red-600 dark:text-red-400 hover:underline">Ta bort</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>
