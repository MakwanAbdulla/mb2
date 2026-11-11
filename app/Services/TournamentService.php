<?php

namespace App\Services;

use App\Models\Tournament\Tournament;
use App\Models\Tournament\TournamentMatch;
use App\Models\Tournament\TournamentMatchParticipant;
use App\Models\Tournament\TournamentParticipant;
use App\Models\Tournament\TournamentPhase;
use App\Models\Tournament\TournamentRound;
use App\Models\Tournament\TournamentStanding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TournamentService
{
    // ── Draw ceremony ─────────────────────────────────────────────────────────

    /**
     * Generate a randomised draw for a phase without starting it.
     * Returns structured slot data ready for the draw screen.
     * Seeded participants are pre-placed; unseeded are shuffled.
     */
    public function prepareDraw(TournamentPhase $phase): array
    {
        $participants = $this->seededParticipants($phase->tournament);
        $seeded       = $participants->filter(fn ($p) => $p->seed !== null)->sortBy('seed')->values();
        $unseeded     = $participants->filter(fn ($p) => $p->seed === null)->shuffle()->values();

        $format           = $phase->format;
        $playersPerMatch  = $phase->players_per_match ?? 2;
        $n                = $participants->count();

        if ($this->isSwissFormat($format)) {
            return $this->buildPodDrawSlots($seeded, $unseeded, $playersPerMatch);
        }

        if (in_array($format, [TournamentPhase::FORMAT_SINGLE_ELIM, TournamentPhase::FORMAT_DOUBLE_ELIM])) {
            return $this->buildElimDrawSlots($seeded, $unseeded, $n);
        }

        // Round robin / groups
        $groupCount = max(1, $phase->group_count ?? 1);
        return $this->buildGroupDrawSlots($seeded, $unseeded, $groupCount);
    }

    private function isSwissFormat(string $format): bool
    {
        return in_array($format, [TournamentPhase::FORMAT_SWISS, TournamentPhase::FORMAT_POD_SWISS]);
    }

    private function buildPodDrawSlots(
        \Illuminate\Support\Collection $seeded,
        \Illuminate\Support\Collection $unseeded,
        int $playersPerMatch
    ): array {
        $all  = $seeded->concat($unseeded);
        $n    = $all->count();

        if ($n === 0) {
            return ['layout' => 'pods', 'groups' => [], 'group_label' => 'Bord'];
        }

        // Determine min per table from phase if available (passed as parameter for draw preview)
        $min   = 2; // draw preview uses a sensible default
        $sizes = $this->tableSizes($n, $playersPerMatch, $min);
        $pods  = count($sizes);

        // Build empty slots respecting each table's target size
        $slots = [];
        foreach ($sizes as $size) {
            $slots[] = array_fill(0, $size, null);
        }

        // Place seeds: one per table evenly, then fill remaining
        $all    = $seeded->concat($unseeded)->values();
        $placed = 0;

        // First pass: seeds — one per table
        foreach ($seeded as $idx => $p) {
            $tableIdx = $idx % $pods;
            // Find first empty slot in this table
            for ($s = 0; $s < count($slots[$tableIdx]); $s++) {
                if ($slots[$tableIdx][$s] === null) {
                    $slots[$tableIdx][$s] = $this->slotData($p, true);
                    $placed++;
                    break;
                }
            }
        }

        // Second pass: unseeded fill remaining null slots in order
        $tableIdx = 0;
        foreach ($unseeded as $p) {
            // Find next table with empty slot
            while ($tableIdx < $pods) {
                $hasEmpty = false;
                for ($s = 0; $s < count($slots[$tableIdx]); $s++) {
                    if ($slots[$tableIdx][$s] === null) {
                        $slots[$tableIdx][$s] = $this->slotData($p, false);
                        $hasEmpty = true;
                        break;
                    }
                }
                if ($hasEmpty) break;
                $tableIdx++;
            }
        }

        return ['layout' => 'pods', 'groups' => array_values($slots), 'group_label' => 'Bord'];
    }

    private function buildElimDrawSlots(
        \Illuminate\Support\Collection $seeded,
        \Illuminate\Support\Collection $unseeded,
        int $n
    ): array {
        if ($n === 0) {
            return ['layout' => 'bracket', 'groups' => [], 'group_label' => 'Match'];
        }

        $size         = $this->nextPowerOfTwo($n);
        $orderedSeeds = $this->seededBracketSlots($size); // [1, 8, 5, 4, 3, 6, 7, 2] for 8-slot

        // Build slot array indexed by bracket position
        $slotMap = [];
        foreach ($orderedSeeds as $bracketPos => $seedNum) {
            $p = $seeded->firstWhere('seed', $seedNum);
            if (! $p) {
                $p = $unseeded->shift();
            }
            if ($bracketPos < $n) {
                $slotMap[$bracketPos] = $p ? $this->slotData($p, (bool) $p->seed) : null;
            }
        }
        // Any remaining unseeded
        foreach ($unseeded as $p) {
            for ($i = 0; $i < $size; $i++) {
                if (! isset($slotMap[$i])) {
                    $slotMap[$i] = $this->slotData($p, false);
                    break;
                }
            }
        }

        // Group into first-round pairs
        $pairs = [];
        for ($i = 0; $i < $size / 2; $i++) {
            $pairs[] = [
                $slotMap[$i * 2] ?? null,
                $slotMap[$i * 2 + 1] ?? null,
            ];
        }

        return ['layout' => 'bracket', 'groups' => $pairs, 'group_label' => 'Match'];
    }

    private function buildGroupDrawSlots(
        \Illuminate\Support\Collection $seeded,
        \Illuminate\Support\Collection $unseeded,
        int $groupCount
    ): array {
        if ($seeded->isEmpty() && $unseeded->isEmpty()) {
            return ['layout' => 'groups', 'groups' => [], 'group_label' => 'Grupp'];
        }

        $letters = range('A', 'Z');
        $groups  = array_fill(0, $groupCount, []);

        // FIFA pots: distribute seeds one per group per pot
        foreach ($seeded as $idx => $p) {
            $groups[$idx % $groupCount][] = $this->slotData($p, true);
        }
        foreach ($unseeded as $idx => $p) {
            $groups[$idx % $groupCount][] = $this->slotData($p, false);
        }

        $labelled = [];
        foreach ($groups as $i => $members) {
            $labelled[] = ['label' => 'Grupp ' . $letters[$i], 'members' => $members];
        }

        return ['layout' => 'groups', 'groups' => $labelled, 'group_label' => 'Grupp'];
    }

    private function slotData(TournamentParticipant $p, bool $isSeeded): array
    {
        return [
            'id'        => $p->id,
            'name'      => $p->displayName(),
            'seed'      => $p->seed,
            'is_seeded' => $isSeeded,
        ];
    }

    /**
     * Save the pre-generated draw to settings and start the phase.
     * Called after the draw ceremony is completed.
     */
    public function commitDraw(TournamentPhase $phase, array $draw): void
    {
        $phase->update(['settings' => array_merge($phase->settings ?? [], ['committed_draw' => $draw])]);
        $this->startPhase($phase);
    }

    // ── Phase lifecycle ───────────────────────────────────────────────────────

    public function startPhase(TournamentPhase $phase): void
    {
        if ($phase->status !== TournamentPhase::STATUS_PENDING) {
            throw new \RuntimeException('Fasen är redan startad eller avslutad.');
        }

        DB::transaction(function () use ($phase) {
            $phase->update(['status' => TournamentPhase::STATUS_ONGOING]);
            $phase->tournament->update(['status' => Tournament::STATUS_ONGOING]);

            match ($phase->format) {
                TournamentPhase::FORMAT_SINGLE_ELIM => $this->buildSingleElimBracket($phase),
                TournamentPhase::FORMAT_DOUBLE_ELIM => $this->buildDoubleElimBracket($phase),
                TournamentPhase::FORMAT_ROUND_ROBIN => $this->buildRoundRobin($phase),
                TournamentPhase::FORMAT_SWISS,
                TournamentPhase::FORMAT_POD_SWISS   => $this->generateFirstSwissRound($phase),
            };
        });
    }

    public function generateNextRound(TournamentPhase $phase): TournamentRound
    {
        if (! $this->canStartNextRound($phase)) {
            throw new \RuntimeException('Nuvarande runda är inte klar eller fasen är avslutad.');
        }

        return DB::transaction(function () use ($phase) {
            return match ($phase->format) {
                TournamentPhase::FORMAT_SWISS,
                TournamentPhase::FORMAT_POD_SWISS => $this->generateSwissRound($phase),
                default                           => throw new \RuntimeException('generateNextRound gäller bara Swiss-format.'),
            };
        });
    }

    public function canStartNextRound(TournamentPhase $phase): bool
    {
        if (! $phase->isSwiss()) {
            return false;
        }
        $current = $phase->currentRound();
        if (! $current) {
            return false;
        }
        if (! $current->isComplete()) {
            return false;
        }
        $done = $phase->completedRoundsCount();
        return $done < ($phase->rounds_count ?? 99);
    }

    public function isPhaseComplete(TournamentPhase $phase): bool
    {
        if ($phase->isSwiss()) {
            return $phase->completedRoundsCount() >= ($phase->rounds_count ?? 0)
                && ($phase->currentRound()?->isComplete() ?? true);
        }

        // Elimination: all matches complete
        return $phase->matches()->whereNotIn('status', [TournamentMatch::STATUS_COMPLETED, TournamentMatch::STATUS_BYE])->doesntExist();
    }

    public function completePhase(TournamentPhase $phase): void
    {
        $phase->update(['status' => TournamentPhase::STATUS_COMPLETED]);
        $this->recalculateStandings($phase);

        $hasMorePhases = $phase->tournament->phases()
            ->where('phase_number', '>', $phase->phase_number)
            ->exists();

        if (! $hasMorePhases) {
            $phase->tournament->update(['status' => Tournament::STATUS_COMPLETED]);
            $this->awardTournamentXp($phase);
        }
    }

    public function sendRoundNotifications(TournamentRound $round): void
    {
        $participants = $round->phase->tournament->participants()
            ->whereIn('status', [TournamentParticipant::STATUS_REGISTERED, TournamentParticipant::STATUS_CHECKED_IN])
            ->with(['member.user'])
            ->get();

        foreach ($participants as $participant) {
            $email = $participant->member?->user?->email ?? $participant->member?->email;
            if (! $email) {
                continue;
            }

            try {
                \Illuminate\Support\Facades\Mail::to($email)
                    ->send(new \App\Mail\TournamentRoundStartMail($round, $participant));
            } catch (\Throwable) {
                // Never let mail failure block round generation
            }
        }
    }

    private function awardTournamentXp(TournamentPhase $finalPhase): void
    {
        // XP by rank: 1st=100, 2nd=60, 3rd=40, 4th-8th=20, rest=10
        $xpByRank = [1 => 100, 2 => 60, 3 => 40];
        $defaultXp = 20;
        $participationXp = 10;

        $standings = \App\Models\Tournament\TournamentStanding::where('phase_id', $finalPhase->id)
            ->with('participant.member')
            ->get();

        $gamification = app(\App\Services\GamificationService::class);

        foreach ($standings as $s) {
            $member = $s->participant?->member;
            if (! $member) {
                continue;
            }

            $xp = $xpByRank[$s->rank] ?? ($s->rank <= 8 ? $defaultXp : $participationXp);

            $alreadyAwarded = \App\Models\Gamification\XpLog::where('member_id', $member->id)
                ->where('source_type', \App\Models\Gamification\XpLog::SOURCE_TOURNAMENT_PLACEMENT)
                ->where('source_id', $finalPhase->tournament_id)
                ->exists();

            if (! $alreadyAwarded) {
                $gamification->award(
                    $member,
                    \App\Models\Gamification\XpLog::SOURCE_TOURNAMENT_PLACEMENT,
                    $xp,
                    null,
                    $finalPhase->tournament_id,
                    "Plats {$s->rank} i {$finalPhase->tournament->title}",
                );
            }
        }
    }

    public function advanceParticipants(TournamentPhase $from, TournamentPhase $to): void
    {
        $count = $from->advancement_count ?? $to->tournament->activeParticipants()->count();

        $standings = TournamentStanding::where('phase_id', $from->id)
            ->orderBy('rank')
            ->limit($count)
            ->pluck('participant_id');

        foreach ($standings as $participantId) {
            // Participant already belongs to the tournament — just ensure they're active
            TournamentParticipant::where('id', $participantId)
                ->update(['status' => TournamentParticipant::STATUS_REGISTERED]);
        }

        // Mark non-advancing participants as withdrawn from next phase perspective
        $notAdvancing = TournamentParticipant::where('tournament_id', $from->tournament_id)
            ->whereNotIn('id', $standings)
            ->pluck('id');

        // Store advancing list in phase settings for reference
        $from->update(['settings' => array_merge($from->settings ?? [], ['advanced' => $standings->toArray()])]);
    }

    // ── Result recording ─────────────────────────────────────────────────────

    /**
     * Record results for a match.
     * For 1v1: $scores = [participant_id => game_score, ...]
     * For pod: $scores = [participant_id => ['placement' => 1, 'game_score' => 42], ...]
     */
    public function recordResult(TournamentMatch $match, array $scores): void
    {
        DB::transaction(function () use ($match, $scores) {
            $phase = $match->phase();

            foreach ($scores as $participantId => $data) {
                $placement   = is_array($data) ? ($data['placement'] ?? null) : null;
                $gameScore   = is_array($data) ? ($data['game_score'] ?? null) : $data;
                $bonusPoints = is_array($data) ? (float) ($data['bonus_points'] ?? 0) : 0.0;
                $isWinner    = false;
                $matchPts    = null;

                if ($phase->players_per_match > 2 && $placement !== null) {
                    $matchPts = $this->placementPoints($phase, (int) $placement);
                    $isWinner = (int) $placement === 1;
                }

                TournamentMatchParticipant::updateOrCreate(
                    ['match_id' => $match->id, 'participant_id' => $participantId],
                    [
                        'game_score'   => $gameScore,
                        'placement'    => $placement,
                        'match_points' => $matchPts,
                        'is_winner'    => $isWinner,
                        'bonus_points' => $bonusPoints,
                    ]
                );
            }

            // For 1v1: determine winner by game_score (or explicit flag)
            if ($phase->players_per_match === 2) {
                $entries = TournamentMatchParticipant::where('match_id', $match->id)->get();
                if ($entries->count() === 2) {
                    $winner = $entries->sortByDesc('game_score')->first();
                    $loser  = $entries->sortBy('game_score')->first();

                    $draw = $winner->game_score === $loser->game_score;

                    $winner->update([
                        'is_winner'   => ! $draw,
                        'match_points' => $draw ? $phase->match_points_draw : $phase->match_points_win,
                    ]);
                    $loser->update([
                        'is_winner'   => false,
                        'match_points' => $draw ? $phase->match_points_draw : $phase->match_points_loss,
                    ]);

                    // Advance winner in elimination bracket
                    if ($phase->isElimination() && ! $draw) {
                        $this->advanceInBracket($match, $winner->participant_id, $loser->participant_id);
                    }
                }
            }

            $match->update(['status' => TournamentMatch::STATUS_COMPLETED]);

            // Check if round is now complete
            $round = $match->round;
            if ($round->isComplete()) {
                $round->update(['status' => TournamentRound::STATUS_COMPLETED]);
            }

            $this->recalculateStandings($match->phase());
        });
    }

    /**
     * Reset a completed match back to pending so results can be corrected.
     */
    public function resetMatch(TournamentMatch $match): void
    {
        DB::transaction(function () use ($match) {
            $phase = $match->phase();

            // Clear participant scores
            $match->participants()->update([
                'game_score'   => null,
                'match_points' => null,
                'placement'    => null,
                'bonus_points' => 0,
                'is_winner'    => false,
            ]);

            // For elimination: remove participants that were advanced by this match
            if ($phase->isElimination() && $match->next_match_id) {
                $advancedIds = $match->participants()->pluck('participant_id');
                TournamentMatchParticipant::where('match_id', $match->next_match_id)
                    ->whereIn('participant_id', $advancedIds)
                    ->delete();

                // Reset next match to pending if it was completed
                TournamentMatch::where('id', $match->next_match_id)
                    ->where('status', TournamentMatch::STATUS_COMPLETED)
                    ->update(['status' => TournamentMatch::STATUS_PENDING]);
            }

            $match->update(['status' => TournamentMatch::STATUS_PENDING]);

            // Reopen round only if it was marked as completed
            if ($match->round->status === TournamentRound::STATUS_COMPLETED) {
                $match->round->update(['status' => TournamentRound::STATUS_ONGOING]);
            }

            $this->recalculateStandings($phase);
        });
    }

    /**
     * Validate scores before saving. Returns array of error messages.
     */
    public function validateScores(TournamentMatch $match, array $scores): array
    {
        $phase  = $match->phase();
        $errors = [];

        if ($phase->players_per_match <= 2) {
            return $errors; // 1v1: no placement to validate
        }

        $placements = array_filter(array_column($scores, 'placement'), fn ($p) => $p !== '' && $p !== null);

        if (count($placements) < count($scores)) {
            $errors[] = 'Alla spelare måste ha en placering.';
        }

        $n = $phase->players_per_match;
        sort($placements);
        $expected = range(1, count($scores));

        if ($placements != $expected) {
            $errors[] = "Placeringar måste vara 1–" . count($scores) . " utan dubletter.";
        }

        return $errors;
    }

    public function recalculateStandings(TournamentPhase $phase): void
    {
        $participantIds = $phase->tournament->activeParticipants()->pluck('id');

        $rows = TournamentMatchParticipant::whereHas('match', fn ($q) => $q->whereHas('round', fn ($r) => $r->where('phase_id', $phase->id)))
            ->whereIn('participant_id', $participantIds)
            ->select('participant_id',
                DB::raw('SUM(COALESCE(match_points, 0) + COALESCE(bonus_points, 0)) as match_points'),
                DB::raw('SUM(game_score) as game_points'),
                DB::raw('SUM(CASE WHEN is_winner = 1 THEN 1 ELSE 0 END) as wins'),
                DB::raw('SUM(CASE WHEN is_winner = 0 AND match_points > 0 THEN 1 ELSE 0 END) as draws'),
                DB::raw('SUM(CASE WHEN is_winner = 0 AND match_points = 0 THEN 1 ELSE 0 END) as losses')
            )
            ->groupBy('participant_id')
            ->get()
            ->keyBy('participant_id');

        // Build standings sorted by match_points DESC, then game_points DESC
        $sorted = $participantIds->map(fn ($id) => [
            'participant_id' => $id,
            'match_points'   => round((float) ($rows[$id]->match_points ?? 0), 2),
            'game_points'    => round((float) ($rows[$id]->game_points ?? 0), 2),
            'wins'           => (int) ($rows[$id]->wins ?? 0),
            'losses'         => (int) ($rows[$id]->losses ?? 0),
            'draws'          => (int) ($rows[$id]->draws ?? 0),
        ])->sortByDesc(fn ($r) => [$r['match_points'], $r['game_points']])->values();

        foreach ($sorted as $rank => $data) {
            TournamentStanding::updateOrInsert(
                ['phase_id' => $phase->id, 'participant_id' => $data['participant_id']],
                array_merge($data, ['rank' => $rank + 1])
            );
        }
    }

    // ── Bracket: Single Elimination ──────────────────────────────────────────

    private function buildSingleElimBracket(TournamentPhase $phase): void
    {
        $participants = $this->seededParticipants($phase->tournament);
        $n            = $participants->count();
        $slots        = $this->nextPowerOfTwo($n);
        $byes         = $slots - $n;

        // Build all rounds upfront with placeholder matches
        $roundCount = (int) log($slots, 2);
        $rounds     = [];
        for ($r = 1; $r <= $roundCount; $r++) {
            $rounds[$r] = TournamentRound::create([
                'phase_id'     => $phase->id,
                'round_number' => $r,
                'status'       => $r === 1 ? TournamentRound::STATUS_ONGOING : TournamentRound::STATUS_PENDING,
            ]);
        }

        // Generate final, semis, quarters ... from last round backward, then link next_match_id
        $matchesByRound = [];
        for ($r = $roundCount; $r >= 1; $r--) {
            $matchCount = $slots / (2 ** $r);
            $matchesByRound[$r] = [];
            for ($pos = 1; $pos <= $matchCount; $pos++) {
                $nextMatchId = null;
                if ($r < $roundCount) {
                    $nextPos     = (int) ceil($pos / 2);
                    $nextMatchId = $matchesByRound[$r + 1][$nextPos - 1]->id ?? null;
                }
                $matchesByRound[$r][] = TournamentMatch::create([
                    'round_id'         => $rounds[$r]->id,
                    'bracket_position' => $pos,
                    'bracket_side'     => TournamentMatch::SIDE_WINNERS,
                    'next_match_id'    => $nextMatchId,
                    'status'           => TournamentMatch::STATUS_PENDING,
                ]);
            }
        }

        // Seed participants into round 1
        $seededSlots = $this->seededBracketSlots($slots);
        $round1Matches = $matchesByRound[1];

        foreach ($round1Matches as $idx => $match) {
            $slot1 = $seededSlots[$idx * 2] ?? null;
            $slot2 = $seededSlots[$idx * 2 + 1] ?? null;

            $p1 = $slot1 !== null ? ($participants[$slot1 - 1] ?? null) : null;
            $p2 = $slot2 !== null ? ($participants[$slot2 - 1] ?? null) : null;

            if ($p1) {
                TournamentMatchParticipant::create(['match_id' => $match->id, 'participant_id' => $p1->id, 'is_winner' => false]);
            }
            if ($p2) {
                TournamentMatchParticipant::create(['match_id' => $match->id, 'participant_id' => $p2->id, 'is_winner' => false]);
            }

            // Bye: only one participant — auto-advance
            if ($p1 && ! $p2) {
                $match->update(['status' => TournamentMatch::STATUS_BYE]);
                $this->advanceInBracket($match, $p1->id, null);
            } elseif (! $p1 && ! $p2) {
                $match->update(['status' => TournamentMatch::STATUS_BYE]);
            }
        }

        $rounds[1]->update(['status' => TournamentRound::STATUS_ONGOING]);
    }

    private function advanceInBracket(TournamentMatch $match, int $winnerId, ?int $loserId): void
    {
        if ($match->next_match_id) {
            TournamentMatchParticipant::firstOrCreate([
                'match_id'       => $match->next_match_id,
                'participant_id' => $winnerId,
            ], ['is_winner' => false]);
        }

        if ($loserId && $match->loser_next_match_id) {
            TournamentMatchParticipant::firstOrCreate([
                'match_id'       => $match->loser_next_match_id,
                'participant_id' => $loserId,
            ], ['is_winner' => false]);
        }
    }

    // ── Bracket: Double Elimination ──────────────────────────────────────────

    private function buildDoubleElimBracket(TournamentPhase $phase): void
    {
        // Winners bracket (same as single elim)
        $this->buildSingleElimBracket($phase);

        $participants = $this->seededParticipants($phase->tournament);
        $slots        = $this->nextPowerOfTwo($participants->count());
        $wRounds      = (int) log($slots, 2);

        // Losers bracket: 2*(wRounds-1) rounds
        $lRoundCount  = 2 * ($wRounds - 1);
        $lRoundOffset = $wRounds + 1; // losers rounds start after winners

        $lRounds = [];
        for ($r = 1; $r <= $lRoundCount; $r++) {
            $lRounds[$r] = TournamentRound::create([
                'phase_id'     => $phase->id,
                'round_number' => $lRoundOffset + $r - 1,
                'status'       => TournamentRound::STATUS_PENDING,
            ]);
        }

        // Grand Finals round
        TournamentRound::create([
            'phase_id'     => $phase->id,
            'round_number' => $lRoundOffset + $lRoundCount,
            'status'       => TournamentRound::STATUS_PENDING,
        ]);

        // Wire up loser_next_match_id on winners bracket round 1 matches to losers bracket
        // This is a simplified wiring — full double elim wiring is complex
        // For now: losers from WR rounds drop into appropriate LR rounds
        // Actual complex wiring done when results are recorded
    }

    // ── Round Robin ──────────────────────────────────────────────────────────

    private function buildRoundRobin(TournamentPhase $phase): void
    {
        $participants = $this->seededParticipants($phase->tournament);
        $groupCount   = max(1, $phase->group_count ?? 1);

        $groups = $this->assignToGroups($participants, $groupCount);

        foreach ($groups as $groupName => $groupParticipants) {
            $this->buildRoundRobinGroup($phase, $groupName, $groupParticipants);
        }

        // Assign tables and slots to all generated rounds
        foreach ($phase->rounds as $round) {
            $this->assignTablesAndSlots($round);
        }
    }

    private function buildRoundRobinGroup(TournamentPhase $phase, string $groupName, Collection $participants): void
    {
        $list  = $participants->values();
        $n     = $list->count();
        $dummy = $n % 2 !== 0 ? null : false; // add dummy for odd number

        if ($n % 2 !== 0) {
            $list->push(null); // bye slot
            $n++;
        }

        $rounds = $n - 1;
        $half   = $n / 2;

        for ($r = 1; $r <= $rounds; $r++) {
            $round = TournamentRound::firstOrCreate(
                ['phase_id' => $phase->id, 'round_number' => $r],
                ['status' => $r === 1 ? TournamentRound::STATUS_ONGOING : TournamentRound::STATUS_PENDING]
            );

            for ($i = 0; $i < $half; $i++) {
                $home = $list[$i];
                $away = $list[$n - 1 - $i];

                if ($home === null || $away === null) {
                    continue; // bye
                }

                $match = TournamentMatch::create([
                    'round_id'   => $round->id,
                    'group_name' => $groupName,
                    'status'     => TournamentMatch::STATUS_PENDING,
                ]);

                TournamentMatchParticipant::create(['match_id' => $match->id, 'participant_id' => $home->id, 'is_winner' => false]);
                TournamentMatchParticipant::create(['match_id' => $match->id, 'participant_id' => $away->id, 'is_winner' => false]);
            }

            // Rotate: fix first element, rotate rest
            $fixed  = $list->shift();
            $rotated = $list->push($list->shift());
            $list->prepend($fixed);
        }
    }

    // ── Swiss ────────────────────────────────────────────────────────────────

    private function generateFirstSwissRound(TournamentPhase $phase): void
    {
        $participants = $this->seededParticipants($phase->tournament);
        $round = TournamentRound::create([
            'phase_id'     => $phase->id,
            'round_number' => 1,
            'status'       => TournamentRound::STATUS_ONGOING,
        ]);

        if ($phase->format === TournamentPhase::FORMAT_POD_SWISS) {
            $this->createPodMatches($phase, $round, $participants);
        } else {
            $this->createSwissMatches($phase, $round, $participants);
        }

        $this->assignTablesAndSlots($round);
        $this->sendRoundNotifications($round);
    }

    private function generateSwissRound(TournamentPhase $phase): TournamentRound
    {
        $current = $phase->rounds()->where('status', TournamentRound::STATUS_ONGOING)->first();
        if ($current) {
            $current->update(['status' => TournamentRound::STATUS_COMPLETED]);
        }

        $nextNumber = $phase->completedRoundsCount() + 1;
        $round = TournamentRound::create([
            'phase_id'     => $phase->id,
            'round_number' => $nextNumber,
            'status'       => TournamentRound::STATUS_ONGOING,
        ]);

        $participants = $this->standingsOrderedParticipants($phase);

        if ($phase->format === TournamentPhase::FORMAT_POD_SWISS) {
            $this->createPodMatches($phase, $round, $participants);
        } else {
            $this->createSwissMatches($phase, $round, $participants);
        }

        $this->assignTablesAndSlots($round);
        $this->sendRoundNotifications($round);

        return $round;
    }

    private function createSwissMatches(TournamentPhase $phase, TournamentRound $round, Collection $participants): void
    {
        $paired      = collect();
        $unpaired    = $participants->values();
        $playedPairs = $this->getPlayedPairs($phase);
        $rematches   = [];

        while ($unpaired->count() >= 2) {
            $p1   = $unpaired->shift();
            $best = null;

            foreach ($unpaired as $idx => $p2) {
                $key = $this->pairKey($p1->id, $p2->id);
                if (! isset($playedPairs[$key])) {
                    $best = ['idx' => $idx, 'p' => $p2, 'rematch' => false];
                    break;
                }
            }

            // If all potential opponents are rematches, take the first available
            if (! $best) {
                $best = ['idx' => 0, 'p' => $unpaired->first(), 'rematch' => true];
            }

            $unpaired->forget($best['idx']);
            $paired->push([$p1, $best['p'], $best['rematch']]);
        }

        // Bye for leftover
        $bye = $unpaired->first();

        foreach ($paired as [$p1, $p2, $isRematch]) {
            $match = TournamentMatch::create([
                'round_id'   => $round->id,
                'status'     => TournamentMatch::STATUS_PENDING,
                'group_name' => $isRematch ? 'rematch' : null,
            ]);
            TournamentMatchParticipant::create(['match_id' => $match->id, 'participant_id' => $p1->id, 'is_winner' => false]);
            TournamentMatchParticipant::create(['match_id' => $match->id, 'participant_id' => $p2->id, 'is_winner' => false]);
        }

        if ($bye) {
            $byeMatch = TournamentMatch::create([
                'round_id' => $round->id,
                'status'   => TournamentMatch::STATUS_BYE,
            ]);
            TournamentMatchParticipant::create([
                'match_id'       => $byeMatch->id,
                'participant_id' => $bye->id,
                'is_winner'      => true,
                'match_points'   => $phase->match_points_win,
            ]);
        }
    }

    private function createPodMatches(TournamentPhase $phase, TournamentRound $round, Collection $participants): void
    {
        $max   = $phase->players_per_match ?? 4;
        $min   = $phase->min_players_per_match ?? 2;
        $list  = $participants->values();
        $sizes = $this->tableSizes($list->count(), $max, $min);

        $offset = 0;
        foreach ($sizes as $size) {
            $pod = $list->slice($offset, $size);
            $offset += $size;

            if ($pod->count() < 2) {
                continue;
            }

            $match = TournamentMatch::create([
                'round_id' => $round->id,
                'status'   => TournamentMatch::STATUS_PENDING,
            ]);

            foreach ($pod as $participant) {
                TournamentMatchParticipant::create([
                    'match_id'       => $match->id,
                    'participant_id' => $participant->id,
                    'is_winner'      => false,
                ]);
            }
        }
    }

    /**
     * Points for a given placement (1-indexed) using settings['placement_points']
     * or sensible defaults based on match_points_win/loss and player count.
     */
    public function placementPoints(TournamentPhase $phase, int $placement): int
    {
        $configured = $phase->settings['placement_points'] ?? null;
        if ($configured && isset($configured[$placement - 1])) {
            return (int) $configured[$placement - 1];
        }

        // Default: linear scale from match_points_win down to match_points_loss
        $n    = $phase->players_per_match ?? 2;
        $win  = $phase->match_points_win;
        $loss = $phase->match_points_loss;

        if ($n <= 1 || $placement > $n) {
            return $loss;
        }
        if ($n === 2) {
            return $placement === 1 ? $win : $loss;
        }

        // Distribute evenly: 1st = win, last = loss, intermediate = interpolated
        $range = $win - $loss;
        $step  = $range / ($n - 1);
        return (int) round($win - ($placement - 1) * $step);
    }

    /**
     * Default placement_points array for a given phase (for form pre-population).
     */
    public static function defaultPlacementPoints(int $playersPerMatch, int $win, int $loss): array
    {
        $points = [];
        for ($p = 1; $p <= $playersPerMatch; $p++) {
            $range  = $win - $loss;
            $step   = $playersPerMatch > 1 ? $range / ($playersPerMatch - 1) : 0;
            $points[] = (int) round($win - ($p - 1) * $step);
        }
        return $points;
    }

    // ── Table & slot assignment ───────────────────────────────────────────────

    /**
     * Randomly assign table numbers and time slots to all matches in a round.
     * Matches are shuffled first, then assigned tables 1..N in each slot.
     * If tables_available is null, all matches get slot 1 with sequential tables.
     */
    public function assignTablesAndSlots(TournamentRound $round): void
    {
        $tablesAvailable = $round->phase->tables_available;
        $matches = $round->matches()->where('status', '!=', TournamentMatch::STATUS_BYE)->get();

        if ($matches->isEmpty()) {
            return;
        }

        // Shuffle for random table assignment
        $shuffled = $matches->shuffle()->values();

        if (! $tablesAvailable || $tablesAvailable >= $shuffled->count()) {
            // Enough tables: all in slot 1, tables 1..N
            foreach ($shuffled as $idx => $match) {
                $match->update(['table_number' => $idx + 1, 'time_slot' => 1]);
            }
            return;
        }

        // Not enough tables: distribute into slots
        $slot      = 1;
        $tableInSlot = 1;

        foreach ($shuffled as $match) {
            $match->update(['table_number' => $tableInSlot, 'time_slot' => $slot]);
            $tableInSlot++;
            if ($tableInSlot > $tablesAvailable) {
                $tableInSlot = 1;
                $slot++;
            }
        }
    }

    /**
     * Save slot start times for a round (admin-provided).
     * $times = ['1' => '14:00', '2' => '15:30']
     */
    public function saveSlotTimes(TournamentRound $round, array $times): void
    {
        $round->update(['slot_times' => $times]);
    }

    /**
     * Reassign table numbers randomly within each existing slot.
     */
    public function reshuffleTableNumbers(TournamentRound $round): void
    {
        $bySlot = $round->matches()
            ->where('status', '!=', TournamentMatch::STATUS_BYE)
            ->get()
            ->groupBy('time_slot');

        foreach ($bySlot as $slot => $matches) {
            $tables = $matches->count();
            $order  = collect(range(1, $tables))->shuffle()->values();
            foreach ($matches as $idx => $match) {
                $match->update(['table_number' => $order[$idx]]);
            }
        }
    }

    /**
     * Distribute $n players into tables, respecting max and min players per table.
     * Returns array of table sizes, e.g. [4, 4, 4, 3, 3] for 18 players at max 4, min 3.
     *
     * Algorithm:
     * 1. Start with ceil(n/max) tables (fewest tables that fit everyone under max).
     * 2. While any table would be below min, reduce the table count.
     * 3. Distribute evenly: some tables get floor(n/tables), some get floor+1.
     */
    public function tableSizes(int $n, int $max, int $min): array
    {
        if ($n <= 0) {
            return [];
        }

        $tables = (int) ceil($n / $max);

        // Reduce table count until all tables meet minimum size
        while ($tables > 1 && (int) floor($n / $tables) < $min) {
            $tables--;
        }

        $base   = (int) floor($n / $tables);
        $extras = $n % $tables; // this many tables get base+1

        $sizes = [];
        for ($i = 0; $i < $tables; $i++) {
            $sizes[] = $i < $extras ? $base + 1 : $base;
        }

        return $sizes;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function seededParticipants(Tournament $tournament): Collection
    {
        return $tournament->activeParticipants()
            ->with(['member', 'team'])
            ->get()
            ->sortBy(fn ($p) => $p->seed ?? PHP_INT_MAX)
            ->values();
    }

    private function standingsOrderedParticipants(TournamentPhase $phase): Collection
    {
        return TournamentStanding::where('phase_id', $phase->id)
            ->orderByDesc('match_points')
            ->orderByDesc('game_points')
            ->with('participant.member')
            ->get()
            ->pluck('participant')
            ->filter()
            ->values();
    }

    private function assignToGroups(Collection $participants, int $groupCount): array
    {
        $groups = [];
        $letters = range('A', 'Z');

        for ($g = 0; $g < $groupCount; $g++) {
            $groups['Grupp ' . $letters[$g]] = collect();
        }

        // FIFA pot system: distribute seeds evenly across groups
        $seeded    = $participants->filter(fn ($p) => $p->seed !== null)->sortBy('seed')->values();
        $unseeded  = $participants->filter(fn ($p) => $p->seed === null)->shuffle()->values();
        $all       = $seeded->concat($unseeded);

        foreach ($all as $idx => $p) {
            $groupKey = 'Grupp ' . $letters[$idx % $groupCount];
            $groups[$groupKey]->push($p);
        }

        return $groups;
    }

    private function getPlayedPairs(TournamentPhase $phase): array
    {
        $pairs = [];
        $matches = $phase->matches()
            ->with('participants')
            ->where('status', TournamentMatch::STATUS_COMPLETED)
            ->get();

        foreach ($matches as $match) {
            $ids = $match->participants->pluck('participant_id')->toArray();
            if (count($ids) === 2) {
                $pairs[$this->pairKey($ids[0], $ids[1])] = true;
            }
        }

        return $pairs;
    }

    private function getPlayedSets(TournamentPhase $phase): array
    {
        // Similar to pairs but for N-player pods
        return [];
    }

    private function pairKey(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }

    private function nextPowerOfTwo(int $n): int
    {
        if ($n <= 1) {
            return 1;
        }
        return 2 ** (int) ceil(log($n, 2));
    }

    /**
     * Standard seeded bracket slot ordering.
     * For 8-player bracket: [1,8,5,4,3,6,7,2] — top seeds are separated.
     */
    private function seededBracketSlots(int $size): array
    {
        if ($size === 1) {
            return [1];
        }
        $half   = $size / 2;
        $upper  = $this->seededBracketSlots($half);
        $result = [];
        foreach ($upper as $u) {
            $result[] = $u;
            $result[] = $size + 1 - $u;
        }
        return $result;
    }
}
