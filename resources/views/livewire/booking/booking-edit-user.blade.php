<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 space-y-6">

    {{-- Header --}}
    <div class="flex items-center gap-3">
        <a href="{{ route('booking.my') }}" wire:navigate
           class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Mina bokningar</a>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Redigera bokning</h1>
    </div>

    @if ($saved)
        <div class="flex items-center justify-between rounded-lg border border-green-200 dark:border-green-700 bg-green-50 dark:bg-green-950 px-4 py-3">
            <p class="text-sm font-semibold text-green-800 dark:text-green-300">Bokningen uppdaterades.</p>
            <a href="{{ route('booking.my') }}" wire:navigate
               class="text-sm text-green-700 dark:text-green-400 underline">Tillbaka till listan</a>
        </div>
    @endif

    @if ($conflictMessage)
        <div class="rounded-lg border border-red-200 dark:border-red-700 bg-red-50 dark:bg-red-950 px-4 py-3 text-sm text-red-700 dark:text-red-300">
            {{ $conflictMessage }}
        </div>
    @endif

    <form wire:submit="save" class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 space-y-5">

        @php $inp = 'w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron'; @endphp

        {{-- Rum --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Rum</label>
            <select wire:model="resourceId" class="{{ $inp }}">
                <option value="">Välj rum…</option>
                @foreach ($resources as $resource)
                    <option value="{{ $resource->id }}">{{ $resource->name }}</option>
                @endforeach
            </select>
            @error('resourceId') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Titel --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Titel *</label>
            <input wire:model="title" type="text"
                   class="{{ $inp }} @error('title') border-red-300 dark:border-red-600 @enderror" />
            @error('title') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Aktivitet --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Aktivitet *</label>
            <select wire:model="activity" class="{{ $inp }} @error('activity') border-red-300 dark:border-red-600 @enderror">
                <option value="">Välj aktivitet…</option>
                @foreach ($activities as $act)
                    <option value="{{ $act }}">{{ $act }}</option>
                @endforeach
            </select>
            @error('activity') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Datum + tider --}}
        <div class="grid grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Datum *</label>
                <input wire:model="date" type="date"
                       class="{{ $inp }} @error('date') border-red-300 dark:border-red-600 @enderror" />
                @error('date') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Starttid *</label>
                <select wire:model="startTime" class="{{ $inp }}">
                    @for ($i = 0; $i <= 47; $i++)
                        @php $val = sprintf('%02d:%02d', intdiv($i, 2), ($i % 2) * 30); @endphp
                        <option value="{{ $val }}">{{ $val }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Sluttid *</label>
                <select wire:model="endTime" class="{{ $inp }} @error('endTime') border-red-300 dark:border-red-600 @enderror">
                    @for ($i = 1; $i <= 48; $i++)
                        @php $val = $i === 48 ? '24:00' : sprintf('%02d:%02d', intdiv($i, 2), ($i % 2) * 30); @endphp
                        <option value="{{ $val }}">{{ $val }}</option>
                    @endfor
                </select>
                @error('endTime') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Bokningstyp --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">Typ av arrangemang</label>
            <div class="flex gap-6">
                <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300 cursor-pointer">
                    <input type="radio" wire:model.live="bookingType" value="group"
                           class="text-brand focus:ring-brand-saffron">
                    Fylld grupp
                </label>
                <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300 cursor-pointer">
                    <input type="radio" wire:model.live="bookingType" value="open"
                           class="text-brand focus:ring-brand-saffron">
                    Öppet arrangemang
                </label>
            </div>
        </div>

        @if ($bookingType === 'open')
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                    Deltagarinformation <span class="font-normal text-zinc-400">(valfritt)</span>
                </label>
                <textarea wire:model="participationInfo" rows="2" class="{{ $inp }}"></textarea>
            </div>
        @endif

        {{-- Taggar --}}
        <div x-data="{
                input: '',
                open: false,
                allTags: {{ Js::from($allTags) }},
                selected: $wire.entangle('selectedTags'),
                get suggestions() {
                    const q = this.input.toLowerCase().trim();
                    if (!q) return [];
                    return this.allTags.filter(t => t.includes(q) && !this.selected.includes(t)).slice(0, 8);
                },
                addTag(name) {
                    name = name.toLowerCase().trim();
                    if (name && !this.selected.includes(name)) this.selected = [...this.selected, name];
                    this.input = ''; this.open = false;
                    this.$nextTick(() => this.$refs.tagInput.focus());
                },
                removeTag(name) { this.selected = this.selected.filter(t => t !== name); },
                onKeydown(e) {
                    if ((e.key === 'Enter' || e.key === ',') && this.input.trim()) { e.preventDefault(); this.addTag(this.input); }
                    else if (e.key === 'Backspace' && !this.input && this.selected.length) this.selected = this.selected.slice(0, -1);
                    else if (e.key === 'Escape') this.open = false;
                }
            }" @click.outside="open = false">
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                Taggar <span class="font-normal text-zinc-400">(valfritt)</span>
            </label>
            <div class="min-h-[38px] flex flex-wrap gap-1.5 items-center rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-2 py-1.5 focus-within:border-brand focus-within:ring-1 focus-within:ring-brand-saffron cursor-text"
                 @click="$refs.tagInput.focus()">
                <template x-for="tag in selected" :key="tag">
                    <span class="inline-flex items-center gap-1 rounded-full bg-brand/10 border border-brand/30 px-2 py-0.5 text-xs text-brand-dark dark:text-brand">
                        <span x-text="tag"></span>
                        <button type="button" @click.stop="removeTag(tag)" class="text-brand-orange hover:text-red-500 leading-none">&times;</button>
                    </span>
                </template>
                <div class="relative flex-1 min-w-[120px]">
                    <input x-ref="tagInput" x-model="input"
                           @keydown="onKeydown($event)"
                           @input="input = input.toLowerCase(); open = input.trim().length > 0"
                           @focus="open = input.trim().length > 0"
                           type="text" placeholder="Skriv och tryck Enter…"
                           class="w-full bg-transparent border-0 p-0 text-sm text-zinc-900 dark:text-white placeholder-zinc-400 focus:ring-0 focus:outline-none" />
                    <div x-show="open && suggestions.length > 0" x-transition
                         class="absolute z-50 left-0 top-full mt-1 w-48 rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 shadow-lg py-1"
                         style="display:none">
                        <template x-for="tag in suggestions" :key="tag">
                            <button type="button" @click.prevent="addTag(tag)"
                                    class="w-full text-left px-3 py-1.5 text-sm text-zinc-700 dark:text-zinc-200 hover:bg-zinc-50 dark:hover:bg-zinc-700"
                                    x-text="tag"></button>
                        </template>
                    </div>
                </div>
            </div>
            <p class="mt-1 text-xs text-zinc-400">Tryck Enter eller komma för att lägga till.</p>
        </div>

        {{-- Anteckningar --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                Anteckningar <span class="font-normal text-zinc-400">(valfritt)</span>
            </label>
            <textarea wire:model="notes" rows="2" class="{{ $inp }}"></textarea>
        </div>

        {{-- Submit --}}
        <div class="flex items-center justify-between pt-1">
            <a href="{{ route('booking.my') }}" wire:navigate
               class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">Avbryt</a>
            <button type="submit"
                    class="rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors disabled:opacity-50"
                    wire:loading.attr="disabled">
                <span wire:loading.remove>Spara ändringar</span>
                <span wire:loading>Sparar…</span>
            </button>
        </div>

    </form>

</div>
