<?php

use App\Models\Tournament\Tournament;
use App\Models\Tournament\TournamentParticipant;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Turnering');

new class extends Component {

    public Tournament $tournament;
    public array      $regData  = [];
    public ?string    $flash    = null;
    public ?string    $error    = null;

    public function mount(Tournament $tournament): void
    {
        if (! $tournament->is_public && ! (Auth::check() && $tournament->canBeManagedBy(Auth::user()))) {
            abort(404);
        }
        $this->tournament = $tournament;
    }

    #[\Livewire\Attributes\Computed]
    public function phases()
    {
        return $this->tournament->phases()
            ->with(['rounds.matches.participants.participant.member', 'standings.participant.member'])
            ->get();
    }

    #[\Livewire\Attributes\Computed]
    public function myParticipant(): ?TournamentParticipant
    {
        $member = Auth::user()?->member;
        if (! $member) {
            return null;
        }
        return $this->tournament->participants()->where('member_id', $member->id)->first();
    }

    public function register(): void
    {
        $member = Auth::user()?->member;
        if (! $member) {
            return;
        }
        $this->error = null;

        if (! $this->tournament->isRegistrationOpen()) {
            $this->error = 'Anmälan är inte öppen.';
            return;
        }

        if ($this->myParticipant) {
            $this->error = 'Du är redan anmäld.';
            return;
        }

        // Validate required registration fields
        foreach ($this->tournament->registration_fields ?? [] as $field) {
            if (($field['required'] ?? false) && empty($this->regData[$field['key']])) {
                $this->error = "Fältet \"{$field['label']}\" är obligatoriskt.";
                return;
            }
        }

        $isFull  = $this->tournament->isFull();
        $status  = $isFull
            ? TournamentParticipant::STATUS_WAITLISTED
            : TournamentParticipant::STATUS_REGISTERED;

        TournamentParticipant::create([
            'tournament_id'     => $this->tournament->id,
            'member_id'         => $member->id,
            'status'            => $status,
            'registration_data' => empty($this->regData) ? null : $this->regData,
        ]);

        $this->regData = [];
        $this->flash   = $isFull
            ? 'Du är placerad på väntelistan.'
            : 'Du är anmäld till turneringen!';
    }

    public function withdraw(): void
    {
        $p = $this->myParticipant;
        if (! $p || ! $this->tournament->isRegistrationOpen()) {
            return;
        }

        $wasRegistered = $p->status === TournamentParticipant::STATUS_REGISTERED;
        $p->update(['status' => TournamentParticipant::STATUS_WITHDRAWN]);

        // Promote first waitlisted if a registered spot freed up
        if ($wasRegistered) {
            /** @var TournamentParticipant|null $next */
            $next = $this->tournament->participants()
                ->where('status', TournamentParticipant::STATUS_WAITLISTED)
                ->oldest()
                ->first();
            $next?->update(['status' => TournamentParticipant::STATUS_REGISTERED]);
        }

        $this->flash = 'Du har avanmält dig.';
    }
}; ?>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 space-y-8">

    {{-- Header --}}
    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $tournament->title }}</h1>
        @if($tournament->description)
            <p class="mt-2 text-zinc-600 dark:text-zinc-400">{{ $tournament->description }}</p>
        @endif
        <div class="mt-3 flex items-center gap-3 flex-wrap text-sm text-zinc-500 dark:text-zinc-400">
            <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300">
                {{ $tournament->statusLabel() }}
            </span>
            <span>{{ $tournament->activeParticipants()->count() }} deltagare</span>
            @if($tournament->event) <span>· {{ $tournament->event->title }}</span> @endif
        </div>
    </div>

    {{-- Flash --}}
    @if($flash)
        <div x-data x-init="setTimeout(() => $el.remove(), 4000)"
             class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400">
            {{ $flash }}
        </div>
    @endif

    {{-- Registration panel --}}
    @auth
    @if($this->tournament->isRegistrationOpen())
        @if(! $this->myParticipant)
            {{-- Register --}}
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                <div class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800 px-5 py-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                        Anmäl dig
                        @if($this->tournament->isFull())
                            <span class="ml-1 text-amber-600 dark:text-amber-400 font-normal text-xs">(turneringen är full — du hamnar på väntelistan)</span>
                        @else
                            <span class="ml-1 text-zinc-400 font-normal text-xs">
                                {{ $this->tournament->activeParticipants()->count() }}
                                @if($this->tournament->max_participants) / {{ $this->tournament->max_participants }} @endif
                                deltagare
                            </span>
                        @endif
                    </h2>
                </div>
                <div class="p-5 space-y-4">
                    @foreach($tournament->registration_fields ?? [] as $field)
                        <div>
                            <label class="lbl">{{ $field['label'] }}{{ ($field['required'] ?? false) ? ' *' : '' }}</label>
                            @if(($field['type'] ?? 'text') === 'select')
                                <select wire:model="regData.{{ $field['key'] }}" class="inp mt-1 w-full">
                                    <option value="">Välj…</option>
                                    @foreach(explode(',', $field['options'] ?? '') as $opt)
                                        <option value="{{ trim($opt) }}">{{ trim($opt) }}</option>
                                    @endforeach
                                </select>
                            @elseif(($field['type'] ?? 'text') === 'textarea')
                                <textarea wire:model="regData.{{ $field['key'] }}" rows="2" class="inp mt-1 w-full resize-none"></textarea>
                            @else
                                <input wire:model="regData.{{ $field['key'] }}" type="text" class="inp mt-1 w-full" />
                            @endif
                        </div>
                    @endforeach
                    @if($error)
                        <p class="text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
                    @endif
                    <button wire:click="register" class="btn btn-primary">
                        {{ $this->tournament->isFull() ? 'Ställ upp på väntelistan' : 'Anmäl mig' }}
                    </button>
                </div>
            </div>

        @elseif($this->myParticipant->status === TournamentParticipant::STATUS_WAITLISTED)
            {{-- Waitlisted --}}
            <div class="rounded-xl border border-amber-200 dark:border-amber-800/50 bg-amber-50 dark:bg-amber-900/10 px-5 py-4 flex items-center justify-between gap-4">
                <div>
                    <p class="font-medium text-amber-800 dark:text-amber-300">Du är på väntelistan</p>
                    <p class="text-sm text-amber-600 dark:text-amber-400 mt-0.5">
                        Plats {{ $this->tournament->participants()->where('status', TournamentParticipant::STATUS_WAITLISTED)->where('created_at', '<=', $this->myParticipant->created_at)->count() }}
                        av {{ $this->tournament->waitlistCount() }}
                    </p>
                </div>
                <button wire:click="withdraw" wire:confirm="Avanmäl dig från väntelistan?"
                        class="text-sm text-amber-700 dark:text-amber-400 underline underline-offset-2 shrink-0">
                    Avregistrera
                </button>
            </div>

        @elseif($this->myParticipant->status === TournamentParticipant::STATUS_REGISTERED)
            {{-- Registered --}}
            <div class="rounded-xl border border-brand/40 bg-brand/5 dark:border-brand/30 dark:bg-brand/10 px-5 py-4 flex items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <x-bootstrap-icon name="check-circle-fill" class="size-5 text-green-500" />
                    <div>
                        <p class="font-medium text-zinc-900 dark:text-white">Du är anmäld</p>
                        @if($this->myParticipant->seed)
                            <p class="text-xs text-zinc-400">Seed {{ $this->myParticipant->seed }}</p>
                        @endif
                    </div>
                </div>
                <button wire:click="withdraw" wire:confirm="Avanmäl dig från turneringen?"
                        class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 underline underline-offset-2 shrink-0">
                    Avanmäl mig
                </button>
            </div>

        @elseif($this->myParticipant->status === TournamentParticipant::STATUS_CHECKED_IN)
            <div class="rounded-xl border border-green-200 dark:border-green-800/50 bg-green-50 dark:bg-green-900/10 px-5 py-3 flex items-center gap-2 text-green-700 dark:text-green-400">
                <x-bootstrap-icon name="check2-circle" class="size-5" />
                <span class="font-medium">Incheckad ✓</span>
            </div>
        @endif
    @endif
    @endauth

    {{-- My registration info --}}
    @if($this->myParticipant && ! empty($this->myParticipant->registration_data))
        <div class="rounded-xl border border-brand/40 bg-brand/5 dark:border-brand/30 dark:bg-brand/10 px-5 py-4">
            <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200 mb-2">Din anmälningsinformation</p>
            <div class="flex flex-wrap gap-3">
                @foreach(($tournament->registration_fields ?? []) as $field)
                    <div class="text-xs">
                        <span class="text-zinc-400">{{ $field['label'] }}:</span>
                        <span class="ml-1 font-medium text-zinc-700 dark:text-zinc-300">{{ $this->myParticipant->registration_data[$field['key']] ?? '—' }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Phases --}}
    @foreach($this->phases as $phase)
        @if($phase->status === 'pending') @continue @endif

        <div class="space-y-4">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                {{ $phase->name }}
                <span class="text-sm font-normal text-zinc-400 ml-1">{{ $phase->formatLabel() }}</span>
            </h2>

            {{-- Standings --}}
            @if($phase->standings->isNotEmpty())
                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800 px-5 py-2.5">
                        <h3 class="text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500">Standings</h3>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="border-b border-zinc-100 dark:border-zinc-800">
                            <tr>
                                <th class="px-5 py-2.5 text-left font-medium text-zinc-400 w-10">#</th>
                                <th class="px-5 py-2.5 text-left font-medium text-zinc-400">Deltagare</th>
                                <th class="px-5 py-2.5 text-center font-medium text-zinc-400">MP</th>
                                @if($phase->uses_game_points)
                                    <th class="px-5 py-2.5 text-center font-medium text-zinc-400">SP</th>
                                @endif
                                <th class="px-5 py-2.5 text-center font-medium text-zinc-400 hidden sm:table-cell">V/O/F</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                            @foreach($phase->standings->sortBy('rank') as $s)
                                @php $isMe = $this->myParticipant && $s->participant_id === $this->myParticipant->id; @endphp
                                <tr @class(['bg-brand/5 dark:bg-brand/10' => $isMe])>
                                    <td class="px-5 py-2.5 text-zinc-400 tabular-nums">
                                        @if($s->rank === 1) 🥇
                                        @elseif($s->rank === 2) 🥈
                                        @elseif($s->rank === 3) 🥉
                                        @else {{ $s->rank }}
                                        @endif
                                    </td>
                                    <td class="px-5 py-2.5 font-medium text-zinc-900 dark:text-white">
                                        {{ $s->participant?->displayName() }}
                                        @if($isMe) <span class="text-xs text-zinc-400 ml-1">(du)</span> @endif
                                    </td>
                                    <td class="px-5 py-2.5 text-center tabular-nums font-bold text-zinc-800 dark:text-zinc-200">{{ tournament_num($s->match_points) }}</td>
                                    @if($phase->uses_game_points)
                                        <td class="px-5 py-2.5 text-center tabular-nums text-zinc-500 dark:text-zinc-400">{{ tournament_num($s->game_points) }}</td>
                                    @endif
                                    <td class="px-5 py-2.5 text-center text-xs text-zinc-400 hidden sm:table-cell">{{ $s->wins }}/{{ $s->draws }}/{{ $s->losses }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Current round matches --}}
            @php $currentRound = $phase->rounds->where('status', 'ongoing')->first() ?? $phase->rounds->last(); @endphp
            @if($currentRound && $phase->isSwiss())
                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800 px-5 py-2.5">
                        <h3 class="text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500">
                            Runda {{ $currentRound->round_number }}
                            @if($phase->rounds_count) <span class="font-normal">/ {{ $phase->rounds_count }}</span> @endif
                        </h3>
                    </div>
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach($currentRound->matches->where('status', '!=', 'bye') as $match)
                            <div class="flex items-center gap-2 px-5 py-3 flex-wrap">
                                @foreach($match->participants as $mp)
                                    <span @class([
                                        'text-sm',
                                        'font-bold text-zinc-900 dark:text-white' => $mp->is_winner && $match->status === 'completed',
                                        'text-zinc-700 dark:text-zinc-300' => ! ($mp->is_winner && $match->status === 'completed'),
                                    ])>{{ $mp->participant?->displayName() }}</span>
                                    @if(! $loop->last)
                                        <span class="text-zinc-300 dark:text-zinc-600 text-xs">vs</span>
                                    @endif
                                @endforeach
                                @if($match->status === 'completed')
                                    <span class="ml-auto text-xs text-zinc-400">klar</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Elimination bracket (simplified text view) --}}
            @if($phase->isElimination())
                @foreach($phase->rounds as $round)
                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                        <div class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800 px-5 py-2 text-xs font-semibold uppercase tracking-widest text-zinc-400">
                            @php
                                $totalRounds = $phase->rounds->count();
                                $roundName = match($totalRounds - $round->round_number) {
                                    0 => 'Final',
                                    1 => 'Semifinal',
                                    2 => 'Kvartsfinal',
                                    default => "Omgång {$round->round_number}",
                                };
                            @endphp
                            {{ $roundName }}
                        </div>
                        <div class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                            @foreach($round->matches->where('status', '!=', 'bye') as $match)
                                <div class="flex items-center gap-3 px-5 py-3">
                                    <div class="flex-1 flex items-center gap-2 flex-wrap">
                                        @foreach($match->participants as $mp)
                                            <span @class([
                                                'text-sm',
                                                'font-bold text-zinc-900 dark:text-white' => $mp->is_winner,
                                                'text-zinc-500 dark:text-zinc-400 line-through' => ! $mp->is_winner && $match->status === 'completed',
                                                'text-zinc-700 dark:text-zinc-300' => $match->status !== 'completed',
                                            ])>{{ $mp->participant?->displayName() }}</span>
                                            @if(! $loop->last) <span class="text-zinc-300 dark:text-zinc-600 text-xs">vs</span> @endif
                                        @endforeach
                                        @if($match->participants->isEmpty())
                                            <span class="text-xs text-zinc-300 dark:text-zinc-600 italic">Väntar på resultat…</span>
                                        @endif
                                    </div>
                                    @if($match->status === 'completed')
                                        @foreach($match->participants->where('is_winner', true) as $w)
                                            <span class="text-xs text-green-600 dark:text-green-400 font-medium shrink-0">✓ {{ $w->participant?->displayName() }}</span>
                                        @endforeach
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    @endforeach

</div>
