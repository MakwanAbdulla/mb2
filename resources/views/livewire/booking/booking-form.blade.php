<div class="py-8">
<div class="mx-auto max-w-xl px-4 sm:px-6">

@if (! $fixedResource)
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">
@endif

    {{-- Välj rum --}}
    @if (! $fixedResource)
        <div class="pb-5 mb-5 border-b border-zinc-100 dark:border-zinc-800">
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Välj rum</label>
            <select wire:model.live="resourceSlug"
                    class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                @foreach ($rooms as $room)
                    <option value="{{ $room->slug }}">{{ $room->name }}</option>
                @endforeach
            </select>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                @if ($resource->location)
                    <span>📍 {{ $resource->location }}</span>
                @endif
                @if ($resource->max_duration)
                    <span>Max: {{ $resource->max_duration }} min</span>
                @endif
                @if ($resource->requires_approval)
                    <span class="text-amber-600 dark:text-amber-400">Kräver godkännande</span>
                @endif
            </div>
        </div>
    @endif

    {{-- Success --}}
    @if ($success)
        <div class="rounded-lg border border-green-200 dark:border-green-700 bg-green-50 dark:bg-green-950 p-4 mb-6">
            <div class="flex gap-3">
                <x-bootstrap-icon name="check-circle-fill" class="size-5 text-green-600 dark:text-green-400 shrink-0 mt-0.5" />
                <div>
                    @if (count($bookedRooms) > 1)
                        <p class="text-sm font-semibold text-green-800 dark:text-green-300">{{ count($bookedRooms) }} rum bokade!</p>
                        <ul class="mt-1 space-y-0.5">
                            @foreach ($bookedRooms as $room)
                                <li class="text-sm text-green-700 dark:text-green-400">{{ $room }}</li>
                            @endforeach
                        </ul>
                    @elseif ($resource->requires_approval)
                        <p class="text-sm font-semibold text-green-800 dark:text-green-300">Bokningsförfrågan skickad! Den behöver godkännas av en administratör.</p>
                    @else
                        <p class="text-sm font-semibold text-green-800 dark:text-green-300">Bokningen är bekräftad!</p>
                    @endif
                    <a href="{{ route('booking.my') }}" wire:navigate
                       class="mt-1 text-sm text-green-700 dark:text-green-400 underline">
                        Visa mina bokningar
                    </a>
                </div>
            </div>
        </div>
    @endif

    {{-- Booking opens info --}}
    @if (! auth()->user()->isAdmin() && $opensFrom && $opensFrom > now()->format('Y-m-d'))
        <div class="rounded-lg border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/50 p-4 mb-6">
            <p class="text-sm text-amber-800 dark:text-amber-300">
                Bokning öppnar {{ \Carbon\Carbon::parse($opensFrom)->locale('sv')->isoFormat('D MMMM YYYY') }}.
            </p>
        </div>
    @endif

    {{-- Conflict --}}
    @if ($conflictMessage)
        <div class="rounded-lg border border-red-200 dark:border-red-700 bg-red-50 dark:bg-red-950 p-4 mb-6">
            <p class="text-sm text-red-700 dark:text-red-300">{{ $conflictMessage }}</p>
            @if (count($alternatives) > 0)
                <p class="mt-2 text-sm font-medium text-red-700 dark:text-red-300">Lediga alternativ samma tid:</p>
                <ul class="mt-1 space-y-1">
                    @foreach ($alternatives as $alt)
                        <li>
                            @if ($fixedResource)
                                <a href="{{ route('booking.create') }}?rum={{ $alt['slug'] }}"
                                   class="text-sm text-red-600 dark:text-red-400 underline hover:text-red-800 dark:hover:text-red-200">
                                    {{ $alt['name'] }}
                                </a>
                            @else
                                <button type="button" wire:click="$set('resourceSlug', '{{ $alt['slug'] }}')"
                                        class="text-sm text-red-600 dark:text-red-400 underline hover:text-red-800 dark:hover:text-red-200">
                                    {{ $alt['name'] }}
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-2 text-sm text-red-500">Inga andra rum är lediga den valda tiden.</p>
            @endif
        </div>
    @endif

    <form wire:submit="save" class="space-y-5">

        {{-- Boka åt (admin) --}}
        @if (auth()->user()->isAdmin() && $members->isNotEmpty())
            @php
                $membersList = collect([['id' => '', 'label' => 'Mig själv']])
                    ->concat($members
                        ->filter(fn ($m) => $m->id !== auth()->id())
                        ->map(fn ($m) => ['id' => (string) $m->id, 'label' => $m->name]));
            @endphp
            <div x-data="{
                search: '',
                open: false,
                label: 'Mig själv',
                members: {{ Js::from($membersList) }},
                get filtered() {
                    if (!this.search) return this.members;
                    const s = this.search.toLowerCase();
                    return this.members.filter(m => m.label.toLowerCase().includes(s));
                },
                select(m) {
                    this.label = m.label;
                    $wire.set('onBehalfUserId', m.id);
                    this.search = ''; this.open = false;
                }
            }" @click.outside="open = false">
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Boka åt (valfritt)</label>
                <div class="relative">
                    <button type="button" @click="open = !open"
                            class="w-full flex items-center justify-between rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-2 text-sm text-zinc-900 dark:text-white shadow-sm focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand-saffron">
                        <span x-text="label" class="truncate"></span>
                        <x-bootstrap-icon name="chevron-down" class="size-4 text-zinc-400 shrink-0 ml-2" />
                    </button>
                    <div x-show="open" x-transition class="absolute z-50 mt-1 w-full rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 shadow-lg" style="display:none">
                        <div class="p-2 border-b border-zinc-100 dark:border-zinc-700">
                            <input x-model="search" type="text" placeholder="Sök person…"
                                   class="w-full rounded-md border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-700 text-sm text-zinc-900 dark:text-white placeholder-zinc-400 px-2 py-1 focus:border-brand focus:ring-1 focus:ring-brand-saffron"
                                   @click.stop x-ref="searchInput" x-effect="if (open) $nextTick(() => $refs.searchInput.focus())">
                        </div>
                        <ul class="max-h-52 overflow-y-auto py-1">
                            <template x-for="m in filtered" :key="m.id">
                                <li @click="select(m)"
                                    class="cursor-pointer px-3 py-2 text-sm text-zinc-700 dark:text-zinc-200 hover:bg-zinc-50 dark:hover:bg-zinc-700 truncate"
                                    x-text="m.label"></li>
                            </template>
                            <li x-show="filtered.length === 0" class="px-3 py-2 text-sm text-zinc-400">Inga träffar.</li>
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        {{-- Boka för (sektion/grupp) --}}
        @if($bookableSections->isNotEmpty())
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Boka för</label>
                <select wire:model="bookForSectionId" class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white text-sm shadow-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                    <option value="">Mig själv</option>
                    @foreach($bookableSections as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Bokningar för en sektion räknas inte mot din personliga gräns.</p>
            </div>
        @endif

        {{-- Titel --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Titel *</label>
            <input wire:model="title" type="text" placeholder="t.ex. Styrelsemöte, Träning…"
                   class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron @error('title') border-red-300 dark:border-red-600 @enderror" />
            @error('title') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Beskrivning --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Beskrivning <span class="font-normal text-zinc-400">(valfri)</span></label>
            <textarea wire:model="description" rows="2" placeholder="Synlig för alla som ser bokningen…"
                      class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron"></textarea>
            @error('description') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Aktivitet --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Aktivitet *</label>
            <select wire:model="activity"
                    class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron @error('activity') border-red-300 dark:border-red-600 @enderror">
                <option value="">Välj aktivitet…</option>
                @foreach ($activities as $act)
                    <option value="{{ $act }}">{{ $act }}</option>
                @endforeach
            </select>
            @error('activity') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

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
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Taggar <span class="font-normal text-zinc-400">(valfritt)</span></label>
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

        {{-- Datum + tider --}}
        <div class="grid grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Datum *</label>
                <input wire:model="date" type="date" min="{{ $minDate }}" max="{{ $maxDate }}"
                       class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron @error('date') border-red-300 dark:border-red-600 @enderror" />
                @error('date') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Starttid *</label>
                <select wire:model="startTime"
                        class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                    @for ($i = 0; $i <= 47; $i++)
                        @php $val = sprintf('%02d:%02d', intdiv($i, 2), ($i % 2) * 30); @endphp
                        <option value="{{ $val }}">{{ $val }}</option>
                    @endfor
                </select>
                @error('startTime') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Sluttid *</label>
                <select wire:model="endTime"
                        class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron">
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
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">Typ av arrangemang *</label>
            <div class="flex gap-6">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" wire:model.live="bookingType" value="group"
                           class="text-brand focus:ring-brand-saffron" />
                    <span class="text-sm text-zinc-600 dark:text-zinc-300">Fylld grupp</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" wire:model.live="bookingType" value="open"
                           class="text-brand focus:ring-brand-saffron" />
                    <span class="text-sm text-zinc-600 dark:text-zinc-300">Öppet arrangemang</span>
                </label>
            </div>
        </div>

        @if ($bookingType === 'open')
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Vilka kan delta? <span class="font-normal text-zinc-400">(valfritt)</span></label>
                <textarea wire:model="participationInfo" rows="3"
                          placeholder="Beskriv vilka som är välkomna, eventuella krav m.m."
                          class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron"></textarea>
                @error('participationInfo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        @endif

        {{-- Anteckningar --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Anteckningar <span class="font-normal text-zinc-400">(valfritt)</span></label>
            <textarea wire:model="notes" rows="3" placeholder="Valfri information…"
                      class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron"></textarea>
            @error('notes') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Admin-alternativ --}}
        @if (auth()->user()->isAdmin())
            <div class="space-y-2">
                <div class="rounded-lg border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/50 px-4 py-3">
                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input type="checkbox" wire:model.live="bookAll"
                               class="rounded text-brand focus:ring-brand-saffron" />
                        <span class="text-sm font-medium text-amber-800 dark:text-amber-300">Boka alla rum</span>
                        <span class="text-xs text-amber-600 dark:text-amber-400">(skapar separata bokningar för varje ledigt rum)</span>
                    </label>
                </div>
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 px-4 py-3">
                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input type="checkbox" wire:model="exemptFromLimit"
                               class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron" />
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Undanta från bokningsgräns</span>
                    </label>
                    <p class="mt-1 ml-6 text-xs text-zinc-500 dark:text-zinc-400">Räknas inte mot personens maxantal aktiva bokningar.</p>
                </div>
            </div>
        @endif

        {{-- Non-member notice --}}
        @if (! auth()->user()->canBook())
            <div class="rounded-lg border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/50 px-4 py-3 text-sm text-amber-800 dark:text-amber-300">
                {{ \App\Models\SystemSetting::get('non_member_message', 'Ditt konto är inaktiverat för bokningar tills årets medlemsavgift är betald.') }}
            </div>
        @endif

        {{-- Submit --}}
        <div class="flex justify-end">
            <button type="submit"
                    class="rounded-lg bg-brand px-6 py-2.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    wire:loading.attr="disabled"
                    @if (! auth()->user()->canBook()) disabled @endif>
                <span wire:loading.remove>
                    @if ($bookAll)
                        Boka alla rum
                    @elseif ($resource->requires_approval)
                        Skicka bokningsförfrågan
                    @else
                        Bekräfta bokning
                    @endif
                </span>
                <span wire:loading>Sparar…</span>
            </button>
        </div>

    </form>

@if (! $fixedResource)
    </div>
@endif
</div>
</div>
