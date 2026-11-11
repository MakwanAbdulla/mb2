@props([
    'href'       => '#',
    'icon'       => null,
    'active'     => false,
    'permission' => null,
    'target'     => null,
])

@php
    $classes = implode(' ', array_filter([
        'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
        $active
            ? 'bg-zinc-100 font-medium text-zinc-900 dark:bg-zinc-800 dark:text-white'
            : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white',
    ]));
@endphp

<a href="{{ $href }}"
   @if(!$target) wire:navigate @endif
   @if($target) target="{{ $target }}" rel="noopener" @endif
   {{ $attributes->class($classes) }}>
    @if($icon)
        <x-bootstrap-icon :name="$icon" class="size-4 shrink-0" />
    @endif
    <span class="flex-1">{{ $slot }}</span>
    @if($permission)
        <x-permission-hint :permission="$permission" />
    @endif
</a>
