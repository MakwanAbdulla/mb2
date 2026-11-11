<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 space-y-6"
     x-data="{ allTagNames: {{ Js::from($tagNames) }} }">

    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Taggar</h1>

    @if ($saved)
        <div class="rounded-lg border border-green-200 dark:border-green-700 bg-green-50 dark:bg-green-950 px-4 py-3 text-sm text-green-800 dark:text-green-300">
            Ändringarna sparades.
        </div>
    @endif

    {{-- Ny tagg --}}
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-5 space-y-3">
        <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Lägg till tagg</h2>
        <div class="flex gap-3">
            <input wire:model="newTagName" type="text" placeholder="Taggnamn…"
                   class="flex-1 rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron @error('newTagName') border-red-300 dark:border-red-600 @enderror" />
            <button wire:click="addTag"
                    class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                Lägg till
            </button>
        </div>
        @error('newTagName') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    {{-- Tagglista --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm">
        @if ($tags->isEmpty())
            <p class="px-6 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Inga taggar ännu.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Tagg</th>
                        <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-300">Bokningar</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                    @foreach ($tags as $tag)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-3">
                                @if ((string) $tag->id === $renamingId)
                                    <div class="flex items-center gap-2">
                                        <input wire:model="renameValue" type="text"
                                               class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
                                        <button wire:click="saveRename"
                                                class="rounded bg-brand px-3 py-1 text-xs font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">OK</button>
                                        <button wire:click="cancelRename"
                                                class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">Avbryt</button>
                                    </div>
                                    @error('renameValue') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                @elseif ((string) $tag->id === $mergingId)
                                    <div class="space-y-2">
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Slå ihop <strong>{{ $tag->name }}</strong> med:</p>
                                        <div class="flex items-center gap-2">
                                            <input wire:model="mergeTarget" type="text" list="merge-suggestions-{{ $tag->id }}"
                                                   placeholder="Måltagg…"
                                                   class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
                                            <datalist id="merge-suggestions-{{ $tag->id }}">
                                                @foreach ($tags->where('id', '!=', $tag->id) as $other)
                                                    <option value="{{ $other->name }}">
                                                @endforeach
                                            </datalist>
                                            <button wire:click="saveMerge"
                                                    wire:confirm="Slå ihop '{{ $tag->name }}' med '{{ $mergeTarget }}'? '{{ $tag->name }}' raderas."
                                                    class="rounded bg-amber-500 px-3 py-1 text-xs font-semibold text-white hover:bg-amber-600 transition-colors">Slå ihop</button>
                                            <button wire:click="cancelMerge"
                                                    class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">Avbryt</button>
                                        </div>
                                        @error('mergeTarget') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                    </div>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-zinc-100 dark:bg-zinc-700 px-2.5 py-0.5 text-xs text-zinc-700 dark:text-zinc-300">
                                        {{ $tag->name }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center text-zinc-500 dark:text-zinc-400 tabular-nums">{{ $tag->bookings_count }}</td>
                            <td class="px-4 py-3 text-right space-x-3">
                                @if ((string) $tag->id !== $renamingId && (string) $tag->id !== $mergingId)
                                    <button wire:click="startRename({{ $tag->id }}, '{{ $tag->name }}')"
                                            class="text-xs text-complement dark:text-complement-light hover:underline">Byt namn</button>
                                    <button wire:click="startMerge({{ $tag->id }})"
                                            class="text-xs text-zinc-500 dark:text-zinc-400 hover:underline">Slå ihop</button>
                                    <button wire:click="deleteTag({{ $tag->id }})"
                                            wire:confirm="Ta bort taggen '{{ $tag->name }}'?"
                                            class="text-xs text-red-600 dark:text-red-400 hover:underline">Ta bort</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>
