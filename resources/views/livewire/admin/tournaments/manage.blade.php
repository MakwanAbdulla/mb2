<?php

use App\Models\Membership\Member;
use App\Models\Tournament\Tournament;
use App\Models\Tournament\TournamentMatch;
use App\Models\Tournament\TournamentParticipant;
use App\Models\Tournament\TournamentPhase;
use App\Services\TournamentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Hantera turnering');

new class extends Component {

    public Tournament $tournament;

    // Participant add
    public string  $searchMember   = '';
    public string  $addSeed        = '';
    public array   $regData        = [];
    public string  $guestName      = '';
    public string  $guestEmail     = '';
    public bool    $addingGuest    = false;

    // Result entry
    public ?int    $editMatchId    = null;
    public array   $scores         = []; // [participant_id => game_score] or [participant_id => ['placement' => x, 'game_score' => y]]

    public ?string $flash          = null;
    public ?string $flashError     = null;

    // Slot time editing
    public ?int   $editSlotRoundId = null;
    public array  $slotTimes       = [];

    // TV message
    public string $tvMessage = '';
    public bool   $editingMessage = false;

    public function mount(Tournament $tournament): void
    {
        abort_unless(Auth::check() && $tournament->canBeManagedBy(Auth::user()), 403);
        $this->tournament = $tournament;
    }

    #[\Livewire\Attributes\Computed]
    public function phases()
    {
        return $this->tournament->phases()->with(['rounds.matches.participants.participant.member'])->get();
    }

    #[\Livewire\Attributes\Computed]
    public function participants()
    {
        return $this->tournament->activeParticipants()
            ->with('member')
            ->orderBy('seed')
            ->orderBy('created_at')
            ->get();
    }

    #[\Livewire\Attributes\Computed]
    public function memberSuggestions()
    {
        if (strlen($this->searchMember) < 2) {
            return collect();
        }
        $existing = $this->tournament->participants()->pluck('member_id');
        return Member::where('status', Member::STATUS_ACTIVE)
            ->whereNotIn('id', $existing)
            ->where(fn ($q) => $q->where('first_name', 'like', "%{$this->searchMember}%")
                ->orWhere('last_name', 'like', "%{$this->searchMember}%"))
            ->limit(8)->get(['id', 'first_name', 'last_name']);
    }

    public function addGuest(): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);

        $this->validate(['guestName' => 'required|string|min:2|max:120']);

        TournamentParticipant::create([
            'tournament_id' => $this->tournament->id,
            'guest_name'    => trim($this->guestName),
            'guest_email'   => trim($this->guestEmail) ?: null,
            'seed'          => $this->addSeed !== '' ? (int) $this->addSeed : null,
            'status'        => TournamentParticipant::STATUS_REGISTERED,
        ]);

        $this->guestName  = '';
        $this->guestEmail = '';
        $this->addSeed    = '';
        $this->flash      = 'Gäst tillagd.';
    }

    public function addParticipant(int $memberId): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);

        // Validate required reg fields
        $fields = $this->tournament->registration_fields ?? [];
        foreach ($fields as $field) {
            if (($field['required'] ?? false) && empty($this->regData[$field['key']])) {
                $this->flashError = "Fältet '{$field['label']}' är obligatoriskt.";
                return;
            }
        }

        TournamentParticipant::create([
            'tournament_id'     => $this->tournament->id,
            'member_id'         => $memberId,
            'seed'              => $this->addSeed !== '' ? (int) $this->addSeed : null,
            'status'            => TournamentParticipant::STATUS_REGISTERED,
            'registration_data' => empty($this->regData) ? null : $this->regData,
        ]);

        $this->searchMember = '';
        $this->addSeed      = '';
        $this->regData      = [];
        $this->flash        = 'Deltagare tillagd.';
    }

    public function removeParticipant(int $id): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);
        TournamentParticipant::findOrFail($id)->update(['status' => TournamentParticipant::STATUS_WITHDRAWN]);
    }

    public function updateSeed(int $id, string $seed): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);
        TournamentParticipant::findOrFail($id)->update(['seed' => $seed !== '' ? (int) $seed : null]);
    }

    public function startPhase(int $phaseId): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);
        $phase = TournamentPhase::findOrFail($phaseId);
        try {
            app(TournamentService::class)->startPhase($phase);
            $this->flash = "Fas '{$phase->name}' startad.";
        } catch (\RuntimeException $e) {
            $this->flashError = $e->getMessage();
        }
    }

    public function nextRound(int $phaseId): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);
        $phase = TournamentPhase::findOrFail($phaseId);
        try {
            $round = app(TournamentService::class)->generateNextRound($phase);
            $this->flash = "Runda {$round->round_number} genererad.";
        } catch (\RuntimeException $e) {
            $this->flashError = $e->getMessage();
        }
    }

    public function completePhase(int $phaseId): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);
        $phase = TournamentPhase::findOrFail($phaseId);
        app(TournamentService::class)->completePhase($phase);
        $this->flash = "Fas '{$phase->name}' avslutad.";
    }

    public function openResult(int $matchId): void
    {
        $this->editMatchId = $matchId;
        $this->scores      = [];
        $match = TournamentMatch::with('participants.participant.member')->find($matchId);
        if ($match) {
            foreach ($match->participants as $mp) {
                $this->scores[$mp->participant_id] = [
                    'placement'    => $mp->placement ?? '',
                    'game_score'   => $mp->game_score ?? '',
                    'bonus_points' => $mp->bonus_points ?? 0,
                ];
            }
        }
    }

    public function saveResult(): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);
        if (! $this->editMatchId) {
            return;
        }

        $match   = TournamentMatch::findOrFail($this->editMatchId);
        $service = app(TournamentService::class);

        $scores = [];
        foreach ($this->scores as $pid => $data) {
            $gameStr  = str_replace(',', '.', trim($data['game_score'] ?? ''));
            $bonusStr = str_replace(',', '.', trim($data['bonus_points'] ?? '0'));
            $scores[$pid] = [
                'placement'    => ($data['placement'] ?? '') !== '' ? (int) $data['placement'] : null,
                'game_score'   => $gameStr !== '' ? (float) $gameStr : null,
                'bonus_points' => $bonusStr !== '' ? (float) $bonusStr : 0,
            ];
        }

        $errors = $service->validateScores($match, $scores);
        if ($errors) {
            $this->flashError = implode(' ', $errors);
            return;
        }

        try {
            // For elimination correction: reset bracket cascade first, then re-record
            if ($match->status === TournamentMatch::STATUS_COMPLETED && $match->phase()->isElimination()) {
                $service->resetMatch($match);
                // Re-fetch match after reset (status changed)
                $match = TournamentMatch::findOrFail($this->editMatchId);
            }
            $service->recordResult($match, $scores);
            $this->editMatchId = null;
            $this->flash = 'Resultat korrigerat.';
        } catch (\RuntimeException $e) {
            $this->flashError = $e->getMessage();
        }
    }

    public function resetMatch(int $matchId): void
    {
        // Only used for elimination bracket cascade cleanup before re-recording a result.
        // For Swiss/pod: openResult() is called directly without resetting scores.
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);
        $match = TournamentMatch::with('round.phase')->findOrFail($matchId);
        if ($match->phase()->isElimination()) {
            app(TournamentService::class)->resetMatch($match);
        }
        $this->openResult($matchId);
    }

    public function openMessageEditor(): void
    {
        $this->tvMessage     = $this->tournament->tv_message ?? '';
        $this->editingMessage = true;
    }

    public function saveTvMessage(): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);
        $this->tournament->update(['tv_message' => trim($this->tvMessage) ?: null]);
        $this->editingMessage = false;
        $this->flash = trim($this->tvMessage) ? 'Meddelande uppdaterat.' : 'Meddelande rensat.';
    }

    public function clearTvMessage(): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);
        $this->tournament->update(['tv_message' => null]);
        $this->tvMessage = '';
        $this->editingMessage = false;
        $this->flash = 'Meddelande rensat.';
    }

    public function openSlotEditor(int $roundId): void
    {
        $round   = \App\Models\Tournament\TournamentRound::findOrFail($roundId);
        $maxSlot = \App\Models\Tournament\TournamentMatch::where('round_id', $roundId)->max('time_slot') ?? 1;
        $this->slotTimes = [];
        for ($s = 1; $s <= $maxSlot; $s++) {
            $existing = $round->slot_times[$s] ?? null;
            // Normalise to {start, end} format regardless of old string-only format
            if (is_array($existing)) {
                $this->slotTimes[$s] = ['start' => $existing['start'] ?? '', 'end' => $existing['end'] ?? ''];
            } else {
                $this->slotTimes[$s] = ['start' => $existing ?? '', 'end' => ''];
            }
        }
        $this->editSlotRoundId = $roundId;
    }

    public function saveSlotTimes(): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);
        if (! $this->editSlotRoundId) return;
        $round = \App\Models\Tournament\TournamentRound::findOrFail($this->editSlotRoundId);
        app(TournamentService::class)->saveSlotTimes($round, $this->slotTimes);
        $this->editSlotRoundId = null;
        $this->flash = 'Slottider sparade.';
    }

    public function extendSlot(int $roundId, int $slotNum, int $minutes): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);
        $round    = \App\Models\Tournament\TournamentRound::findOrFail($roundId);
        $times    = $round->slot_times ?? [];
        $existing = $times[$slotNum] ?? null;

        $endStr = is_array($existing) ? ($existing['end'] ?? '') : '';

        if ($endStr === '') {
            $this->flashError = 'Sätt en sluttid för passet innan du förlänger.';
            return;
        }

        [$h, $m] = explode(':', $endStr);
        $newEnd = \Carbon\Carbon::today()->setHour((int)$h)->setMinute((int)$m)->addMinutes($minutes);

        $times[$slotNum] = array_merge(
            is_array($existing) ? $existing : ['start' => $existing ?? ''],
            ['end' => $newEnd->format('H:i')]
        );

        app(TournamentService::class)->saveSlotTimes($round, $times);
        $this->flash = "Pass {$slotNum} förlängt med {$minutes} min → sluttid {$newEnd->format('H:i')}.";
    }

    public function checkIn(int $participantId): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);
        TournamentParticipant::where('id', $participantId)
            ->where('tournament_id', $this->tournament->id)
            ->update(['status' => TournamentParticipant::STATUS_CHECKED_IN]);
    }

    public function undoCheckIn(int $participantId): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);
        TournamentParticipant::where('id', $participantId)
            ->where('tournament_id', $this->tournament->id)
            ->update(['status' => TournamentParticipant::STATUS_REGISTERED]);
    }

    public function importFromEvent(): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);

        if (! $this->tournament->event_id) {
            $this->flashError = 'Ingen kopplad event.';
            return;
        }

        $existingMemberIds = $this->tournament->participants()->pluck('member_id')->filter()->flip();

        $registrations = \App\Models\Events\EventRegistration::where('event_id', $this->tournament->event_id)
            ->where('status', \App\Models\Events\EventRegistration::STATUS_CONFIRMED)
            ->whereHas('user', fn ($q) => $q->whereHas('member'))
            ->with('user.member')
            ->get();

        $added = 0;
        foreach ($registrations as $reg) {
            $memberId = $reg->user?->member?->id;
            if (! $memberId || isset($existingMemberIds[$memberId])) {
                continue;
            }
            TournamentParticipant::create([
                'tournament_id' => $this->tournament->id,
                'member_id'     => $memberId,
                'status'        => TournamentParticipant::STATUS_REGISTERED,
            ]);
            $added++;
        }

        $this->flash = $added > 0 ? "{$added} deltagare importerade från eventet." : 'Inga nya deltagare att importera.';
    }

    public function reshuffleTables(int $roundId): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);
        $round = \App\Models\Tournament\TournamentRound::findOrFail($roundId);
        app(TournamentService::class)->reshuffleTableNumbers($round);
        $this->flash = 'Bordsnummer omlottade.';
    }

    public function advanceToNextPhase(int $fromPhaseId, int $toPhaseId): void
    {
        abort_unless($this->tournament->canBeManagedBy(Auth::user()), 403);
        $from = TournamentPhase::findOrFail($fromPhaseId);
        $to   = TournamentPhase::findOrFail($toPhaseId);
        app(TournamentService::class)->advanceParticipants($from, $to);
        $this->flash = "Deltagare avancerade till '{$to->name}'.";
    }
}; ?>

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 space-y-8">

    {{-- Header --}}
    <div class="flex items-start justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-xl font-bold text-zinc-900 dark:text-white">{{ $tournament->title }}</h1>
            <div class="flex items-center gap-2 mt-1">
                <span class="text-xs px-2 py-0.5 rounded-full bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $tournament->statusLabel() }}</span>
                @if($tournament->event) <span class="text-xs text-zinc-400">{{ $tournament->event->title }}</span> @endif
                @if($tournament->section) <span class="text-xs text-zinc-400">{{ $tournament->section->name }}</span> @endif
            </div>
        </div>
        <div class="flex items-center gap-4 shrink-0">
            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button @click="open = !open"
                        class="inline-flex items-center gap-1.5 text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                    <x-bootstrap-icon name="display" class="size-3.5" />
                    TV-sidor
                    <x-bootstrap-icon name="chevron-down" class="size-3" />
                </button>
                <div x-show="open" x-cloak
                     class="absolute right-0 top-full mt-1 w-48 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800 z-20">
                    @foreach([
                        ['route' => 'tournaments.tv.info',      'label' => 'Info & deltagare'],
                        ['route' => 'tournaments.tv.standings', 'label' => 'Ställning'],
                        ['route' => 'tournaments.tv.draw',      'label' => 'Matchschema'],
                        ['route' => 'tournaments.tv.timer',     'label' => 'Rundtimer'],
                        ['route' => 'tournaments.tv.recap',     'label' => 'Resultat-recap'],
                        ['route' => 'tournaments.tv.bracket',   'label' => 'Bracket'],
                        ['route' => 'tournaments.tv.message',   'label' => 'Meddelande'],
                        ['route' => 'tournaments.pdf.standings', 'label' => 'PDF: Ställning'],
                        ['route' => 'tournaments.pdf.bracket',   'label' => 'PDF: Bracket'],
                    ] as $tv)
                        <a href="{{ route($tv['route'], $tournament) }}" target="_blank"
                           class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                            <x-bootstrap-icon name="box-arrow-up-right" class="size-3 text-zinc-400" />
                            {{ $tv['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
            <a href="{{ route('admin.tournaments.edit', $tournament) }}" wire:navigate
               class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 underline underline-offset-2">Redigera</a>
        </div>
    </div>

    @if($flash)
        <div x-data x-init="setTimeout(() => $el.remove(), 3000)"
             class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400">
            {{ $flash }}
        </div>
    @endif
    @if($flashError)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
            {{ $flashError }}
            <button wire:click="$set('flashError', null)" class="ml-2 underline text-xs">Stäng</button>
        </div>
    @endif

    {{-- TV-meddelande --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
        <div class="flex items-center justify-between border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800 px-5 py-3">
            <div class="flex items-center gap-2">
                <x-bootstrap-icon name="tv" class="size-4 text-zinc-400" />
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">TV-meddelande</h2>
                @if($tournament->tv_message)
                    <span class="text-xs text-green-600 dark:text-green-400 font-medium">● Aktivt</span>
                @endif
            </div>
            <div class="flex items-center gap-3">
                @if($tournament->tv_message && !$editingMessage)
                    <button wire:click="clearTvMessage"
                            class="text-xs text-red-500 hover:text-red-700 dark:text-red-400 underline underline-offset-2">
                        Rensa
                    </button>
                @endif
                <button wire:click="{{ $editingMessage ? '$set(\'editingMessage\', false)' : 'openMessageEditor' }}"
                        class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 underline underline-offset-2">
                    {{ $editingMessage ? 'Avbryt' : ($tournament->tv_message ? 'Ändra' : 'Skriv meddelande') }}
                </button>
            </div>
        </div>

        @if($editingMessage)
            <div class="px-5 py-4 space-y-3">
                <textarea wire:model="tvMessage" rows="3" autofocus
                          placeholder="T.ex. Lunchpaus — spel återupptas 13:30"
                          class="inp w-full resize-none text-sm"></textarea>
                <div class="flex items-center gap-3">
                    <button wire:click="saveTvMessage" class="btn btn-primary text-xs">Publicera</button>
                    <p class="text-xs text-zinc-400">Visas direkt på TV-skärmen. Töm fältet och spara för att ta bort.</p>
                </div>
            </div>
        @elseif($tournament->tv_message)
            <div class="px-5 py-3">
                <p class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $tournament->tv_message }}</p>
            </div>
        @else
            <p class="px-5 py-3 text-sm text-zinc-400 dark:text-zinc-500">Inget aktivt meddelande.</p>
        @endif
    </div>

    {{-- Deltagare --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
        <div class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800 px-5 py-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                Deltagare ({{ $this->participants->count() }})
            </h2>
        </div>

        {{-- Table distribution preview for pod phases --}}
        @foreach($this->phases->where('format', 'pod_swiss') as $podPhase)
            @if($podPhase->status === 'pending')
                @php
                    $ppm    = $podPhase->players_per_match ?? 4;
                    $minPpm = $podPhase->min_players_per_match ?? 2;
                    $cnt    = $this->participants->count();
                    $sizes  = $cnt > 0 ? app(\App\Services\TournamentService::class)->tableSizes($cnt, $ppm, $minPpm) : [];
                    $numTables = count($sizes);
                    $minSize   = $sizes ? min($sizes) : 0;
                    $maxSize   = $sizes ? max($sizes) : 0;
                    $nextFull  = $numTables > 0 ? $numTables * $ppm : $ppm;
                    $missing   = $cnt > 0 ? $nextFull - $cnt : $ppm;

                    // Group sizes: "5×4, 3×3"
                    $grouped = collect($sizes)->countBy()->map(fn($c, $s) => "{$c}×{$s}")->values()->implode(', ');

                    $tooFew  = $cnt > 0 && $minSize < $minPpm;     // some table below min
                    $tooMany = $cnt > 0 && $maxSize > $ppm;         // a table exceeds max (n < min_total)
                    $perfect = $cnt > 0 && $cnt % $ppm === 0;
                @endphp

                @if($cnt === 0)
                    <div class="border-b border-zinc-100 dark:border-zinc-800 px-5 py-3 flex items-center gap-2 text-xs text-zinc-400 dark:text-zinc-500">
                        <x-bootstrap-icon name="info-circle" class="size-3.5 shrink-0" />
                        Inga deltagare ännu. {{ $podPhase->name }}: max {{ $ppm }} per bord.
                    </div>
                @elseif($tooFew || $tooMany)
                    <div class="border-b border-amber-200 dark:border-amber-800/40 bg-amber-50/80 dark:bg-amber-900/10 px-5 py-3 text-xs text-amber-700 dark:text-amber-400 space-y-1">
                        <div class="flex items-start gap-1.5">
                            <x-bootstrap-icon name="exclamation-triangle" class="size-3.5 shrink-0 mt-0.5" />
                            <div>
                                <strong>{{ $podPhase->name }}:</strong>
                                @if($tooMany)
                                    Bara {{ $cnt }} deltagare — ett bord skulle ha {{ $maxSize }} spelare (max är {{ $ppm }}).
                                    Lägg till {{ $ppm - $cnt }} deltagare till för att fylla ett bord, eller {{ $minPpm - $cnt + (int)ceil($cnt/$ppm)*$ppm - $cnt }} fler för jämn fördelning.
                                @elseif($tooFew)
                                    {{ $cnt }} deltagare ger ett bord med bara {{ $minSize }} spelare (min är {{ $minPpm }}).
                                    Lägg till {{ $missing }} deltagare för {{ $numTables * $ppm }} totalt (alla bord fulla).
                                @endif
                            </div>
                        </div>
                    </div>
                @elseif(! $perfect)
                    <div class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/20 px-5 py-3 text-xs text-zinc-500 dark:text-zinc-400 flex items-start gap-1.5">
                        <x-bootstrap-icon name="info-circle" class="size-3.5 shrink-0 mt-0.5" />
                        <span>
                            <strong>{{ $podPhase->name }}:</strong>
                            {{ $cnt }} deltagare → {{ $numTables }} bord ({{ $grouped }}).
                            +{{ $missing }} för att fylla alla bord ({{ $nextFull }} totalt).
                        </span>
                    </div>
                @else
                    <div class="border-b border-green-200 dark:border-green-800/40 bg-green-50/50 dark:bg-green-900/10 px-5 py-2.5 text-xs text-green-700 dark:text-green-400 flex items-center gap-1.5">
                        <x-bootstrap-icon name="check-circle" class="size-3.5 shrink-0" />
                        <span><strong>{{ $podPhase->name }}:</strong> {{ $cnt }} deltagare → {{ $numTables }} bord à {{ $ppm }}. Perfekt fördelning!</span>
                    </div>
                @endif
            @endif
        @endforeach

        {{-- Event-import --}}
        @if($tournament->event_id && in_array($tournament->status, ['draft', 'registration']))
            <div class="border-b border-zinc-100 dark:border-zinc-800 px-5 py-3">
                <button wire:click="importFromEvent"
                        wire:confirm="Importera alla bekräftade event-deltagare som saknas i turneringen?"
                        class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 flex items-center gap-1.5 underline underline-offset-2">
                    <x-bootstrap-icon name="download" class="size-3.5" />
                    Importera bekräftade event-deltagare
                </button>
            </div>
        @endif

        {{-- Lägg till --}}
        @if(in_array($tournament->status, ['draft', 'registration']))
        <div class="border-b border-zinc-100 dark:border-zinc-800 px-5 py-4 space-y-3">

            {{-- Läge-toggle --}}
            <div class="flex rounded-lg bg-zinc-100 dark:bg-zinc-800 p-0.5 w-fit">
                <button wire:click="$set('addingGuest', false)"
                        @class(['rounded-md px-3 py-1 text-xs font-medium transition-colors',
                            'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm' => !$addingGuest,
                            'text-zinc-500 dark:text-zinc-400' => $addingGuest])>
                    Medlem
                </button>
                <button wire:click="$set('addingGuest', true)"
                        @class(['rounded-md px-3 py-1 text-xs font-medium transition-colors',
                            'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm' => $addingGuest,
                            'text-zinc-500 dark:text-zinc-400' => !$addingGuest])>
                    Gäst
                </button>
            </div>

            @if(!$addingGuest)
            {{-- Sök medlem --}}
            <div class="flex items-start gap-2" x-data="{ open: false }">
                <div class="flex-1 relative">
                    <input wire:model.live.debounce.250ms="searchMember" type="text"
                           placeholder="Sök och lägg till deltagare…"
                           @focus="open = true" @click.outside="open = false"
                           class="inp w-full" />
                    @if($this->memberSuggestions->isNotEmpty())
                    <div x-show="open" class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                        @foreach($this->memberSuggestions as $m)
                            <button type="button" wire:click="addParticipant({{ $m->id }})" @click="open = false"
                                    class="w-full px-4 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                {{ $m->full_name }}
                            </button>
                        @endforeach
                    </div>
                    @endif
                </div>
                <input wire:model="addSeed" type="number" min="1" placeholder="Seed" class="inp w-20" />
            </div>
            @else
            {{-- Gäst --}}
            <div class="flex items-start gap-2">
                <div class="flex-1 space-y-2">
                    <input wire:model="guestName" type="text" maxlength="120" placeholder="Namn *"
                           class="inp w-full @error('guestName') border-red-300 @enderror" />
                    @error('guestName') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    <input wire:model="guestEmail" type="email" maxlength="120" placeholder="E-post (frivilligt)"
                           class="inp w-full" />
                </div>
                <input wire:model="addSeed" type="number" min="1" placeholder="Seed" class="inp w-20" />
                <button wire:click="addGuest" class="btn btn-primary shrink-0">Lägg till</button>
            </div>
            @endif
            {{-- Registration fields --}}
            @foreach($tournament->registration_fields ?? [] as $field)
                <div>
                    <label class="lbl text-xs">{{ $field['label'] }}{{ ($field['required'] ?? false) ? ' *' : '' }}</label>
                    @if(($field['type'] ?? 'text') === 'select')
                        <select wire:model="regData.{{ $field['key'] }}" class="inp mt-1 w-full text-sm">
                            <option value="">Välj…</option>
                            @foreach(explode(',', $field['options'] ?? '') as $opt)
                                <option value="{{ trim($opt) }}">{{ trim($opt) }}</option>
                            @endforeach
                        </select>
                    @elseif(($field['type'] ?? 'text') === 'textarea')
                        <textarea wire:model="regData.{{ $field['key'] }}" rows="2" class="inp mt-1 w-full text-sm resize-none"></textarea>
                    @else
                        <input wire:model="regData.{{ $field['key'] }}" type="text" class="inp mt-1 w-full text-sm" />
                    @endif
                </div>
            @endforeach
        </div>
        @endif

        {{-- Participant list --}}
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse($this->participants as $p)
                <div class="flex items-center gap-3 px-5 py-2.5">
                    <span class="w-8 text-right text-xs text-zinc-400 tabular-nums">
                        @if($p->seed) #{{ $p->seed }} @else — @endif
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $p->displayName() }}</span>
                            @if($p->isGuest())
                                <span class="text-xs text-zinc-400 border border-zinc-300 dark:border-zinc-600 px-1.5 rounded">Gäst</span>
                            @endif
                        </div>
                        @if($p->status === TournamentParticipant::STATUS_WAITLISTED)
                            <span class="ml-2 text-xs text-amber-500">väntelista</span>
                        @elseif($p->status === TournamentParticipant::STATUS_WITHDRAWN)
                            <span class="ml-2 text-xs text-zinc-400 line-through">avanmäld</span>
                        @endif
                        @if(! empty($p->registration_data))
                            <div class="flex gap-1.5 flex-wrap mt-0.5">
                                @foreach($p->registration_data as $val)
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400 bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0 rounded">{{ $val }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    {{-- Check-in --}}
                    @if(in_array($tournament->status, ['registration', 'ongoing']))
                        @if($p->status === TournamentParticipant::STATUS_REGISTERED)
                            <button wire:click="checkIn({{ $p->id }})"
                                    class="text-xs text-zinc-400 hover:text-green-600 dark:hover:text-green-400 shrink-0" title="Checka in">
                                <x-bootstrap-icon name="circle" class="size-4" />
                            </button>
                        @elseif($p->status === TournamentParticipant::STATUS_CHECKED_IN)
                            <button wire:click="undoCheckIn({{ $p->id }})"
                                    class="text-xs text-green-500 hover:text-zinc-400 shrink-0" title="Ångra incheckning">
                                <x-bootstrap-icon name="check-circle-fill" class="size-4" />
                            </button>
                        @endif
                    @endif
                    @if(in_array($tournament->status, ['draft', 'registration']))
                    <div class="flex items-center gap-2 shrink-0">
                        <input type="number" min="1" placeholder="Seed"
                               wire:change="updateSeed({{ $p->id }}, $event.target.value)"
                               value="{{ $p->seed }}"
                               class="w-16 rounded border border-zinc-300 bg-white px-2 py-0.5 text-xs dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        <button wire:click="removeParticipant({{ $p->id }})"
                                class="text-xs text-red-400 hover:text-red-600">Ta bort</button>
                    </div>
                    @endif
                </div>
            @empty
                <p class="px-5 py-6 text-sm text-zinc-400 dark:text-zinc-500 text-center">Inga deltagare ännu.</p>
            @endforelse
        </div>
    </div>

    {{-- Faser --}}
    @foreach($this->phases as $phase)
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
            <div class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800 px-5 py-3 flex items-center justify-between">
                <div>
                    <span class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $phase->name }}</span>
                    <span class="ml-2 text-xs text-zinc-400">{{ $phase->formatLabel() }}</span>
                </div>
                <div class="flex items-center gap-3">
                    @if($phase->status === 'pending')
                        <a href="{{ route('admin.tournaments.draw', [$tournament, $phase]) }}" wire:navigate
                           class="btn btn-primary text-xs flex items-center gap-1.5">
                            <x-bootstrap-icon name="shuffle" class="size-3.5" />
                            Starta lottning
                        </a>
                    @elseif($phase->status === 'ongoing')
                        @if(app(\App\Services\TournamentService::class)->canStartNextRound($phase))
                            <button wire:click="nextRound({{ $phase->id }})"
                                    class="rounded-lg bg-blue-50 border border-blue-200 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100 dark:bg-blue-900/20 dark:border-blue-800 dark:text-blue-400">
                                Nästa runda
                            </button>
                        @endif
                        @if(app(\App\Services\TournamentService::class)->isPhaseComplete($phase))
                            <button wire:click="completePhase({{ $phase->id }})"
                                    wire:confirm="Avsluta fas '{{ $phase->name }}'?"
                                    class="rounded-lg bg-green-50 border border-green-200 px-3 py-1.5 text-xs font-medium text-green-700 hover:bg-green-100 dark:bg-green-900/20 dark:border-green-800 dark:text-green-400">
                                Avsluta fas
                            </button>
                        @endif
                    @endif
                </div>
            </div>

            {{-- Rounds --}}
            @foreach($phase->rounds as $round)
                @php $matchesBySlot = $round->matches->where('status', '!=', 'bye')->groupBy('time_slot'); @endphp
                <div class="border-b border-zinc-100 dark:border-zinc-800 last:border-0">

                    {{-- Round header --}}
                    @php
                        $totalMatchCount = $round->matches->where('status', '!=', 'bye')->count();
                        $passCount       = $matchesBySlot->count();
                        $tablesNeeded    = $passCount > 1
                            ? $phase->tables_available
                            : $totalMatchCount;
                    @endphp
                    <div class="px-5 py-2.5 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500">Runda {{ $round->round_number }}</span>
                            @if($round->status === 'completed')
                                <x-bootstrap-icon name="check-circle-fill" class="size-3.5 text-green-500" />
                            @endif
                            @if($totalMatchCount > 0)
                                <span class="text-xs text-zinc-400 dark:text-zinc-500">
                                    {{ $tablesNeeded }} bord
                                    @if($passCount > 1)
                                        · {{ $totalMatchCount }} matcher × {{ $passCount }} pass
                                    @endif
                                </span>
                            @endif
                            @foreach($round->slot_times ?? [] as $sn => $st)
                                @php
                                    $slotStart = is_array($st) ? ($st['start'] ?? '') : ($st ?? '');
                                    $slotEnd   = is_array($st) ? ($st['end'] ?? '') : '';
                                @endphp
                                @if($slotStart)
                                    <span class="text-xs bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 px-2 py-0.5 rounded-full">
                                        Pass {{ $sn }}: {{ $slotStart }}{{ $slotEnd ? '–'.$slotEnd : '' }}
                                    </span>
                                @endif
                            @endforeach
                        </div>
                        @if($phase->status === 'ongoing')
                            <div class="flex items-center gap-3 shrink-0">
                                <button wire:click="reshuffleTables({{ $round->id }})"
                                        title="Lotta om bordsnummer"
                                        class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                                    <x-bootstrap-icon name="shuffle" class="size-4" />
                                </button>
                                <button wire:click="openSlotEditor({{ $round->id }})"
                                        class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 flex items-center gap-1 underline underline-offset-2">
                                    <x-bootstrap-icon name="clock" class="size-3.5" />
                                    Tider
                                </button>
                            </div>
                        @endif
                    </div>

                    {{-- Slot time editor --}}
                    @if($editSlotRoundId === $round->id)
                        <div class="border-t border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/40 px-5 py-3 space-y-3">
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Starttid och sluttid per pass (sluttid används av timern)</p>
                            <div class="space-y-2">
                                @foreach($slotTimes as $sNum => $sTimes)
                                    <div class="flex items-center gap-3 flex-wrap">
                                        <label class="text-xs font-medium text-zinc-500 shrink-0 w-12">Pass {{ $sNum }}</label>
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-xs text-zinc-400">Från</span>
                                            <input wire:model="slotTimes.{{ $sNum }}.start" type="time"
                                                   class="rounded border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                                        </div>
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-xs text-zinc-400">Till</span>
                                            <input wire:model="slotTimes.{{ $sNum }}.end" type="time"
                                                   class="rounded border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="flex gap-2">
                                <button wire:click="saveSlotTimes" class="btn btn-primary text-xs">Spara</button>
                                <button wire:click="$set('editSlotRoundId', null)" class="text-xs text-zinc-400 hover:text-zinc-600">Avbryt</button>
                            </div>
                        </div>
                    @endif

                    {{-- Quick extend buttons for active slots --}}
                    @if($phase->status === 'ongoing' && $round->status === 'ongoing')
                        @foreach($round->slot_times ?? [] as $sn => $st)
                            @php
                                $slotEnd = is_array($st) ? ($st['end'] ?? '') : '';
                            @endphp
                            @if($slotEnd)
                                <div class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/20 px-5 py-2 flex items-center gap-3 flex-wrap">
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400 shrink-0">Pass {{ $sn }} slutar {{ $slotEnd }} — Förläng:</span>
                                    @foreach([10, 15, 30] as $min)
                                        <button wire:click="extendSlot({{ $round->id }}, {{ $sn }}, {{ $min }})"
                                                class="text-xs rounded border border-zinc-300 dark:border-zinc-600 px-2.5 py-0.5 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-700">
                                            +{{ $min }} min
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    @endif

                    {{-- Multi-slot warning --}}
                    @if($matchesBySlot->count() > 1)
                        <div class="border-t border-amber-200 dark:border-amber-800/40 bg-amber-50/60 dark:bg-amber-900/10 px-5 py-1.5 text-xs text-amber-700 dark:text-amber-400 flex items-center gap-1.5">
                            <x-bootstrap-icon name="exclamation-triangle" class="size-3.5 shrink-0" />
                            {{ $matchesBySlot->count() }} pass krävs ({{ $phase->tables_available }} bord tillgängliga)
                        </div>
                    @endif

                    {{-- Matches grouped by time slot --}}
                    @foreach($matchesBySlot->sortKeys() as $slotNum => $slotMatches)
                        @if($matchesBySlot->count() > 1)
                            <div class="px-5 py-1 bg-zinc-50 dark:bg-zinc-800/30 text-xs font-medium text-zinc-500 dark:text-zinc-400 border-t border-zinc-100 dark:border-zinc-800 flex items-center gap-2">
                                Pass {{ $slotNum }}
                                @if(($round->slot_times[$slotNum] ?? '') !== '')
                                    <span class="text-zinc-400">· {{ $round->slot_times[$slotNum] }}</span>
                                @endif
                            </div>
                        @endif
                        @foreach($slotMatches->sortBy('table_number') as $match)
                            <div class="flex items-center gap-3 px-5 py-2.5 divide-y-0 border-t border-zinc-50 dark:border-zinc-800/50">
                                {{-- Table number --}}
                                @if($match->table_number)
                                    <span class="shrink-0 w-16 text-xs text-zinc-400 tabular-nums">Bord {{ $match->table_number }}</span>
                                @endif
                                <div class="flex-1 min-w-0">
                                    @if($match->status === 'bye')
                                        <span class="text-xs text-zinc-400 italic">Bye</span>
                                        @foreach($match->participants as $mp)
                                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $mp->participant?->displayName() }}</span>
                                        @endforeach
                                    @else
                                        <div class="flex items-center gap-2 flex-wrap">
                                            @if($match->group_name === 'rematch')
                                                <span class="text-xs text-amber-500 dark:text-amber-400 border border-amber-300 dark:border-amber-700 px-1.5 py-0 rounded shrink-0">
                                                    Re-match
                                                </span>
                                            @endif
                                            @foreach($match->participants as $mp)
                                                <span @class([
                                                    'text-sm',
                                                    'font-bold text-zinc-900 dark:text-white' => $mp->is_winner,
                                                    'text-zinc-600 dark:text-zinc-400' => ! $mp->is_winner,
                                                ])>{{ $mp->participant?->displayName() }}
                                                @if($match->status === 'completed')
                                                    <span class="text-xs text-zinc-400">
                                                        @if($phase->players_per_match > 2) {{ $mp->placement ? "#{$mp->placement}" : '' }} @endif
                                                        @if($phase->uses_game_points) ({{ tournament_num($mp->game_score) }} sp) @endif
                                                        {{ tournament_num($mp->match_points) }}@if($mp->bonus_points > 0)+{{ tournament_num($mp->bonus_points) }}@endif mp
                                                    </span>
                                                @endif
                                                </span>
                                                @if(! $loop->last) <span class="text-zinc-300 dark:text-zinc-600">vs</span> @endif
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                @if($phase->status === 'ongoing' && $match->status === 'pending' && $editMatchId !== $match->id)
                                    <button wire:click="openResult({{ $match->id }})"
                                            class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 underline underline-offset-2 shrink-0">
                                        Mata in resultat
                                    </button>
                                @elseif($phase->status === 'ongoing' && $match->status === 'completed' && $editMatchId !== $match->id)
                                    <button wire:click="resetMatch({{ $match->id }})"
                                            @if($round->status === 'completed') wire:confirm="Rundan är markerad som klar. Öppna den igen och justera resultatet?" @endif
                                            class="text-xs text-amber-500 hover:text-amber-700 dark:text-amber-400 underline underline-offset-2 shrink-0">
                                        Korrigera
                                    </button>
                                @endif
                            </div>

                            {{-- Inline result form — shown for both pending and correction of completed --}}
                            @if($editMatchId === $match->id)
                                <div class="bg-zinc-50 dark:bg-zinc-800/50 px-5 py-4 space-y-3 border-t border-zinc-100 dark:border-zinc-800">
                                    @foreach($match->participants as $mp)
                                        <div class="flex items-center gap-3">
                                            <span class="w-32 text-sm text-zinc-700 dark:text-zinc-300 truncate">{{ $mp->participant?->displayName() }}</span>
                                            @if($phase->players_per_match > 2)
                                                <div>
                                                    <label class="text-xs text-zinc-400">Plats (1–{{ $phase->players_per_match }})</label>
                                                    <input wire:model="scores.{{ $mp->participant_id }}.placement" type="number" min="1" max="{{ $phase->players_per_match }}"
                                                           class="inp w-16 text-sm" />
                                                </div>
                                            @endif
                                            @if($phase->uses_game_points || $phase->players_per_match === 2)
                                                <div>
                                                    <label class="text-xs text-zinc-400">{{ $phase->uses_game_points ? 'Spelpoäng' : 'Poäng' }}</label>
                                                    <input wire:model="scores.{{ $mp->participant_id }}.game_score"
                                                           type="text" inputmode="decimal" placeholder="0"
                                                           class="inp w-20 text-sm" />
                                                </div>
                                            @endif
                                            <div>
                                                <label class="text-xs text-zinc-400">Bonus MP</label>
                                                <input wire:model="scores.{{ $mp->participant_id }}.bonus_points"
                                                       type="text" inputmode="decimal" placeholder="0"
                                                       class="inp w-16 text-sm" />
                                            </div>
                                        </div>
                                    @endforeach
                                    <div class="flex gap-2">
                                        <button wire:click="saveResult" class="btn btn-primary text-xs">Spara</button>
                                        <button wire:click="$set('editMatchId', null)" class="text-xs text-zinc-400 hover:text-zinc-600">Avbryt</button>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    @endforeach

                    {{-- Bye matches --}}
                    @foreach($round->matches->where('status', 'bye') as $match)
                        <div class="flex items-center gap-3 px-5 py-2 border-t border-zinc-50 dark:border-zinc-800/50">
                            <span class="text-xs text-zinc-400 italic">Bye —</span>
                            @foreach($match->participants as $mp)
                                <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $mp->participant?->displayName() }}</span>
                            @endforeach
                        </div>
                    @endforeach

                </div>
            @endforeach

            {{-- Standings --}}
            @if($phase->standings->isNotEmpty())
                <div class="border-t border-zinc-200 dark:border-zinc-700">
                    <div class="px-5 py-2 text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500">Standings</div>
                    <table class="w-full text-sm">
                        <thead class="border-b border-zinc-100 dark:border-zinc-800">
                            <tr>
                                <th class="px-5 py-2 text-left font-medium text-zinc-400 w-10">#</th>
                                <th class="px-5 py-2 text-left font-medium text-zinc-400">Deltagare</th>
                                <th class="px-5 py-2 text-center font-medium text-zinc-400">MP</th>
                                @if($phase->uses_game_points)
                                    <th class="px-5 py-2 text-center font-medium text-zinc-400">SP</th>
                                @endif
                                <th class="px-5 py-2 text-center font-medium text-zinc-400 hidden sm:table-cell">V/O/F</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                            @foreach($phase->standings->sortBy('rank') as $s)
                                <tr>
                                    <td class="px-5 py-2 text-zinc-400 tabular-nums">{{ $s->rank }}</td>
                                    <td class="px-5 py-2 font-medium text-zinc-900 dark:text-white">{{ $s->participant?->displayName() }}</td>
                                    <td class="px-5 py-2 text-center tabular-nums font-bold text-zinc-800 dark:text-zinc-200">{{ tournament_num($s->match_points) }}</td>
                                    @if($phase->uses_game_points)
                                        <td class="px-5 py-2 text-center tabular-nums text-zinc-600 dark:text-zinc-400">{{ tournament_num($s->game_points) }}</td>
                                    @endif
                                    <td class="px-5 py-2 text-center text-xs text-zinc-400 hidden sm:table-cell">{{ $s->wins }}/{{ $s->draws }}/{{ $s->losses }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Advance to next phase --}}
            @if($phase->status === 'completed')
                @php $nextPhase = $this->phases->where('phase_number', $phase->phase_number + 1)->first(); @endphp
                @if($nextPhase && $nextPhase->status === 'pending')
                    <div class="border-t border-zinc-100 dark:border-zinc-800 px-5 py-3 flex justify-end">
                        <button wire:click="advanceToNextPhase({{ $phase->id }}, {{ $nextPhase->id }})"
                                class="btn btn-primary text-xs">
                            Flytta topp {{ $phase->advancement_count ?? 'alla' }} → {{ $nextPhase->name }}
                        </button>
                    </div>
                @endif
            @endif
        </div>
    @endforeach

</div>
