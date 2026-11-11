<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 space-y-6">

    @php
        $inp = 'w-full rounded border border-transparent hover:border-zinc-300 dark:hover:border-zinc-600 focus:border-brand focus:ring-1 focus:ring-brand-saffron bg-transparent px-1.5 py-0.5 text-sm text-zinc-900 dark:text-white';
        $inpSm = 'w-full rounded border border-transparent hover:border-zinc-300 dark:hover:border-zinc-600 focus:border-brand focus:ring-1 focus:ring-brand-saffron bg-transparent px-1.5 py-0.5 text-xs text-zinc-500 dark:text-zinc-400';
    @endphp

    {{-- Header --}}
    <div class="flex items-start justify-between gap-4">
        <div>
            <a href="{{ route('admin.meetings.templates.index') }}" wire:navigate
               class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Mallar</a>
            <input type="text"
                   value="{{ $template->name }}"
                   wire:blur="saveField('name', $event.target.value)"
                   class="mt-1 block w-full rounded border border-transparent hover:border-zinc-300 dark:hover:border-zinc-600 focus:border-brand focus:ring-1 focus:ring-brand-saffron bg-transparent px-1.5 py-0.5 text-2xl font-bold text-zinc-900 dark:text-white" />
        </div>
    </div>

    {{-- Metadata --}}
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4">
        <div class="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-4">
            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-0.5">Mötestyp</label>
                <select wire:change="saveField('meeting_type', $event.target.value)"
                        class="w-full rounded border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-sm text-zinc-900 dark:text-white px-2 py-1 focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                    @foreach (\App\Models\Meeting\MeetingTemplate::TYPES as $val => $label)
                        <option value="{{ $val }}" @selected($template->meeting_type === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-0.5">Sektion</label>
                <select wire:change="saveField('section_id', $event.target.value)"
                        class="w-full rounded border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-sm text-zinc-900 dark:text-white px-2 py-1 focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                    <option value="">Global</option>
                    @foreach ($sections as $section)
                        <option value="{{ $section->id }}" @selected($template->section_id === $section->id)>{{ $section->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-0.5">Kallelsetid (dagar)</label>
                <input type="number" min="1" max="365"
                       value="{{ $template->default_notice_days }}"
                       wire:blur="saveField('default_notice_days', $event.target.value)"
                       class="w-full rounded border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-sm text-zinc-900 dark:text-white px-2 py-1 focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
            </div>
            <div class="flex items-center gap-2 pt-5">
                <input type="checkbox"
                       id="absences"
                       wire:change="saveField('include_absences_in_minutes', $event.target.checked)"
                       @checked($template->include_absences_in_minutes)
                       class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron" />
                <label for="absences" class="text-xs text-zinc-600 dark:text-zinc-300 cursor-pointer">Visa frånvaro i protokoll</label>
            </div>
            <div class="col-span-2 sm:col-span-4">
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-0.5">Beskrivning</label>
                <input type="text"
                       value="{{ $template->description }}"
                       placeholder="Valfri beskrivning av mallen…"
                       wire:blur="saveField('description', $event.target.value)"
                       class="{{ $inp }} border-zinc-200 dark:border-zinc-600 hover:border-zinc-300 dark:hover:border-zinc-500" />
            </div>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-6">

        {{-- Dagordning (2/3) --}}
        <div class="col-span-2 space-y-4">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Dagordning</h2>

            @if ($items->isEmpty())
                <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-8 text-center text-sm text-zinc-400">
                    Inga punkter än. Lägg till nedan.
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($items as $i => $item)
                        <div class="rounded-xl border {{ $item->is_required ? 'border-brand/30' : 'border-zinc-200 dark:border-zinc-700' }} bg-white dark:bg-zinc-900 shadow-sm overflow-hidden">

                            {{-- Punkt-header --}}
                            <div class="flex items-start gap-3 px-4 py-3">
                                <span class="mt-1.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-xs font-semibold">
                                    {{ $i + 1 }}
                                </span>
                                <div class="flex-1 min-w-0 space-y-1">
                                    <div class="flex items-center gap-2">
                                        <input type="text"
                                               value="{{ $item->title }}"
                                               placeholder="Rubrik *"
                                               wire:blur="saveItemField({{ $item->id }}, 'title', $event.target.value)"
                                               class="{{ $inp }} font-medium" />
                                        <select wire:change="saveItemType({{ $item->id }}, $event.target.value)"
                                                class="shrink-0 rounded border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-xs text-zinc-600 dark:text-zinc-300 px-2 py-1 focus:border-brand focus:ring-0">
                                            @foreach (\App\Models\Meeting\MeetingTemplateItem::ITEM_TYPES as $val => $label)
                                                <option value="{{ $val }}" @selected($item->item_type === $val)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <input type="text"
                                           value="{{ $item->description }}"
                                           placeholder="Instruktion / stadgetext…"
                                           wire:blur="saveItemField({{ $item->id }}, 'description', $event.target.value)"
                                           class="{{ $inpSm }}" />
                                    <textarea
                                        wire:blur="saveItemField({{ $item->id }}, 'minutes_template', $event.target.value)"
                                        rows="2"
                                        placeholder="Förberedd protokolltext (kopieras när sekreteraren öppnar punkten)…"
                                        class="{{ $inpSm }} resize-none">{{ $item->minutes_template }}</textarea>
                                    <div class="flex items-center gap-2 pt-0.5">
                                        <input type="checkbox"
                                               id="req-{{ $item->id }}"
                                               wire:change="toggleItemRequired({{ $item->id }})"
                                               @checked($item->is_required)
                                               class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron" />
                                        <label for="req-{{ $item->id }}" class="text-xs text-zinc-400 dark:text-zinc-500 cursor-pointer">
                                            Obligatorisk (kan inte tas bort när mötet skapas)
                                        </label>
                                    </div>
                                </div>
                                <div class="flex flex-col items-center gap-1 shrink-0 pt-1">
                                    <x-sort-buttons prefix="moveItem" :id="$item->id" :index="$i" :count="count($items)" vertical />
                                    @if (!$item->is_required)
                                        <button wire:click="removeItem({{ $item->id }})"
                                                wire:confirm="Ta bort punkten '{{ $item->title }}'?"
                                                class="rounded p-0.5 text-zinc-300 dark:text-zinc-600 hover:text-red-400">
                                            <x-bootstrap-icon name="x-lg" class="size-3.5" />
                                        </button>
                                    @endif
                                </div>
                            </div>

                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Ny punkt --}}
            <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-4">
                <p class="mb-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">Ny punkt</p>
                <div class="flex gap-2">
                    <input wire:model="newItemTitle"
                           wire:keydown.enter="addItem"
                           type="text"
                           placeholder="Rubrik…"
                           class="flex-1 rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
                    <select wire:model="newItemType"
                            class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                        <option value="standard">Standard</option>
                        <option value="vote">Beslut</option>
                        <option value="election">Val</option>
                        <option value="info">Information</option>
                    </select>
                    <button wire:click="addItem"
                            class="rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors whitespace-nowrap">
                        + Lägg till
                    </button>
                </div>
                @error('newItemTitle') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Rollmappningar (1/3) --}}
        <div class="space-y-4">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Rollmappningar</h2>
            <p class="text-xs text-zinc-400 dark:text-zinc-500">Sektionsroller som automatiskt får mötesroll när mötet skapas.</p>

            <div class="space-y-2">
                @forelse ($roleMappings as $mapping)
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2 space-y-1.5">
                        <input type="text"
                               value="{{ $mapping->section_role_keyword }}"
                               placeholder="Sektionsrolltitel, t.ex. ordförande"
                               wire:blur="saveRoleMappingField({{ $mapping->id }}, 'section_role_keyword', $event.target.value)"
                               class="{{ $inpSm }} border-zinc-200 dark:border-zinc-600" />
                        <div class="flex items-center gap-2">
                            <select wire:change="saveRoleMappingField({{ $mapping->id }}, 'meeting_role', $event.target.value)"
                                    class="flex-1 rounded border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-xs text-zinc-700 dark:text-zinc-300 px-1.5 py-1 focus:border-brand focus:ring-0">
                                @foreach (\App\Models\Meeting\MeetingAttendee::ROLES as $val => $label)
                                    <option value="{{ $val }}" @selected($mapping->meeting_role === $val)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <button wire:click="removeRoleMapping({{ $mapping->id }})"
                                    class="shrink-0 text-zinc-300 dark:text-zinc-600 hover:text-red-400">
                                <x-bootstrap-icon name="x-lg" class="size-3.5" />
                            </button>
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-zinc-400 dark:text-zinc-500">Inga rollmappningar.</p>
                @endforelse
            </div>

            <button wire:click="addRoleMapping"
                    class="flex items-center gap-1.5 text-sm text-complement dark:text-complement-light hover:underline">
                <x-bootstrap-icon name="plus-lg" class="size-4" />
                Lägg till mappning
            </button>
        </div>

    </div>

</div>
