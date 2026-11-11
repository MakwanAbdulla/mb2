<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 space-y-6">

    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Rum &amp; lokaler</h1>

    {{-- Filter --}}
    <div class="flex flex-col sm:flex-row gap-3">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Sök rum…"
               class="flex-1 rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
        <select wire:model.live="selectedCategory"
                class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron">
            <option value="">Alla kategorier</option>
            @foreach ($categories as $cat)
                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
            @endforeach
        </select>
    </div>

    @if ($resources->isEmpty())
        <p class="text-center text-sm text-zinc-500 dark:text-zinc-400 py-10">Inga rum hittades.</p>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($resources as $resource)
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden">
                    @if ($resource->color)
                        <div class="h-1.5" style="background-color: {{ $resource->color }}"></div>
                    @endif
                    <div class="p-5">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-semibold text-zinc-900 dark:text-white">{{ $resource->name }}</h3>
                            @if ($resource->category)
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium shrink-0"
                                      style="background-color: {{ $resource->category->color }}20; color: {{ $resource->category->color }}">
                                    {{ $resource->category->name }}
                                </span>
                            @endif
                        </div>

                        @if ($resource->location)
                            <p class="mt-1 text-xs text-zinc-400">{{ $resource->location }}</p>
                        @endif

                        @if ($resource->description)
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300 line-clamp-2">{{ $resource->description }}</p>
                        @endif

                        <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400">
                            <span>{{ $resource->capacity }} pers.</span>
                            @if ($resource->requires_approval)
                                <span class="inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900/40 px-2 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-300">Kräver godkännande</span>
                            @endif
                        </div>

                        <div class="mt-4">
                            <a href="{{ route('booking.create', ['resource' => $resource->id]) }}" wire:navigate
                               class="block w-full rounded-lg bg-brand py-2 text-center text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                                Boka
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $resources->links() }}</div>
    @endif

</div>
