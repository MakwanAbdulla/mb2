@props(['title', 'subtitle' => null, 'permission' => null, 'editUrl' => null])

<div class="border-b border-zinc-200 bg-gradient-to-r from-brand/10 via-brand-orange/5 to-complement/10 py-6 dark:border-zinc-800 dark:from-brand/5 dark:via-brand-orange/5 dark:to-complement/5">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex items-center gap-2">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white md:text-3xl">{{ $title }}</h1>
            @if($permission)
                <x-permission-hint :permission="$permission" />
            @endif
            @if($editUrl)
                @can('cms.edit')
                <a href="{{ $editUrl }}" wire:navigate
                   title="Redigera sida"
                   class="rounded p-1 text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-300">
                    <x-bootstrap-icon name="pencil" class="size-4" />
                </a>
                @endcan
            @endif
        </div>
        @if($subtitle)
            <p class="mt-1 text-zinc-600 dark:text-zinc-400">{{ $subtitle }}</p>
        @endif
        @if(isset($slot) && $slot->isNotEmpty())
            <div class="mt-4">{{ $slot }}</div>
        @endif
    </div>
</div>
