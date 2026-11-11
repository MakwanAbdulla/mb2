@php
    $item = \App\Models\Content\NavigationItem::find($params['id'] ?? null);
    $newTab = filter_var($params['new_tab'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $box = filter_var($params['box'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $resolvedUrl = $item?->resolvedUrl();
@endphp
@if($item && $resolvedUrl)
    @if($box)
        <a href="{{ $resolvedUrl }}"
           @if($newTab) target="_blank" rel="noopener noreferrer" @endif
           class="not-prose my-4 flex items-center gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 no-underline transition-colors hover:border-complement hover:bg-complement/5 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-complement-light">
            <x-bootstrap-icon :name="$item->icon ?: 'link-45deg'" class="size-5 shrink-0 text-complement dark:text-complement-light" />
            <span class="min-w-0 flex-1">
                <span class="block truncate font-semibold text-zinc-900 dark:text-white">{{ $params['text'] ?? $item->label }}</span>
            </span>
            <x-bootstrap-icon name="chevron-right" class="size-4 shrink-0 text-zinc-400" />
        </a>
    @else
        <a href="{{ $resolvedUrl }}" @if($newTab) target="_blank" rel="noopener noreferrer" @endif>{{ $params['text'] ?? $item->label }}</a>
    @endif
@else
    <p class="not-prose my-4 text-sm text-zinc-400 dark:text-zinc-500">Menyalternativ hittades inte (id: {{ $params['id'] ?? '?' }}).</p>
@endif
