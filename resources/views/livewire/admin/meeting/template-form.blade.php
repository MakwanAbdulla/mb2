<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center gap-3">
        <a href="{{ route('admin.meetings.templates.index') }}" wire:navigate
           class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Mallar</a>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
            {{ $template ? 'Redigera mall' : 'Ny mötesMall' }}
        </h1>
    </div>

    <form wire:submit="save" class="space-y-6">

        @php $inp = 'w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron px-3 py-2'; @endphp

        {{-- Grunduppgifter --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 space-y-4">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 uppercase tracking-wide">Grunduppgifter</h2>

            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Mallnamn *</label>
                    <input wire:model="name" type="text" class="{{ $inp }} @error('name') border-red-300 dark:border-red-600 @enderror" />
                    @error('name') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Mötestyp *</label>
                    <select wire:model="meetingType" class="{{ $inp }}">
                        @foreach ($meetingTypes as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Sektion</label>
                    <select wire:model="sectionId" class="{{ $inp }}">
                        <option value="">Global (alla sektioner)</option>
                        @foreach ($sections as $section)
                            <option value="{{ $section->id }}">{{ $section->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                        Kallelsetid *
                        <span class="font-normal text-zinc-400">(dagar i förväg)</span>
                    </label>
                    <input wire:model="defaultNoticeDays" type="number" min="1" max="365"
                           class="{{ $inp }} @error('defaultNoticeDays') border-red-300 dark:border-red-600 @enderror" />
                    @error('defaultNoticeDays') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-col gap-3 pt-6">
                    <div class="flex items-center gap-3">
                        <input wire:model="isExtraordinary" type="checkbox" id="extraordinary"
                               class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron" />
                        <label for="extraordinary" class="text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            Extramöte
                        </label>
                    </div>
                    <div class="flex items-center gap-3">
                        <input wire:model="includeAbsencesInMinutes" type="checkbox" id="absences"
                               class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron" />
                        <label for="absences" class="text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            Visa frånvarande i protokollet
                        </label>
                    </div>
                </div>

                <div class="col-span-2">
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                        Beskrivning <span class="font-normal text-zinc-400">(valfritt)</span>
                    </label>
                    <textarea wire:model="description" rows="2" class="{{ $inp }}"></textarea>
                </div>
            </div>
        </div>

        {{-- Agendapunkter --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 space-y-3">
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 uppercase tracking-wide">Dagordning</h2>
                @error('items') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="space-y-3">
                @foreach ($items as $i => $item)
                    <div wire:key="item-{{ $item['_key'] }}"
                         class="rounded-lg border {{ $item['is_required'] ? 'border-brand/40 bg-brand/5' : 'border-zinc-200 dark:border-zinc-700' }} p-4 space-y-3">

                        <div class="flex items-start gap-3">
                            {{-- Sort order number --}}
                            <span class="mt-2 flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 text-xs font-semibold text-zinc-500 dark:text-zinc-400">
                                {{ $i + 1 }}
                            </span>

                            <div class="flex-1 grid grid-cols-3 gap-3">
                                <div class="col-span-2">
                                    <input wire:model="items.{{ $i }}.title" type="text"
                                           placeholder="Rubrik *"
                                           class="{{ $inp }} @error('items.'.$i.'.title') border-red-300 dark:border-red-600 @enderror" />
                                    @error('items.'.$i.'.title')
                                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <select wire:model="items.{{ $i }}.item_type" class="{{ $inp }}">
                                        <option value="standard">Standard</option>
                                        <option value="vote">Beslutspunkt</option>
                                        <option value="election">Val</option>
                                        <option value="info">Information</option>
                                    </select>
                                </div>
                                <div class="col-span-3">
                                    <input wire:model="items.{{ $i }}.description" type="text"
                                           placeholder="Instruktion eller stadgetext (visas under rubriken)"
                                           class="{{ $inp }} text-zinc-500 dark:text-zinc-400" />
                                </div>
                                <div class="col-span-3">
                                    <textarea wire:model="items.{{ $i }}.minutes_template"
                                              rows="2"
                                              placeholder="Förberedande protokolltext (fylls i automatiskt när sekreteraren öppnar punkten)"
                                              class="{{ $inp }} text-zinc-500 dark:text-zinc-400 text-xs"></textarea>
                                </div>
                            </div>

                            {{-- Controls --}}
                            <div class="flex flex-col items-center gap-1 shrink-0">
                                <x-sort-buttons :id="$i" :index="$i" :count="count($items)" vertical />
                                @if (!$item['is_required'])
                                    <button type="button" wire:click="removeItem({{ $i }})"
                                            class="rounded p-1 text-red-400 hover:text-red-600 dark:hover:text-red-300">
                                        <x-bootstrap-icon name="x-lg" class="size-4" />
                                    </button>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-2 ml-9">
                            <input wire:model="items.{{ $i }}.is_required" type="checkbox"
                                   id="req-{{ $i }}"
                                   class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron" />
                            <label for="req-{{ $i }}" class="text-xs text-zinc-500 dark:text-zinc-400 cursor-pointer">
                                Obligatorisk (kan inte tas bort när mötet skapas)
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>

            <button type="button" wire:click="addItem"
                    class="mt-2 flex items-center gap-2 text-sm text-complement dark:text-complement-light hover:underline">
                <x-bootstrap-icon name="plus-lg" class="size-4" />
                Lägg till punkt
            </button>
        </div>

        {{-- Rollmappningar --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 space-y-3">
            <div class="flex items-center justify-between mb-2">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 uppercase tracking-wide">Rollmappningar</h2>
                    <p class="text-xs text-zinc-400 dark:text-zinc-500 mt-0.5">Sektionsroller som automatiskt får en mötesroll när mötet skapas.</p>
                </div>
            </div>

            @if (count($roleMappings) > 0)
            <div class="space-y-2">
                @foreach ($roleMappings as $i => $mapping)
                <div wire:key="role-{{ $mapping['_key'] }}" class="flex items-center gap-3">
                    <input wire:model="roleMappings.{{ $i }}.section_role_keyword"
                           type="text"
                           placeholder="Sektionsrolltitel, t.ex. ordförande"
                           class="{{ $inp }} flex-1" />
                    <select wire:model="roleMappings.{{ $i }}.meeting_role" class="{{ $inp }} w-44">
                        <option value="chair">Mötesordförande</option>
                        <option value="secretary">Mötessekreterare</option>
                        <option value="adjuster">Justerare</option>
                        <option value="vote_counter">Rösträknare</option>
                        <option value="adjunct">Adjungerad</option>
                        <option value="attendee">Deltagare</option>
                    </select>
                    <button type="button" wire:click="removeRoleMapping({{ $i }})"
                            class="shrink-0 rounded p-1 text-red-400 hover:text-red-600 dark:hover:text-red-300">
                        <x-bootstrap-icon name="x-lg" class="size-4" />
                    </button>
                </div>
                @endforeach
            </div>
            @endif

            <button type="button" wire:click="addRoleMapping"
                    class="flex items-center gap-2 text-sm text-complement dark:text-complement-light hover:underline">
                <x-bootstrap-icon name="plus-lg" class="size-4" />
                Lägg till rollmappning
            </button>
        </div>

        {{-- Submit --}}
        <div class="flex items-center justify-between">
            <a href="{{ route('admin.meetings.templates.index') }}" wire:navigate
               class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">Avbryt</a>
            <button type="submit"
                    class="rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors disabled:opacity-50"
                    wire:loading.attr="disabled">
                <span wire:loading.remove>{{ $template ? 'Spara mall' : 'Skapa mall' }}</span>
                <span wire:loading>Sparar…</span>
            </button>
        </div>

    </form>

</div>
