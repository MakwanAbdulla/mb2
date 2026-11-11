<div class="space-y-2" x-data="{ open: false }">
    <div class="flex items-center gap-2">
        <button type="button" @click="open = !open"
                class="rounded px-2 py-1 text-xs font-medium text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
            {{ $selected ? 'Byt ikon' : 'Välj ikon' }}
        </button>
        @if($selected)
            <div class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                <img src="/images/icons/bootstrap/{{ $selected }}.svg" alt="{{ $selected }}" class="size-5" />
                <span>{{ $selected }}</span>
            </div>
        @endif
    </div>

    <div x-show="open" x-cloak class="space-y-3 rounded-md border border-zinc-200 p-3 dark:border-zinc-700">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Sök bland 2000+ ikoner..."
               class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />

        @if(count($icons) > 0)
            <div class="max-h-64 overflow-y-auto">
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6">
                    @foreach($icons as $name)
                        <button type="button"
                                class="flex flex-col items-center gap-1 rounded border p-2 text-center transition-colors hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-950 {{ $selected === $name ? 'border-blue-500 bg-blue-50 dark:bg-blue-950' : 'border-zinc-200 dark:border-zinc-700' }}"
                                wire:click="selectIcon('{{ $name }}')"
                                title="{{ $name }}">
                            <img src="/images/icons/bootstrap/{{ $name }}.svg" alt="{{ $name }}" class="size-5 opacity-70" />
                            <span class="break-all text-[9px] leading-tight text-zinc-600 dark:text-zinc-400">{{ Str::limit($name, 10) }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                Visar {{ count($icons) }} {{ count($icons) < 200 ? 'ikon' : 'av 2000+ ikoner' }}{{ count($icons) !== 1 ? 'er' : '' }}.
                {{ empty($search) ? 'Sök för att hitta fler.' : '' }}
            </p>
        @else
            <div class="py-4 text-center text-sm text-zinc-500 dark:text-zinc-400">
                @if(empty($search))
                    <p>Inga ikoner tillgängliga.</p>
                @else
                    <p>Inga ikoner matchade "{{ $search }}"</p>
                @endif
            </div>
        @endif
    </div>
</div>
