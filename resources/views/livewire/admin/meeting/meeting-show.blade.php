<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 space-y-6">

    {{-- Header --}}
    <div class="flex items-start justify-between gap-4">
        <div>
            <a href="{{ route('admin.meetings.index') }}" wire:navigate
               class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Möten</a>
            <h1 class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $meeting->title }}</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ $meeting->typeLabel() }} · {{ $meeting->scheduled_at->isoFormat('D MMMM YYYY, HH:mm') }}
                @if ($meeting->location) · {{ $meeting->location }} @endif
                @if ($meeting->section) · {{ $meeting->section->name }} @endif
                @if ($meeting->meet_link)
                    ·
                    <a href="{{ $meeting->meet_link }}" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 text-complement hover:underline dark:text-complement-light">
                        <x-bootstrap-icon name="camera-video" class="size-3.5" />
                        Google Meet
                    </a>
                @endif
            </p>
            @if ($meeting->motion_deadline)
                <p class="mt-0.5 text-xs {{ $meeting->motionsOpen() ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-400' }}">
                    Motionsfrist: {{ $meeting->motion_deadline->isoFormat('D MMM YYYY') }}
                    {{ $meeting->motionsOpen() ? '— öppen' : '— stängd' }}
                </p>
            @endif
        </div>
        <div class="flex items-center gap-3 shrink-0">
            @can('meetings.manage')
            @if ($meeting->status !== 'cancelled' && $meeting->status !== 'published')
                <button wire:click="advanceStatus"
                        class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                    {{ $meeting->nextStatusLabel() }}
                </button>
            @endif
            @if (!in_array($meeting->status, ['draft', 'cancelled']))
                <button wire:click="revertStatus"
                        class="rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-1.5 text-sm text-zinc-500 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                    ← Ångra steg
                </button>
            @endif
            @if ($meeting->meet_link)
                <a href="{{ $meeting->meet_link }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-1.5 text-sm text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                    <x-bootstrap-icon name="camera-video" class="size-4" />
                    Öppna Meet
                </a>
                <button wire:click="removeMeetLink"
                        wire:confirm="Ta bort Meet-länken från mötet?"
                        class="rounded-lg border border-zinc-200 dark:border-zinc-700 px-2 py-1.5 text-xs text-zinc-400 hover:text-red-500 dark:hover:text-red-400">
                    <x-bootstrap-icon name="camera-video-off" class="size-4" />
                </button>
            @elseif($workspaceConfigured)
                <button wire:click="generateMeetLink"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-1.5 text-sm text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800 disabled:opacity-50">
                    <span wire:loading.remove wire:target="generateMeetLink">
                        <x-bootstrap-icon name="camera-video" class="size-4" />
                    </span>
                    <span wire:loading wire:target="generateMeetLink">…</span>
                    <span wire:loading.remove wire:target="generateMeetLink">Meet</span>
                </button>
            @endif
            <a href="{{ route('admin.meetings.edit', $meeting) }}" wire:navigate
               class="rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-1.5 text-sm text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                Redigera
            </a>
            @endcan
        </div>
    </div>

    @php
        $statusColors = [
            'draft'            => 'border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 text-zinc-600 dark:text-zinc-400',
            'notice_sent'      => 'border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950 text-blue-700 dark:text-blue-300',
            'ongoing'          => 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950 text-amber-700 dark:text-amber-300',
            'pending_approval' => 'border-complement/30 bg-complement/5 text-complement dark:text-complement-light',
            'approved'         => 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950 text-green-700 dark:text-green-300',
            'published'        => 'border-green-300 dark:border-green-700 bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200',
            'cancelled'        => 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950 text-red-700 dark:text-red-300',
        ];
    @endphp
    @if(session('meet_error'))
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
            {{ session('meet_error') }}
        </div>
    @endif

    <div class="rounded-lg border px-4 py-3 text-sm {{ $statusColors[$meeting->status] ?? $statusColors['draft'] }}">
        {{ $meeting->statusLabel() }}
        @if ($meeting->approved_at) · Justerat {{ $meeting->approved_at->isoFormat('D MMM YYYY') }} @endif
        @if ($meeting->published_at) · Publicerat {{ $meeting->published_at->isoFormat('D MMM YYYY') }} @endif
    </div>

    <div class="grid grid-cols-3 gap-6">

        {{-- Dagordning + Protokoll (2/3) --}}
        <div class="col-span-2 space-y-4">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Dagordning och protokoll</h2>

            @if ($agendaItems->isEmpty())
                <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-8 text-center text-sm text-zinc-400">
                    Inga agendapunkter ännu.
                    @can('meetings.manage')
                        Lägg till punkter via <a href="{{ route('admin.meetings.edit', $meeting) }}" class="underline" wire:navigate>redigera</a> eller direkt nedan.
                    @endcan
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($agendaItems as $i => $item)
                        @php $minutes = $minutesMap[$item->id] ?? null; @endphp
                        <div class="rounded-xl border {{ $item->is_required ? 'border-brand/30' : 'border-zinc-200 dark:border-zinc-700' }} bg-white dark:bg-zinc-900 shadow-sm overflow-hidden">

                            {{-- Punkt-header --}}
                            <div class="flex items-start gap-3 px-4 py-3">
                                <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full
                                             {{ $minutes ? 'bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400' }}
                                             text-xs font-semibold">
                                    {{ $i + 1 }}
                                </span>
                                <div class="flex-1 min-w-0">
                                    @if (in_array($meeting->status, ['draft', 'notice_sent']) && auth()->user()->can('meetings.manage'))
                                        <div class="flex items-center gap-2 mb-1">
                                            <input type="text"
                                                   value="{{ $item->title }}"
                                                   wire:blur="saveAgendaField({{ $item->id }}, 'title', $event.target.value)"
                                                   class="flex-1 rounded border border-transparent hover:border-zinc-300 dark:hover:border-zinc-600 focus:border-brand focus:ring-1 focus:ring-brand-saffron bg-transparent px-1.5 py-0.5 text-sm font-medium text-zinc-900 dark:text-white" />
                                            @if ($item->item_type !== 'standard')
                                                <span class="shrink-0 rounded-full bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                                    {{ \App\Models\Meeting\MeetingTemplateItem::ITEM_TYPES[$item->item_type] ?? $item->item_type }}
                                                </span>
                                            @endif
                                        </div>
                                        <input type="text"
                                               value="{{ $item->description }}"
                                               placeholder="Beskrivning / stadgetext…"
                                               wire:blur="saveAgendaField({{ $item->id }}, 'description', $event.target.value)"
                                               class="w-full rounded border border-transparent hover:border-zinc-300 dark:hover:border-zinc-600 focus:border-brand focus:ring-1 focus:ring-brand-saffron bg-transparent px-1.5 py-0.5 text-xs text-zinc-500 dark:text-zinc-400" />
                                    @else
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-zinc-900 dark:text-white text-sm">{{ $item->title }}</span>
                                            @if ($item->item_type !== 'standard')
                                                <span class="rounded-full bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                                    {{ \App\Models\Meeting\MeetingTemplateItem::ITEM_TYPES[$item->item_type] ?? $item->item_type }}
                                                </span>
                                            @endif
                                        </div>
                                        @if ($item->description)
                                            <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">{{ $item->description }}</p>
                                        @endif
                                    @endif
                                </div>
                                @can('meetings.manage')
                                @if ($editingMinutesId !== $item->id)
                                    <button wire:click="editMinutes({{ $item->id }})"
                                            class="shrink-0 text-xs text-complement dark:text-complement-light hover:underline">
                                        {{ $minutes ? 'Redigera' : 'Skriv protokoll' }}
                                    </button>
                                    @if ($minutes?->is_decision)
                                        <button wire:click="createTaskFromMinutes({{ $item->id }})"
                                                title="Skapa uppgift från det här beslutet"
                                                class="shrink-0 text-xs text-zinc-400 hover:text-brand-orange dark:hover:text-brand transition-colors">
                                            + Uppgift
                                        </button>
                                    @endif
                                @endif
                                <x-sort-buttons prefix="moveAgendaItem" :id="$item->id" :index="$i" :count="count($agendaItems)" />
                                @endcan
                            </div>

                            {{-- Förberedande protokolltext (utkastläge) --}}
                            @if (in_array($meeting->status, ['draft', 'notice_sent']) && auth()->user()->can('meetings.manage'))
                                <div class="border-t border-zinc-100 dark:border-zinc-800 px-4 py-3 bg-zinc-50/50 dark:bg-zinc-800/30">
                                    <label class="block text-xs font-medium text-zinc-400 dark:text-zinc-500 mb-1">Förberedd protokolltext</label>
                                    <textarea
                                        wire:blur="saveAgendaField({{ $item->id }}, 'minutes_template', $event.target.value)"
                                        rows="2"
                                        placeholder="Fylls i automatiskt när sekreteraren öppnar punkten under mötet…"
                                        class="w-full rounded border border-transparent hover:border-zinc-300 dark:hover:border-zinc-600 focus:border-brand focus:ring-1 focus:ring-brand-saffron bg-transparent px-1.5 py-0.5 text-xs text-zinc-600 dark:text-zinc-300 resize-none">{{ $item->minutes_template }}</textarea>
                                </div>
                            @endif

                            {{-- Befintlig protokolltext --}}
                            @if ($minutes && $editingMinutesId !== $item->id)
                                <div class="border-t border-zinc-100 dark:border-zinc-800 px-4 py-3 bg-zinc-50/50 dark:bg-zinc-800/30">
                                    @if ($minutes->content)
                                        <p class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $minutes->content }}</p>
                                    @endif
                                    @if ($minutes->is_decision && $minutes->decision)
                                        <div class="mt-2 rounded-lg border border-brand/30 bg-brand/5 px-3 py-2">
                                            <p class="text-xs font-semibold text-brand-dark dark:text-brand mb-0.5">Beslut</p>
                                            <p class="text-sm text-zinc-800 dark:text-zinc-200">{{ $minutes->decision }}</p>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            {{-- Redigera protokoll --}}
                            @if ($editingMinutesId === $item->id)
                                <div class="border-t border-zinc-100 dark:border-zinc-800 px-4 py-4 bg-zinc-50/50 dark:bg-zinc-800/30 space-y-3">
                                    <div>
                                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Protokolltext</label>
                                        <textarea wire:model="editingContent" rows="4"
                                                  class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron"></textarea>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <input wire:model.live="editingIsDecision" type="checkbox" id="is-decision"
                                               class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron" />
                                        <label for="is-decision" class="text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">Beslutspunkt</label>
                                    </div>
                                    @if ($editingIsDecision)
                                        <div>
                                            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Beslutssats</label>
                                            <textarea wire:model="editingDecision" rows="2"
                                                      class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron"></textarea>
                                        </div>
                                    @endif
                                    <div class="flex gap-3">
                                        <button wire:click="saveMinutes"
                                                class="rounded-lg bg-brand px-4 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                                            Spara
                                        </button>
                                        <button wire:click="cancelMinutes"
                                                class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400">
                                            Avbryt
                                        </button>
                                    </div>
                                </div>
                            @endif

                            {{-- Omröstning --}}
                            @if(in_array($meeting->status, ['ongoing', 'pending_approval']) || $item->polls()->exists())
                                <livewire:shared.poll-panel
                                    :voteable-type="'agenda_item'"
                                    :voteable-id="$item->id"
                                    :meeting-id="$meeting->id"
                                    :key="'poll-'.$item->id" />
                            @endif

                            {{-- Bilagor --}}
                            @if ($item->documents->isNotEmpty() || auth()->user()->can('meetings.manage'))
                                <div class="border-t border-zinc-100 dark:border-zinc-800 px-4 py-3 space-y-2">
                                    @if ($item->documents->isNotEmpty())
                                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Underlag</p>
                                        <ul class="space-y-1">
                                            @foreach ($item->documents as $doc)
                                                <li class="flex items-center gap-2 text-xs">
                                                    <x-bootstrap-icon name="paperclip" class="size-3.5 shrink-0 text-zinc-400" />
                                                    <span class="flex-1 truncate text-zinc-700 dark:text-zinc-300">{{ $doc->original_name }}</span>
                                                    <span class="text-zinc-400">{{ $doc->formattedSize() }}</span>
                                                    @can('meetings.manage')
                                                    <button wire:click="toggleProtocolAttachment({{ $doc->id }})"
                                                            title="{{ $doc->is_protocol_attachment ? 'Ingår i protokollet — klicka för att göra till internt underlag' : 'Internt underlag — klicka för att inkludera i protokollet' }}"
                                                            class="rounded px-1.5 py-0.5 text-xs font-medium transition-colors
                                                                   {{ $doc->is_protocol_attachment
                                                                       ? 'bg-brand/20 text-brand-dark dark:text-brand'
                                                                       : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-400 dark:text-zinc-500' }}">
                                                        {{ $doc->is_protocol_attachment ? 'Protokoll' : 'Internt' }}
                                                    </button>
                                                    <button wire:click="deleteDocument({{ $doc->id }})"
                                                            wire:confirm="Ta bort {{ $doc->original_name }}?"
                                                            class="text-red-400 hover:text-red-600 dark:hover:text-red-300">
                                                        <x-bootstrap-icon name="x-lg" class="size-3" />
                                                    </button>
                                                    @endcan
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    @can('meetings.manage')
                                    <div class="flex items-center gap-2">
                                        <label class="cursor-pointer text-xs text-complement dark:text-complement-light hover:underline">
                                            + Bifoga fil
                                            <input type="file"
                                                   wire:model="agendaFiles.{{ $item->id }}"
                                                   class="sr-only" />
                                        </label>
                                        @if (!empty($agendaFiles[$item->id]))
                                            <button wire:click="uploadAgendaFile({{ $item->id }})"
                                                    class="rounded bg-brand px-2 py-0.5 text-xs font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                                                Ladda upp
                                            </button>
                                            <span wire:loading wire:target="uploadAgendaFile({{ $item->id }})" class="text-xs text-zinc-400">Laddar upp…</span>
                                        @endif
                                    </div>
                                    @error("agendaFiles.{$item->id}") <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                                    @endcan
                                </div>
                            @endif

                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Spara som / uppdatera mall (utkastläge) --}}
            @if (in_array($meeting->status, ['draft', 'notice_sent']))
            @can('meetings.manage')
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4 space-y-3">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Dagordningsmall</p>
                <div class="flex flex-wrap gap-2">
                    @if ($meeting->template_id)
                        <button wire:click="updateExistingTemplate"
                                wire:confirm="Skriv över mallens dagordning med punkterna från det här mötet?"
                                class="rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-1.5 text-xs text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                            Uppdatera mall "{{ $meeting->template?->name }}"
                        </button>
                    @endif
                    <button wire:click="$set('showTemplateSaveForm', true)"
                            class="rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-1.5 text-xs text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                        + Spara som ny mall
                    </button>
                </div>
                @if ($showTemplateSaveForm)
                    <div class="flex gap-2">
                        <input wire:model="newTemplateName"
                               wire:keydown.enter="saveAsNewTemplate"
                               type="text"
                               placeholder="Mallnamn…"
                               class="flex-1 rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm px-3 py-1.5 focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
                        <button wire:click="saveAsNewTemplate"
                                class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                            Skapa mall
                        </button>
                        <button wire:click="$set('showTemplateSaveForm', false)"
                                class="text-sm text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                            Avbryt
                        </button>
                    </div>
                    @error('newTemplateName') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                @endif
            </div>
            @endcan
            @endif

            {{-- Lägg till punkt under pågående möte --}}
            @can('meetings.manage')
            <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-4">
                <p class="mb-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">Ny punkt under mötet</p>
                <div class="flex gap-2">
                    <input wire:model="newAgendaItemTitle"
                           wire:keydown.enter="addAgendaItem"
                           type="text"
                           placeholder="Rubrik…"
                           class="flex-1 rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
                    <select wire:model="newAgendaItemType"
                            class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                        <option value="standard">Standard</option>
                        <option value="vote">Beslut</option>
                        <option value="election">Val</option>
                        <option value="info">Information</option>
                    </select>
                    <button wire:click="addAgendaItem"
                            class="rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors whitespace-nowrap">
                        + Lägg till
                    </button>
                </div>
                @error('newAgendaItemTitle') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            @endcan
        </div>

        {{-- Deltagare (1/3) --}}
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Deltagare</h2>
                @can('meetings.manage')
                <button wire:click="inviteMembers"
                        wire:confirm="{{ $meeting->section ? 'Bjud in alla aktiva i ' . $meeting->section->name . '?' : 'Bjud in alla aktiva medlemmar?' }}"
                        class="text-xs text-complement dark:text-complement-light hover:underline">
                    + {{ $meeting->section ? $meeting->section->name : 'Alla aktiva' }}
                </button>
                @endcan
            </div>

            {{-- Sök och lägg till --}}
            @can('meetings.manage')
            <div class="relative">
                <input wire:model.live.debounce.300ms="searchAttendee" type="text"
                       placeholder="Sök och lägg till…"
                       class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
                @if (count($searchResults) > 0 || strlen($searchAttendee) >= 2)
                    <div class="absolute z-10 mt-1 w-full rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-lg divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($searchResults as $member)
                            <button wire:click="addAttendee({{ $member->id }})"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-left hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $member->full_name }}</span>
                                <span class="text-zinc-400 text-xs">{{ $member->email }}</span>
                                @if($member->status !== 'active')
                                    <span class="ml-auto rounded-full bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                        {{ match($member->status) { 'inactive' => 'Inaktiv', 'expired' => 'Utgången', default => $member->status } }}
                                    </span>
                                @endif
                            </button>
                        @endforeach
                        @if(strlen($searchAttendee) >= 2)
                            <button wire:click="addExternalAttendee"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-left text-zinc-500 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                <x-bootstrap-icon name="person-plus" class="size-3.5 shrink-0" />
                                Lägg till "{{ $searchAttendee }}" som extern
                            </button>
                        @endif
                    </div>
                @endif
            </div>
            @endcan

            {{-- Statistik --}}
            @php
                $present = $attendees->where('present', true)->count();
                $absent  = $attendees->where('present', false)->count();
                $voting  = $attendees->where('present', true)->where('voting_rights', true)->count();
            @endphp
            <div class="flex gap-3 text-xs text-zinc-500 dark:text-zinc-400">
                <span class="text-green-600 dark:text-green-400 font-medium">{{ $present }} när.</span>
                <span>{{ $absent }} fr.</span>
                <span>{{ $voting }} röstber.</span>
            </div>

            {{-- Lista --}}
            <div class="space-y-1">
                @forelse ($attendees as $attendee)
                    @php
                        $presentClass = match($attendee->present) {
                            true  => 'border-green-200 dark:border-green-800 bg-green-50/50 dark:bg-green-950/30',
                            false => 'border-zinc-100 dark:border-zinc-800 opacity-60',
                            default => 'border-zinc-200 dark:border-zinc-700',
                        };
                    @endphp
                    <div class="flex items-center gap-2 rounded-lg border {{ $presentClass }} px-2 py-1.5">
                        {{-- Närvaro-toggle --}}
                        @can('meetings.manage')
                        <button wire:click="togglePresent({{ $attendee->id }})"
                                class="shrink-0 size-5 rounded-full flex items-center justify-center text-xs
                                       {{ $attendee->present === true  ? 'bg-green-500 text-white'
                                        : ($attendee->present === false ? 'bg-zinc-300 dark:bg-zinc-600 text-zinc-500'
                                        : 'border border-dashed border-zinc-300 dark:border-zinc-600 text-zinc-300') }}">
                            @if ($attendee->present === true) ✓ @elseif ($attendee->present === false) – @endif
                        </button>
                        @endcan

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1">
                                <span class="truncate text-xs font-medium text-zinc-900 dark:text-white">
                                    {{ $attendee->displayName() }}
                                </span>
                                @if (!$attendee->voting_rights)
                                    <span class="shrink-0 text-xs text-amber-500" title="Ingen rösträtt">∅</span>
                                @endif
                                @if ($attendee->membership_valid === false)
                                    <span class="shrink-0 text-xs text-red-500" title="Ogiltigt medlemskap">!</span>
                                @endif
                            </div>
                            @can('meetings.manage')
                            <select wire:change="setAttendeeRole({{ $attendee->id }}, $event.target.value)"
                                    class="mt-0.5 rounded border border-zinc-200 dark:border-zinc-700 bg-transparent text-xs text-zinc-500 dark:text-zinc-400 py-0 pl-0.5 pr-4 focus:border-brand focus:ring-0">
                                @foreach (\App\Models\Meeting\MeetingAttendee::ROLES as $val => $label)
                                    <option value="{{ $val }}" @selected($attendee->role === $val)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @else
                                @if ($attendee->role !== 'attendee')
                                    <span class="text-xs text-zinc-400">{{ $attendee->roleLabel() }}</span>
                                @endif
                            @endcan
                        </div>

                        @can('meetings.manage')
                        <button wire:click="removeAttendee({{ $attendee->id }})"
                                class="shrink-0 text-zinc-300 dark:text-zinc-600 hover:text-red-400 transition-colors">
                            <x-bootstrap-icon name="x-lg" class="size-3.5" />
                        </button>
                        @endcan
                    </div>
                @empty
                    <p class="text-xs text-zinc-400 dark:text-zinc-500 text-center py-4">Inga deltagare registrerade.</p>
                @endforelse
            </div>

            {{-- Inkomna ärenden (om mötet accepterar motioner eller sektionen tar emot ärenden) --}}
            @can('submissions.view')
            @if ($pendingSubmissions->isNotEmpty())
            <div class="space-y-2">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Inkomna ärenden</h2>
                <p class="text-xs text-zinc-400">Ärenden som inte lagts till på dagordningen ännu.</p>
                @foreach ($pendingSubmissions as $sub)
                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-3 space-y-2">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <a href="{{ route('admin.submissions.show', $sub) }}" wire:navigate
                                   class="text-sm font-medium text-zinc-900 dark:text-white hover:text-complement dark:hover:text-complement-light">
                                    {{ $sub->title }}
                                </a>
                                <p class="text-xs text-zinc-400 mt-0.5">
                                    {{ $sub->typeLabel() }} · {{ $sub->createdBy?->name ?? '—' }}
                                </p>
                            </div>
                            @can('meetings.manage')
                            <button wire:click="addSubmissionToAgenda({{ $sub->id }})"
                                    class="shrink-0 rounded-lg bg-brand px-2.5 py-1 text-xs font-semibold text-brand-dark hover:bg-brand-saffron transition-colors whitespace-nowrap">
                                + Dagordning
                            </button>
                            @endcan
                        </div>
                        @if ($sub->body)
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 line-clamp-2">{{ $sub->body }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
            @endif
            @endcan

        </div>

    </div>

</div>
