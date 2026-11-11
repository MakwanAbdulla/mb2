<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 space-y-6">

    @php
        $statusColors = [
            'submitted'    => 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300',
            'under_review' => 'bg-amber-100 dark:bg-amber-900 text-amber-700 dark:text-amber-300',
            'on_agenda'    => 'bg-complement/15 text-complement dark:text-complement-light',
            'resolved'     => 'bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300',
            'rejected'     => 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400',
        ];
    @endphp

    {{-- Header --}}
    <div>
        <a href="{{ route('admin.submissions.index') }}" wire:navigate
           class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Ärenden och motioner</a>
        <div class="mt-1 flex items-start gap-3">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white flex-1">{{ $submission->title }}</h1>
            <span class="mt-1 shrink-0 rounded-full bg-zinc-100 dark:bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-600 dark:text-zinc-300">
                {{ $submission->typeLabel() }}
            </span>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-6">

        {{-- Huvud (2/3) --}}
        <div class="col-span-2 space-y-6">

            {{-- Brödtext --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-2">Ärendebeskrivning</p>
                <p class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $submission->body ?? '–' }}</p>
            </div>

            {{-- Per capsulam-omröstning --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden">
                <div class="px-4 py-3">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Per capsulam / omröstning</p>
                </div>
                <livewire:shared.poll-panel
                    :voteable-type="'submission'"
                    :voteable-id="$submission->id"
                    :key="'poll-sub-'.$submission->id" />
            </div>

            {{-- Styrelsens yttrande (motioner) --}}
            @if ($submission->type === 'motion')
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-2">Styrelsens yttrande</p>
                @can('submissions.manage')
                <textarea wire:blur="saveBoardResponse($event.target.value)"
                          rows="4"
                          placeholder="Skriv styrelsens yttrande här…"
                          class="w-full rounded border border-transparent hover:border-zinc-300 dark:hover:border-zinc-600 focus:border-brand focus:ring-1 focus:ring-brand-saffron bg-transparent px-1.5 py-0.5 text-sm text-zinc-700 dark:text-zinc-300 resize-none">{{ $submission->board_response }}</textarea>
                @else
                <p class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $submission->board_response ?? 'Inget yttrande ännu.' }}</p>
                @endcan
            </div>
            @endif

            {{-- Kommentarer (intern diskussion) --}}
            <div class="space-y-4">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Intern diskussion</h2>

                @forelse ($comments as $comment)
                    <div class="flex gap-3">
                        <div class="size-8 shrink-0 overflow-hidden rounded-full ring-1 ring-zinc-200 dark:ring-zinc-700">
                            @if ($comment->author?->avatar_path)
                                <img src="{{ $comment->author->avatarUrl(64) }}" alt="{{ $comment->author->name }}" class="size-full object-cover">
                            @else
                                <span class="flex size-full items-center justify-center bg-complement/20 text-complement dark:text-complement-light text-xs font-bold">
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
                                              class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron"></textarea>
                                    <div class="flex gap-3">
                                        <button wire:click="updateComment" class="rounded-lg bg-brand px-3 py-1 text-xs font-semibold text-brand-dark hover:bg-brand-saffron">Spara</button>
                                        <button wire:click="cancelEditComment" class="text-xs text-zinc-500">Avbryt</button>
                                    </div>
                                </div>
                            @else
                                <p class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $comment->body }}</p>
                                @if ($comment->author_id === auth()->id() || auth()->user()->can('submissions.manage'))
                                    <div class="mt-1 flex gap-3">
                                        <button wire:click="editComment({{ $comment->id }})" class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">Redigera</button>
                                        <button wire:click="deleteComment({{ $comment->id }})" wire:confirm="Ta bort kommentaren?" class="text-xs text-zinc-400 hover:text-red-500">Ta bort</button>
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-400 dark:text-zinc-500">Ingen diskussion ännu.</p>
                @endforelse

                <div class="flex gap-3 pt-2">
                    <div class="size-8 shrink-0 overflow-hidden rounded-full ring-1 ring-zinc-200 dark:ring-zinc-700">
                        @if (auth()->user()->avatar_path)
                            <img src="{{ auth()->user()->avatarUrl(64) }}" alt="{{ auth()->user()->name }}" class="size-full object-cover">
                        @else
                            <span class="flex size-full items-center justify-center bg-complement/20 text-complement dark:text-complement-light text-xs font-bold">
                                {{ collect(explode(' ', auth()->user()->name ?? '?'))->map(fn ($n) => mb_strtoupper(mb_substr($n, 0, 1)))->take(2)->implode('') }}
                            </span>
                        @endif
                    </div>
                    <div class="flex-1 space-y-2">
                        <textarea wire:model="newComment" rows="2" placeholder="Skriv en kommentar…"
                                  class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron"></textarea>
                        @error('newComment') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                        <button wire:click="addComment" class="rounded-lg bg-brand px-4 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">Kommentera</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Sidebar (1/3) --}}
        <div class="space-y-5">

            {{-- Status --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4 space-y-2">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Status</p>
                @can('submissions.manage')
                <div class="flex flex-wrap gap-1.5">
                    @foreach (\App\Models\Submission::STATUSES as $val => $label)
                        <button wire:click="setStatus('{{ $val }}')"
                                class="rounded-full px-2.5 py-1 text-xs font-medium transition-colors
                                       {{ $submission->status === $val
                                           ? $statusColors[$val]
                                           : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 hover:bg-zinc-200 dark:hover:bg-zinc-700' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                @else
                <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $statusColors[$submission->status] ?? '' }}">
                    {{ $submission->statusLabel() }}
                </span>
                @endcan
            </div>

            {{-- Metadata --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4 space-y-3 text-sm">
                <div>
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-0.5">Inlämnat av</p>
                    <p class="text-zinc-700 dark:text-zinc-300">{{ $submission->createdBy?->name ?? '–' }}</p>
                </div>
                @if ($submission->source_gmail_from)
                <div>
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-0.5">Från e-post</p>
                    <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400 break-all">{{ $submission->source_gmail_from }}</p>
                </div>
                @endif
                <div>
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-0.5">Riktat till</p>
                    <p class="text-zinc-700 dark:text-zinc-300">{{ $submission->section?->name ?? 'Styrelsen' }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-0.5">Inlämnat</p>
                    <p class="text-zinc-700 dark:text-zinc-300">{{ $submission->created_at->isoFormat('D MMM YYYY') }}</p>
                </div>
                @if ($submission->agendaItem)
                <div>
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-0.5">Dagordning</p>
                    <a href="{{ route('admin.meetings.show', $submission->agendaItem->meeting_id) }}" wire:navigate
                       class="text-complement dark:text-complement-light hover:underline text-xs">
                        {{ $submission->agendaItem->meeting->title ?? '–' }}
                    </a>
                </div>
                @endif
            </div>

            {{-- Svara avsändare --}}
            @if ($submission->source_gmail_from && $submission->source_mailbox_id)
            @can('submissions.manage')
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4 space-y-3">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Svara avsändare</p>
                @if ($replyOpen)
                    <textarea wire:model="replyBody" rows="4"
                              placeholder="Skriv ditt svar…"
                              class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron resize-none"></textarea>
                    @error('replyBody') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                    <div class="flex gap-2">
                        <button wire:click="sendReply" wire:loading.attr="disabled" class="btn btn-primary btn-sm">
                            <span wire:loading.remove wire:target="sendReply" class="flex items-center gap-1.5">
                                <x-bootstrap-icon name="send" class="size-3.5" />
                                Skicka
                            </span>
                            <span wire:loading wire:target="sendReply">Skickar…</span>
                        </button>
                        <button wire:click="closeReply" class="btn btn-ghost btn-sm">Avbryt</button>
                    </div>
                @else
                    <p class="text-xs text-zinc-400 dark:text-zinc-500 break-all">Till: {{ $submission->source_gmail_from }}</p>
                    <button wire:click="openReply" class="btn btn-ghost btn-sm w-full">
                        <x-bootstrap-icon name="reply" class="size-4" />
                        Skriv svar
                    </button>
                @endif
            </div>
            @endcan
            @endif

            {{-- Lägg till på dagordning --}}
            @can('submissions.manage')
            @if ($submission->isOpen())
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4 space-y-3">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Lägg till på dagordning</p>
                @if ($upcomingMeetings->isEmpty())
                    <p class="text-xs text-zinc-400">
                        Inga kommande
                        {{ $submission->type === 'motion' ? 'members-/årsmöten' : 'möten' }}
                        i utkast eller kallelsestatus.
                    </p>
                @else
                    <select wire:model="addToMeetingId"
                            class="w-full rounded border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-sm text-zinc-900 dark:text-white px-2 py-1.5 focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                        <option value="">Välj möte…</option>
                        @foreach ($upcomingMeetings as $meeting)
                            <option value="{{ $meeting->id }}">
                                {{ $meeting->typeLabel() }} – {{ $meeting->scheduled_at->isoFormat('D MMM YYYY') }}
                            </option>
                        @endforeach
                    </select>
                    @error('addToMeetingId') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                    <button wire:click="addToAgenda"
                            class="w-full rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                        Lägg till på dagordning
                    </button>
                @endif
            </div>
            @endif
            @endcan

        </div>
    </div>

</div>
