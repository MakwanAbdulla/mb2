<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 space-y-6">

    @php
        $inp = 'w-full rounded border border-transparent hover:border-zinc-300 dark:hover:border-zinc-600 focus:border-brand focus:ring-1 focus:ring-brand-saffron bg-transparent px-1.5 py-0.5 text-sm text-zinc-900 dark:text-white';
        $statusColors = [
            'open'        => 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300',
            'in_progress' => 'bg-amber-100 dark:bg-amber-900 text-amber-700 dark:text-amber-300',
            'done'        => 'bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300',
            'cancelled'   => 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400',
        ];
    @endphp

    {{-- Header --}}
    <div>
        <a href="{{ route('admin.tasks.index') }}" wire:navigate
           class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Uppgifter</a>
        @can('tasks.manage')
        <input type="text"
               value="{{ $task->title }}"
               wire:blur="saveField('title', $event.target.value)"
               class="mt-1 block w-full rounded border border-transparent hover:border-zinc-300 dark:hover:border-zinc-600 focus:border-brand focus:ring-1 focus:ring-brand-saffron bg-transparent px-1.5 py-0.5 text-2xl font-bold text-zinc-900 dark:text-white" />
        @else
        <h1 class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $task->title }}</h1>
        @endcan
    </div>

    <div class="grid grid-cols-3 gap-6">

        {{-- Huvud (2/3) --}}
        <div class="col-span-2 space-y-6">

            {{-- Beskrivning --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Beskrivning</p>
                @can('tasks.manage')
                <textarea wire:blur="saveField('description', $event.target.value)"
                          rows="3"
                          placeholder="Beskriv uppgiften…"
                          class="{{ $inp }} resize-none">{{ $task->description }}</textarea>
                @else
                <p class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $task->description ?? '–' }}</p>
                @endcan
            </div>

            {{-- Kommentarer --}}
            <div class="space-y-4">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Kommentarer</h2>

                @forelse ($comments as $comment)
                    <div class="flex gap-3">
                        <div class="size-8 shrink-0 overflow-hidden rounded-full ring-1 ring-zinc-200 dark:ring-zinc-700">
                            @if ($comment->author?->avatar_path)
                                <img src="{{ $comment->author->avatarUrl(64) }}" alt="{{ $comment->author->name }}" class="size-full object-cover">
                            @else
                                <span class="flex size-full items-center justify-center bg-complement/20 text-complement dark:bg-complement/30 dark:text-complement-light text-xs font-bold">
                                    {{ collect(explode(' ', $comment->author->name ?? '?'))->map(fn ($n) => mb_strtoupper(mb_substr($n, 0, 1)))->take(2)->implode('') }}
                                </span>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $comment->author->name ?? '–' }}</span>
                                <span class="text-xs text-zinc-400">{{ $comment->created_at->isoFormat('D MMM YYYY, HH:mm') }}</span>
                                @if ($comment->edited_at)
                                    <span class="text-xs text-zinc-400">(redigerad)</span>
                                @endif
                            </div>

                            @if ($editingCommentId === $comment->id)
                                <div class="space-y-2">
                                    <textarea wire:model="editingCommentBody" rows="3"
                                              class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron"></textarea>
                                    @error('editingCommentBody') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                                    <div class="flex gap-3">
                                        <button wire:click="updateComment"
                                                class="rounded-lg bg-brand px-3 py-1 text-xs font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                                            Spara
                                        </button>
                                        <button wire:click="cancelEditComment"
                                                class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400">
                                            Avbryt
                                        </button>
                                    </div>
                                </div>
                            @else
                                <p class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $comment->body }}</p>
                                @if ($comment->author_id === auth()->id() || auth()->user()->can('tasks.manage'))
                                    <div class="mt-1 flex gap-3">
                                        <button wire:click="editComment({{ $comment->id }})"
                                                class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                                            Redigera
                                        </button>
                                        <button wire:click="deleteComment({{ $comment->id }})"
                                                wire:confirm="Ta bort kommentaren?"
                                                class="text-xs text-zinc-400 hover:text-red-500">
                                            Ta bort
                                        </button>
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-400 dark:text-zinc-500">Inga kommentarer än.</p>
                @endforelse

                {{-- Ny kommentar --}}
                @auth
                @if (auth()->user()->hasAnyPermission(['tasks.view', 'tasks.manage']))
                <div class="flex gap-3 pt-2">
                    <div class="size-8 shrink-0 overflow-hidden rounded-full ring-1 ring-zinc-200 dark:ring-zinc-700">
                        @if (auth()->user()->avatar_path)
                            <img src="{{ auth()->user()->avatarUrl(64) }}" alt="{{ auth()->user()->name }}" class="size-full object-cover">
                        @else
                            <span class="flex size-full items-center justify-center bg-complement/20 text-complement dark:bg-complement/30 dark:text-complement-light text-xs font-bold">
                                {{ collect(explode(' ', auth()->user()->name ?? '?'))->map(fn ($n) => mb_strtoupper(mb_substr($n, 0, 1)))->take(2)->implode('') }}
                            </span>
                        @endif
                    </div>
                    <div class="flex-1 space-y-2">
                        <textarea wire:model="newComment"
                                  rows="2"
                                  placeholder="Skriv en kommentar…"
                                  class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron"></textarea>
                        @error('newComment') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                        <button wire:click="addComment"
                                class="rounded-lg bg-brand px-4 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                            Kommentera
                        </button>
                    </div>
                </div>
                @endif
                @endauth
            </div>
        </div>

        {{-- Sidebar (1/3) --}}
        <div class="space-y-5">

            {{-- Status --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4 space-y-2">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Status</p>
                @can('tasks.manage')
                <div class="flex flex-wrap gap-1.5">
                    @foreach (\App\Models\Meeting\MeetingTask::STATUSES as $val => $label)
                        <button wire:click="setStatus('{{ $val }}')"
                                class="rounded-full px-3 py-1 text-xs font-medium transition-colors
                                       {{ $task->status === $val
                                           ? $statusColors[$val]
                                           : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 hover:bg-zinc-200 dark:hover:bg-zinc-700' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                @else
                <span class="rounded-full px-3 py-1 text-xs font-medium {{ $statusColors[$task->status] ?? '' }}">
                    {{ $task->statusLabel() }}
                </span>
                @endcan

                @if ($task->completed_at)
                    <p class="text-xs text-zinc-400">Klar {{ $task->completed_at->isoFormat('D MMM YYYY') }}</p>
                @endif
            </div>

            {{-- Metadata --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4 space-y-3">
                <div>
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Förfallodatum</p>
                    @can('tasks.manage')
                    <input type="date"
                           value="{{ $task->due_date?->format('Y-m-d') }}"
                           wire:blur="saveField('due_date', $event.target.value)"
                           class="w-full rounded border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-sm text-zinc-900 dark:text-white px-2 py-1 focus:border-brand focus:ring-1 focus:ring-brand-saffron
                                  {{ $task->isOverdue() ? 'text-red-600 dark:text-red-400' : '' }}" />
                    @else
                    <p class="text-sm {{ $task->isOverdue() ? 'text-red-600 dark:text-red-400 font-medium' : 'text-zinc-700 dark:text-zinc-300' }}">
                        {{ $task->due_date?->isoFormat('D MMM YYYY') ?? '–' }}
                    </p>
                    @endcan
                </div>

                <div>
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Grupp</p>
                    @can('tasks.manage')
                    <select wire:change="saveField('section_id', $event.target.value)"
                            class="w-full rounded border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-sm text-zinc-900 dark:text-white px-2 py-1 focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                        <option value="">Styrelse / föreningen</option>
                        @foreach ($sections as $section)
                            <option value="{{ $section->id }}" @selected($task->section_id === $section->id)>{{ $section->name }}</option>
                        @endforeach
                    </select>
                    @else
                    <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $task->section?->name ?? 'Styrelse' }}</p>
                    @endcan
                </div>

                @if ($task->sourceable)
                <div>
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Källa</p>
                    @php
                        $src = $task->sourceable;
                        $srcType = class_basename($task->sourceable_type);
                        $srcUrl = match($task->sourceable_type) {
                            \App\Models\Meeting\MeetingAgendaItem::class => route('admin.meetings.show', $src->meeting_id),
                            \App\Models\Meeting\Meeting::class           => route('admin.meetings.show', $src),
                            default                                      => null,
                        };
                    @endphp
                    @if ($srcUrl)
                        <a href="{{ $srcUrl }}" wire:navigate
                           class="text-sm text-complement dark:text-complement-light hover:underline">
                            {{ $src->title ?? $srcType }}
                        </a>
                    @else
                        <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $src->title ?? $srcType }}</p>
                    @endif
                </div>
                @endif

                <div>
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-0.5">Skapad av</p>
                    <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $task->createdBy?->name ?? '–' }}</p>
                </div>
            </div>

            {{-- Tilldelade --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4 space-y-3">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Tilldelade</p>

                @can('tasks.manage')
                <div class="relative">
                    <input wire:model.live.debounce.300ms="searchAssignee" type="text"
                           placeholder="Lägg till person…"
                           class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm px-3 py-1.5 focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
                    @if (count($searchResults) > 0)
                        <div class="absolute z-10 mt-1 w-full rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-lg divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($searchResults as $member)
                                <button wire:click="addAssignee({{ $member->id }})"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-sm text-left hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ $member->full_name }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
                @endcan

                <div class="space-y-1">
                    @forelse ($task->assignees as $member)
                        <div class="flex items-center gap-2">
                            <div class="flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-xs font-semibold">
                                {{ mb_strtoupper(mb_substr($member->first_name, 0, 1)) }}
                            </div>
                            <span class="flex-1 text-sm text-zinc-700 dark:text-zinc-300">{{ $member->full_name }}</span>
                            @can('tasks.manage')
                            <button wire:click="removeAssignee({{ $member->id }})"
                                    class="text-zinc-300 dark:text-zinc-600 hover:text-red-400">
                                <x-bootstrap-icon name="x-lg" class="size-3.5" />
                            </button>
                            @endcan
                        </div>
                    @empty
                        <p class="text-xs text-zinc-400 dark:text-zinc-500">Ingen tilldelad.</p>
                    @endforelse
                </div>
            </div>

        </div>
    </div>

</div>
