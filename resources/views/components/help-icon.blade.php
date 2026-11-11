@props(['section' => '', 'label' => 'Hjälp'])

<span
    x-data="{ show: false }"
    class="relative inline-flex items-center"
    @mouseenter="show = true"
    @mouseleave="show = false"
>
    <a
        href="{{ route('help') }}{{ $section ? '#'.$section : '' }}"
        target="_blank"
        rel="noopener"
        class="inline-flex items-center justify-center text-zinc-400 transition-colors hover:text-brand dark:text-zinc-500 dark:hover:text-brand"
        aria-label="Hjälp: {{ $label }}"
    >
        <x-bootstrap-icon name="info-circle" class="h-3.5 w-3.5" />
    </a>

    <div
        x-show="show"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute bottom-full left-1/2 z-50 mb-1.5 w-48 -translate-x-1/2 pointer-events-none"
        style="display:none"
    >
        <div class="rounded-md border border-zinc-200 bg-white px-3 py-2 text-center text-xs text-zinc-600 shadow-lg dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
            <div class="mb-0.5 font-medium text-zinc-900 dark:text-white">{{ $label }}</div>
            <div class="text-zinc-500 dark:text-zinc-400">Klicka för mer hjälp →</div>
        </div>
        <div class="flex justify-center">
            <div class="-mt-1 h-2 w-2 rotate-45 border-b border-r border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800"></div>
        </div>
    </div>
</span>
