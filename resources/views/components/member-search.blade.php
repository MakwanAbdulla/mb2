@props([
    'results',                        // Collection<Member>
    'action',                         // wire:click action name, called with member id
    'selectedName' => null,           // Show confirmation below input when set
    'placeholder'  => 'Namn eller medlemsnummer…',
])

<div x-data="{ open: false }">
    <input {{ $attributes->whereStartsWith('wire:model') }}
           @focus="open = true"
           @click.away="open = false"
           type="text"
           placeholder="{{ $placeholder }}"
           autocomplete="off"
           class="inp mt-1 w-full max-w-sm" />

    @if($results->isNotEmpty())
        <div x-show="open"
             class="mt-1 max-w-sm overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
            @foreach($results as $m)
                <button wire:click="{{ $action }}({{ $m->id }})"
                        @click="open = false"
                        class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-700">
                    <span class="font-medium text-zinc-900 dark:text-white">{{ $m->full_name }}</span>
                    <span class="text-xs text-zinc-400">#{{ $m->membership_number }}</span>
                </button>
            @endforeach
        </div>
    @endif

    @if($selectedName)
        <p class="mt-1 text-xs text-green-600 dark:text-green-400">
            <x-bootstrap-icon name="check-circle" class="inline size-3.5" />
            Vald: {{ $selectedName }}
        </p>
    @endif
</div>
