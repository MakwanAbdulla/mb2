@php
    $routeName = $params['name'] ?? null;
    $newTab = filter_var($params['new_tab'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $box = filter_var($params['box'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $routeParams = collect($params)->except(['name', 'text', 'new_tab', 'box'])->all();

    $url = null;
    if ($routeName && \Illuminate\Support\Facades\Route::has($routeName)) {
        try {
            $url = route($routeName, $routeParams);
        } catch (\Throwable $e) {
            $url = null;
        }
    }
@endphp
@if($url)
    @if($box)
        <a href="{{ $url }}"
           @if($newTab) target="_blank" rel="noopener noreferrer" @endif
           class="not-prose my-4 flex items-center gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 no-underline transition-colors hover:border-complement hover:bg-complement/5 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-complement-light">
            <x-bootstrap-icon name="link-45deg" class="size-5 shrink-0 text-complement dark:text-complement-light" />
            <span class="min-w-0 flex-1">
                <span class="block truncate font-semibold text-zinc-900 dark:text-white">{{ $params['text'] ?? $routeName }}</span>
            </span>
            <x-bootstrap-icon name="chevron-right" class="size-4 shrink-0 text-zinc-400" />
        </a>
    @else
        <a href="{{ $url }}" @if($newTab) target="_blank" rel="noopener noreferrer" @endif>{{ $params['text'] ?? $routeName }}</a>
    @endif
@else
    <p class="not-prose my-4 text-sm text-zinc-400 dark:text-zinc-500">Route hittades inte eller saknar parametrar (route: {{ $routeName ?? '?' }}).</p>
@endif
