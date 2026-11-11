<?php

use App\Models\PollBallot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {

    #[Computed]
    public function notifications(): Collection
    {
        if (! Auth::check()) {
            return collect();
        }
        return Auth::user()->unreadNotifications()->latest()->limit(15)->get();
    }

    #[Computed]
    public function pendingBallots(): Collection
    {
        if (! Auth::check()) {
            return collect();
        }
        return PollBallot::where('voter_id', Auth::id())
            ->whereNull('option_id')
            ->whereHas('poll', fn ($q) => $q->where('status', 'open'))
            ->with(['poll.voteable'])
            ->get();
    }

    #[Computed]
    public function totalCount(): int
    {
        return $this->notifications->count() + $this->pendingBallots->count();
    }

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications()->update(['read_at' => now()]);
        unset($this->notifications);
    }

    public function markRead(string $id): void
    {
        Auth::user()->notifications()->where('id', $id)->update(['read_at' => now()]);
        unset($this->notifications);
    }

    private function ballotLink(\App\Models\PollBallot $ballot): string
    {
        $voteable = $ballot->poll->voteable;
        if ($voteable instanceof \App\Models\Submission) {
            return route('submissions.show', $voteable);
        }
        if ($voteable instanceof \App\Models\Meeting\MeetingAgendaItem) {
            return route('meetings.show', $voteable->meeting_id);
        }
        return route('dashboard');
    }
}; ?>

@php
    $count = $this->totalCount;
    $notifications = $this->notifications;
    $ballots = $this->pendingBallots;
@endphp

<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <button @click="open = !open"
            title="{{ $count > 0 ? $count . ' olästa aviseringar' : 'Inga aviseringar' }}"
            class="relative flex size-9 items-center justify-center rounded-lg transition-colors
                   {{ $count > 0
                       ? 'text-complement dark:text-complement-light hover:bg-complement/10 dark:hover:bg-complement/20'
                       : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white' }}">
        <x-bootstrap-icon name="bell{{ $count > 0 ? '-fill' : '' }}" class="size-4" />
        @if($count > 0)
            <span class="absolute -right-0.5 -top-0.5 flex size-4 items-center justify-center rounded-full bg-red-500 text-[9px] font-bold text-white leading-none">
                {{ $count > 9 ? '9+' : $count }}
            </span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition
         class="absolute right-0 top-full z-50 mt-1 w-80 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">

        {{-- Header --}}
        <div class="flex items-center justify-between border-b border-zinc-100 px-4 py-2.5 dark:border-zinc-700">
            <p class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">Aviseringar</p>
            @if($notifications->isNotEmpty())
                <button wire:click="markAllRead" class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                    Markera alla lästa
                </button>
            @endif
        </div>

        <div class="max-h-96 overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-700">

            {{-- Väntande omröstningar --}}
            @foreach($ballots as $ballot)
                <a href="{{ $this->ballotLink($ballot) }}" wire:navigate @click="open = false"
                   class="flex items-start gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-700/50 transition-colors">
                    <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-complement/10 dark:bg-complement/20">
                        <x-bootstrap-icon name="bar-chart-line" class="size-3.5 text-complement dark:text-complement-light" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200 line-clamp-2">
                            {{ $ballot->poll->question }}
                        </p>
                        @if($ballot->poll->deadline)
                            <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                                Deadline: {{ $ballot->poll->deadline->isoFormat('D MMM, HH:mm') }}
                            </p>
                        @endif
                    </div>
                    <x-bootstrap-icon name="chevron-right" class="mt-1 size-3.5 shrink-0 text-zinc-400" />
                </a>
            @endforeach

            {{-- Övriga notiser --}}
            @foreach($notifications as $notification)
                @php $data = $notification->data; @endphp
                <div class="flex items-start gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-700/50 transition-colors cursor-pointer"
                     wire:click="markRead('{{ $notification->id }}')"
                     @if(!empty($data['url'])) x-on:click.once="setTimeout(() => { window.location = '{{ $data['url'] }}' }, 150)" @endif>
                    <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-700">
                        <x-bootstrap-icon name="{{ $data['icon'] ?? 'bell' }}" class="size-3.5 text-zinc-500 dark:text-zinc-400" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-zinc-800 dark:text-zinc-200 line-clamp-2">
                            {{ $data['message'] ?? '' }}
                        </p>
                        <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                            {{ $notification->created_at->diffForHumans() }}
                        </p>
                    </div>
                    <x-bootstrap-icon name="chevron-right" class="mt-1 size-3.5 shrink-0 text-zinc-400" />
                </div>
            @endforeach

            {{-- Tom state --}}
            @if($count === 0)
                <div class="px-4 py-8 text-center">
                    <x-bootstrap-icon name="bell-slash" class="mx-auto size-6 text-zinc-300 dark:text-zinc-600 mb-2" />
                    <p class="text-xs text-zinc-400 dark:text-zinc-500">Inga olästa aviseringar</p>
                </div>
            @endif

        </div>

        {{-- Footer --}}
        <div class="border-t border-zinc-100 px-4 py-2 dark:border-zinc-700">
            <a href="{{ route('dashboard') }}#aviseringar" wire:navigate @click="open = false"
               class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                Aviseringsinställningar
            </a>
        </div>
    </div>
</div>
