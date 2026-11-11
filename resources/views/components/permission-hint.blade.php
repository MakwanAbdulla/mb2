@auth
@if(auth()->user()->hasPermissionTo('users.manage-roles'))
@php
    static $permLabels = null;
    if ($permLabels === null) {
        $permLabels = \Spatie\Permission\Models\Permission::pluck('label', 'name');
    }
    $hintLabel = $permLabels[$permission] ?? null;
@endphp
<span x-data="{ open: false }"
      @mouseenter="open = true"
      @mouseleave="open = false"
      class="relative inline-flex shrink-0 items-center">
    <x-bootstrap-icon name="lock-fill"
        class="size-3 cursor-help text-zinc-300 transition-colors group-hover:text-zinc-400 dark:text-zinc-600 dark:group-hover:text-zinc-500" />
    <span x-show="open"
          x-cloak
          x-transition:enter="transition ease-out duration-100"
          x-transition:enter-start="opacity-0 scale-95"
          x-transition:enter-end="opacity-100 scale-100"
          class="absolute bottom-full right-0 z-50 mb-2 w-max max-w-56 rounded-lg border border-zinc-200 bg-white px-3 py-2 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
        @if($hintLabel)
            <span class="block text-xs font-medium text-zinc-800 dark:text-zinc-200">{{ $hintLabel }}</span>
        @endif
        <code class="block font-mono text-xs {{ $hintLabel ? 'mt-0.5 text-zinc-400 dark:text-zinc-500' : 'text-zinc-700 dark:text-zinc-300' }}">{{ $permission }}</code>
    </span>
</span>
@endif
@endauth
