<?php

use App\Models\PollBallot;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {

    #[Computed]
    public function pendingBallots(): \Illuminate\Database\Eloquent\Collection
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

    private function linkFor(\App\Models\PollBallot $ballot): string
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

@php $ballots = $this->pendingBallots; $count = $ballots->count(); @endphp

<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <button @click="{{ $count > 0 ? 'open = !open' : 'window.location = \'' . route('dashboard') . '#aviseringar\'' }}"
            title="{{ $count > 0 ? 'Du har ' . $count . ' omröstning' . ($count === 1 ? '' : 'ar') . ' att svara på' : 'Aviseringsinställningar' }}"
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

    @if($count > 0)
        <div x-show="open" x-cloak x-transition
             class="absolute right-0 top-full z-50 mt-1 w-72 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">

            <div class="border-b border-zinc-100 px-4 py-2.5 dark:border-zinc-700">
                <p class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">
                    <x-bootstrap-icon name="bar-chart-line" class="inline size-3.5 mr-1 text-complement dark:text-complement-light" />
                    Väntar på din röst
                </p>
            </div>

            <div class="divide-y divide-zinc-100 dark:divide-zinc-700 max-h-80 overflow-y-auto">
                @foreach($ballots as $ballot)
                    <a href="{{ $this->linkFor($ballot) }}" wire:navigate @click="open = false"
                       class="flex items-start gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-700/50 transition-colors">
                        <x-bootstrap-icon name="circle-fill" class="mt-1 size-2 shrink-0 text-complement dark:text-complement-light" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                {{ $ballot->poll->question }}
                            </p>
                            @if($ballot->poll->deadline)
                                <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                                    Deadline: {{ $ballot->poll->deadline->isoFormat('D MMM, HH:mm') }}
                                </p>
                            @endif
                        </div>
                        <x-bootstrap-icon name="chevron-right" class="mt-0.5 size-3.5 shrink-0 text-zinc-400" />
                    </a>
                @endforeach
            </div>

        </div>
    @endif
</div>
