@props([
    'heading',
    'description' => null,
    'createAction',
    'createLabel',
])

<div class="flex flex-col items-center gap-4 rounded-xl border border-dashed border-zinc-300 bg-zinc-50 px-6 py-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
    <x-bootstrap-icon name="ui-checks" class="size-8 text-zinc-400" />
    <div>
        <p class="font-medium text-zinc-700 dark:text-zinc-300">{{ $heading }}</p>
        @if($description)
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
        @endif
    </div>
    <button wire:click="{{ $createAction }}" class="btn btn-secondary">
        <x-bootstrap-icon name="plus" class="size-4" /> {{ $createLabel }}
    </button>
</div>
