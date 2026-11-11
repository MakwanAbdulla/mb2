<div class="space-y-4" x-data="{ previewUrl: null, previewName: '', previewPath: '', previewBg: 'light' }">

    {{-- Förhandsvisning (teleporterad till body för z-index) --}}
    <template x-teleport="body">
        <div x-show="previewUrl"
             x-cloak
             style="z-index: 99999;"
             class="fixed inset-0 flex items-center justify-center bg-black/80 p-4"
             @click="previewUrl = null"
             @keydown.escape.window="previewUrl = null">
            <div class="flex max-h-[95vh] w-full max-w-6xl flex-col gap-3 rounded-lg bg-white p-4 shadow-2xl dark:bg-zinc-900 sm:p-6"
                 @click.stop>
                <div class="flex flex-shrink-0 items-start justify-between gap-3">
                    <h3 class="truncate pr-4 text-sm font-semibold text-zinc-900 dark:text-zinc-100 sm:text-base" x-text="previewName"></h3>
                    <button @click="previewUrl = null" class="flex-shrink-0 text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="flex flex-shrink-0 items-center gap-2 text-xs text-zinc-700 dark:text-zinc-200 sm:text-sm">
                    <span>Bakgrund:</span>
                    <button @click="previewBg = 'light'"
                            :class="previewBg === 'light' ? 'bg-zinc-200 text-zinc-900 dark:bg-zinc-700 dark:text-white' : 'bg-transparent text-zinc-600 dark:text-zinc-400'"
                            class="rounded border border-zinc-300 px-2 py-1 transition-colors dark:border-zinc-600">Ljus</button>
                    <button @click="previewBg = 'dark'"
                            :class="previewBg === 'dark' ? 'bg-zinc-800 text-white' : 'bg-transparent text-zinc-600 dark:text-zinc-400'"
                            class="rounded border border-zinc-300 px-2 py-1 transition-colors dark:border-zinc-600">Mörk</button>
                </div>
                <div class="flex min-h-0 flex-1 items-center justify-center overflow-auto rounded-lg p-3"
                     :class="previewBg === 'dark' ? 'bg-zinc-900' : 'bg-zinc-100'">
                    <img :src="previewUrl" :alt="previewName" class="max-h-full max-w-full object-contain" style="max-height: calc(95vh - 200px);">
                </div>
                <div class="flex flex-shrink-0 gap-2 sm:gap-3">
                    <button type="button"
                            @click.prevent="$wire.selectImage(previewPath); previewUrl = null"
                            class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-medium text-white transition-colors hover:bg-blue-700 sm:px-4 sm:text-sm">
                        Välj denna bild
                    </button>
                    <button type="button"
                            @click="previewUrl = null"
                            class="rounded-lg bg-zinc-200 px-3 py-2 text-xs font-medium text-zinc-900 transition-colors hover:bg-zinc-300 dark:bg-zinc-700 dark:text-zinc-100 dark:hover:bg-zinc-600 sm:px-4 sm:text-sm">
                        Stäng
                    </button>
                </div>
            </div>
        </div>
    </template>

    {{-- Sök --}}
    <div>
        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Sök i bilder</label>
        <input wire:model.live="search" type="text" placeholder="Sök efter bildnamn..."
               class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
    </div>

    {{-- Uppladdning --}}
    <div class="flex gap-2">
        @if (!$showUpload)
            <button type="button" wire:click="$set('showUpload', true)"
                    class="rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-brand-dark hover:bg-brand-saffron">
                Ladda upp ny bild
            </button>
        @else
            <div class="w-full space-y-2">
                <input type="file" wire:model="uploadFile" accept="image/*"
                       class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-900 file:mr-3 file:rounded file:border-0 file:bg-zinc-100 file:px-2 file:py-0.5 file:text-xs file:font-medium dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                @if ($uploadError)
                    <p class="text-sm text-red-600">{{ $uploadError }}</p>
                @endif
                <div class="flex gap-2">
                    <button type="button" wire:click="uploadImage"
                            class="rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-brand-dark hover:bg-brand-saffron">
                        Ladda upp
                    </button>
                    <button type="button" wire:click="$set('showUpload', false); $set('uploadFile', null)"
                            class="rounded-lg px-3 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                        Avbryt
                    </button>
                </div>
            </div>
        @endif
    </div>

    {{-- Bildgalleri --}}
    @if (count($images) > 0)
        <div class="max-h-64 overflow-y-auto rounded-lg border border-zinc-300 p-2 dark:border-zinc-600">
            <div class="flex flex-wrap gap-2">
                @foreach ($images as $image)
                    <div class="group flex w-20 flex-col overflow-hidden rounded border border-zinc-300 transition-colors hover:border-blue-500 dark:border-zinc-600">
                        <p class="truncate border-b border-zinc-200 bg-zinc-50 px-1 py-0.5 text-[9px] leading-tight text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300"
                           title="{{ $image['name'] }}">{{ $image['name'] }}</p>
                        <div class="relative h-16 w-20 flex-shrink-0 overflow-hidden bg-zinc-100 dark:bg-zinc-800">
                            <div class="absolute inset-0 z-10 cursor-pointer"
                                 @click.stop="previewUrl = '{{ $image['url'] }}'; previewName = '{{ $image['name'] }}'; previewPath = '{{ $image['path'] }}'">
                            </div>
                            <img src="{{ $image['url'] }}" alt="{{ $image['name'] }}" class="h-full w-full object-cover">
                            <div class="pointer-events-none absolute inset-0 z-20 flex items-center justify-center bg-black/0 opacity-0 transition-colors group-hover:bg-black/50 group-hover:opacity-100">
                                <button type="button"
                                        @click.prevent.stop="$wire.selectImage('{{ $image['path'] }}')"
                                        class="pointer-events-auto rounded bg-blue-600 px-2 py-0.5 text-[10px] font-medium text-white">
                                    Välj
                                </button>
                            </div>
                            @if($image['source'] !== 'public')
                                <button type="button"
                                        wire:click="deleteImage('{{ $image['path'] }}')"
                                        class="absolute right-0.5 top-0.5 flex h-4 w-4 items-center justify-center rounded bg-red-500 text-xs leading-none text-white opacity-0 transition-opacity group-hover:opacity-100">
                                    ×
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="py-8 text-center text-zinc-500 dark:text-zinc-400">
            @if (!empty($search))
                <p>Inga bilder matchade din sökning.</p>
            @else
                <p>Inga bilder tillgängliga än. Ladda upp din första bild!</p>
            @endif
        </div>
    @endif
</div>
