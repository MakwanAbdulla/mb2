<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 space-y-6">

    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Anslagstavla</h1>

    {{-- Formulär --}}
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 space-y-4">
        <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
            {{ $editingId ? 'Redigera meddelande' : 'Nytt meddelande' }}
        </h2>

        @php $inp = 'w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron'; @endphp

        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                Rubrik <span class="font-normal text-zinc-400">(valfritt)</span>
            </label>
            <input wire:model="title" type="text" class="{{ $inp }}" />
        </div>

        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Meddelande *</label>
            <textarea wire:model="message" rows="3" class="{{ $inp }} @error('message') border-red-300 dark:border-red-600 @enderror"></textarea>
            @error('message') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Startdatum *</label>
                <input wire:model="startsAt" type="date" class="{{ $inp }} @error('startsAt') border-red-300 @enderror" />
                @error('startsAt') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Slutdatum *</label>
                <input wire:model="endsAt" type="date" class="{{ $inp }} @error('endsAt') border-red-300 @enderror" />
                @error('endsAt') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Visningstid (sek) *</label>
                <input wire:model="duration" type="number" min="3" max="60" class="{{ $inp }}" />
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Typ *</label>
                <select wire:model="type" class="{{ $inp }}">
                    <option value="info">Info</option>
                    <option value="success">Framgång</option>
                    <option value="warning">Varning</option>
                    <option value="error">Fel/Viktigt</option>
                </select>
            </div>
        </div>

        <label class="flex items-center gap-2.5 cursor-pointer">
            <input type="checkbox" wire:model="isPublic"
                   class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron">
            <span class="text-sm text-zinc-700 dark:text-zinc-300">Synlig för alla (även icke-inloggade)</span>
        </label>

        <div class="flex gap-3 pt-1">
            <button wire:click="save"
                    class="rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                {{ $editingId ? 'Uppdatera' : 'Skapa meddelande' }}
            </button>
            @if ($editingId)
                <button wire:click="cancelEdit"
                        class="rounded-lg border border-zinc-300 dark:border-zinc-600 px-4 py-2 text-sm text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                    Avbryt
                </button>
            @endif
        </div>
    </div>

    {{-- Flikar --}}
    <div class="flex gap-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 p-1 w-fit">
        @foreach (['active' => 'Aktiva', 'past' => 'Historik'] as $val => $label)
            <button wire:click="$set('tab', '{{ $val }}')"
                    class="px-4 py-1.5 rounded-md text-sm font-medium transition-colors
                           {{ $tab === $val
                               ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm'
                               : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Lista --}}
    @php $notices = $tab === 'active' ? $active : $past; @endphp

    @if ($notices->isEmpty())
        <p class="text-center text-sm text-zinc-500 dark:text-zinc-400 py-6">Inga meddelanden.</p>
    @else
        <div class="space-y-3">
            @foreach ($notices as $notice)
                @php
                    $typeCls = match($notice->type) {
                        'success' => 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950',
                        'warning' => 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950',
                        'error'   => 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950',
                        default   => 'border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950',
                    };
                    $textCls = match($notice->type) {
                        'success' => 'text-green-800 dark:text-green-300',
                        'warning' => 'text-amber-800 dark:text-amber-300',
                        'error'   => 'text-red-800 dark:text-red-300',
                        default   => 'text-blue-800 dark:text-blue-300',
                    };
                @endphp
                <div class="rounded-xl border {{ $typeCls }} p-4 flex items-start justify-between gap-4">
                    <div class="flex-1 min-w-0">
                        @if ($notice->title)
                            <p class="font-semibold text-sm {{ $textCls }}">{{ $notice->title }}</p>
                        @endif
                        <p class="text-sm {{ $textCls }} {{ $notice->title ? 'mt-0.5' : '' }}">{{ $notice->message }}</p>
                        <p class="mt-1 text-xs text-zinc-400">
                            {{ $notice->starts_at->locale('sv')->isoFormat('D MMM') }} –
                            {{ $notice->ends_at->locale('sv')->isoFormat('D MMM YYYY') }}
                            · {{ $notice->duration }}s
                            @if ($notice->is_public) · Publik @endif
                        </p>
                    </div>
                    <div class="flex gap-3 flex-shrink-0">
                        <button wire:click="edit({{ $notice->id }})"
                                class="text-xs text-complement dark:text-complement-light hover:underline">Redigera</button>
                        <button wire:click="delete({{ $notice->id }})"
                                wire:confirm="Ta bort detta meddelande?"
                                class="text-xs text-red-600 dark:text-red-400 hover:underline">Ta bort</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

</div>
