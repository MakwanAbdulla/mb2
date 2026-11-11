<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 space-y-6">

    {{-- Header --}}
    <div>
        <a href="{{ route('meetings.mine') }}" wire:navigate
           class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Mina möten</a>
        <h1 class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $meeting->title }}</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            {{ $meeting->scheduled_at->isoFormat('D MMMM YYYY, HH:mm') }}
            @if ($meeting->location) · {{ $meeting->location }} @endif
            @if ($meeting->section) · {{ $meeting->section->name }} @endif
        </p>
    </div>

    {{-- Din status --}}
    @if ($attendee)
        <div class="rounded-lg border px-4 py-3 text-sm
                    {{ $attendee->present === true
                        ? 'border-green-200 dark:border-green-700 bg-green-50 dark:bg-green-950 text-green-700 dark:text-green-300'
                        : 'border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 text-zinc-600 dark:text-zinc-300' }}">
            @if ($attendee->present === true)
                Du deltog i detta möte.
                @if ($attendee->role !== 'attendee') Som {{ $attendee->roleLabel() }}. @endif
            @elseif ($attendee->present === false)
                Du var frånvarande vid detta möte.
            @else
                Du är kallad till detta möte.
            @endif
        </div>
    @endif

    {{-- Justerat --}}
    @if ($meeting->published_at)
        <div class="rounded-lg border border-green-200 dark:border-green-700 bg-green-50 dark:bg-green-950 px-4 py-3 text-sm text-green-700 dark:text-green-300">
            Protokollet är justerat och publicerat {{ $meeting->published_at->isoFormat('D MMM YYYY') }}.
        </div>
    @endif

    {{-- Dagordning --}}
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm divide-y divide-zinc-100 dark:divide-zinc-800">
        <div class="px-5 py-4">
            <h2 class="font-semibold text-zinc-900 dark:text-white">Dagordning</h2>
        </div>

        @forelse ($agendaItems as $i => $item)
            <div class="px-5 py-4 space-y-2">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 text-xs font-semibold text-zinc-500 dark:text-zinc-400">
                        {{ $i + 1 }}
                    </span>
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-zinc-900 dark:text-white text-sm">{{ $item->title }}</span>
                            @if ($item->item_type === 'vote')
                                <span class="rounded-full bg-brand/10 px-2 py-0.5 text-xs font-medium text-brand-dark dark:text-brand">Beslut</span>
                            @elseif ($item->item_type === 'election')
                                <span class="rounded-full bg-complement/10 px-2 py-0.5 text-xs font-medium text-complement dark:text-complement-light">Val</span>
                            @endif
                        </div>
                        @if ($item->description)
                            <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">{{ $item->description }}</p>
                        @endif

                        {{-- Bilagor (bara protokollbilagor är synliga för medlemmar) --}}
                        @php $visibleDocs = $item->documents->where('is_protocol_attachment', true); @endphp
                        @if ($visibleDocs->isNotEmpty())
                            <ul class="mt-1.5 space-y-1">
                                @foreach ($visibleDocs as $doc)
                                    <li class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                        <x-bootstrap-icon name="paperclip" class="size-3 shrink-0" />
                                        <span>{{ $doc->original_name }}</span>
                                        <span class="text-zinc-300 dark:text-zinc-600">{{ $doc->formattedSize() }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        {{-- Protokolltext --}}
                        @if ($showMinutes && $item->minutesItem)
                            <div class="mt-3 space-y-2">
                                @if ($item->minutesItem->content)
                                    <p class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $item->minutesItem->content }}</p>
                                @endif
                                @if ($item->minutesItem->is_decision && $item->minutesItem->decision)
                                    <div class="rounded-lg border border-brand/30 bg-brand/5 px-3 py-2">
                                        <p class="text-xs font-semibold text-brand-dark dark:text-brand mb-0.5">Beslut</p>
                                        <p class="text-sm text-zinc-800 dark:text-zinc-200">{{ $item->minutesItem->decision }}</p>
                                    </div>
                                @endif
                            </div>
                        @elseif (!$showMinutes && $meeting->status === 'closed')
                            <p class="mt-2 text-xs text-zinc-400 italic">Protokollet är ännu inte justerat.</p>
                        @endif
                    </div>
                </div>

                {{-- Omröstning --}}
                @if($item->polls->isNotEmpty())
                    <livewire:shared.poll-panel
                        :voteable-type="'agenda_item'"
                        :voteable-id="$item->id"
                        :meeting-id="$meeting->id"
                        :key="'poll-'.$item->id" />
                @endif
            </div>
        @empty
            <div class="px-5 py-8 text-center text-sm text-zinc-400">Dagordning ej publicerad ännu.</div>
        @endforelse
    </div>

    {{-- Närvaro (bara när publicerat) --}}
    @if ($meeting->published_at && ($presentAttendees->isNotEmpty() || $absentAttendees->isNotEmpty()))
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-5 space-y-3">
            <h2 class="font-semibold text-zinc-900 dark:text-white text-sm">Närvaro</h2>

            @if ($presentAttendees->isNotEmpty())
                <div>
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Närvarande</p>
                    <p class="text-sm text-zinc-700 dark:text-zinc-300">
                        {{ $presentAttendees->map(fn ($a) => $a->displayName())->join(', ') }}
                    </p>
                </div>
            @endif

            @if ($absentAttendees->isNotEmpty())
                <div>
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Frånvarande</p>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $absentAttendees->map(fn ($a) => $a->displayName())->join(', ') }}
                    </p>
                </div>
            @endif
        </div>
    @endif

</div>
