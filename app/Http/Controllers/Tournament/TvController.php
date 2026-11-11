<?php

namespace App\Http\Controllers\Tournament;

use App\Http\Controllers\Controller;
use App\Models\Tournament\Tournament;
use App\Models\Tournament\TournamentMatch;
use App\Models\Tournament\TournamentPhase;
use App\Models\Tournament\TournamentRound;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class TvController extends Controller
{
    public function message(Tournament $tournament): Response
    {
        abort_unless($tournament->is_public || request()->has('token'), 404);

        return response()
            ->view('tournaments.tv.message', compact('tournament'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function pdfStandings(Tournament $tournament): \Illuminate\Http\Response
    {
        abort_unless($tournament->canBeManagedBy(request()->user()) || $tournament->is_public, 403);

        /** @var TournamentPhase|null $phase */
        $phase = $tournament->phases()
            ->whereIn('status', [TournamentPhase::STATUS_ONGOING, TournamentPhase::STATUS_COMPLETED])
            ->with(['standings.participant.member', 'standings.participant.team'])
            ->orderByDesc('phase_number')
            ->first();

        $standings = $phase?->standings->sortBy('rank') ?? collect();

        $pdf = Pdf::loadView('pdf.tournament.standings', compact('tournament', 'phase', 'standings'))
            ->setPaper('a4', 'portrait');

        $filename = str($tournament->title)->slug() . '-stallning.pdf';
        return $pdf->download($filename);
    }

    public function pdfBracket(Tournament $tournament): \Illuminate\Http\Response
    {
        abort_unless($tournament->canBeManagedBy(request()->user()) || $tournament->is_public, 403);

        /** @var TournamentPhase|null $phase */
        $phase = $tournament->phases()
            ->whereIn('format', ['single_elim', 'double_elim'])
            ->whereIn('status', [TournamentPhase::STATUS_ONGOING, TournamentPhase::STATUS_COMPLETED])
            ->with(['rounds.matches.participants.participant.member', 'rounds.matches.participants.participant.team'])
            ->orderByDesc('phase_number')
            ->first();

        $rounds = $phase?->rounds ?? collect();

        $pdf = Pdf::loadView('pdf.tournament.bracket', compact('tournament', 'phase', 'rounds'))
            ->setPaper('a4', 'landscape');

        $filename = str($tournament->title)->slug() . '-bracket.pdf';
        return $pdf->download($filename);
    }

    public function standings(Tournament $tournament): Response
    {
        abort_unless($tournament->is_public || request()->has('token'), 404);

        $phase = $tournament->phases()
            ->where('status', TournamentPhase::STATUS_ONGOING)
            ->with(['standings.participant.member', 'standings.participant.team'])
            ->first()
            ?? $tournament->phases()
            ->where('status', TournamentPhase::STATUS_COMPLETED)
            ->with(['standings.participant.member', 'standings.participant.team'])
            ->orderByDesc('phase_number')
            ->first();

        return response()
            ->view('tournaments.tv.standings', [
                'tournament' => $tournament,
                'phase'      => $phase,
                'standings'  => $phase?->standings->sortBy('rank') ?? collect(),
                'generatedAt' => now(),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function bracket(Tournament $tournament): Response
    {
        abort_unless($tournament->is_public || request()->has('token'), 404);

        $phase = $tournament->phases()
            ->whereIn('format', ['single_elim', 'double_elim'])
            ->whereIn('status', [TournamentPhase::STATUS_ONGOING, TournamentPhase::STATUS_COMPLETED])
            ->with(['rounds.matches.participants.participant.member', 'rounds.matches.participants.participant.team'])
            ->orderByDesc('phase_number')
            ->first();

        return response()
            ->view('tournaments.tv.bracket', [
                'tournament'  => $tournament,
                'phase'       => $phase,
                'rounds'      => $phase?->rounds ?? collect(),
                'generatedAt' => now(),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function timer(Tournament $tournament): Response
    {
        abort_unless($tournament->is_public || request()->has('token'), 404);

        /** @var TournamentPhase|null $phase */
        $phase = $tournament->phases()
            ->where('status', TournamentPhase::STATUS_ONGOING)
            ->first();

        /** @var \App\Models\Tournament\TournamentRound|null $round */
        $round = $phase?->rounds()
            ->where('status', TournamentPhase::STATUS_ONGOING)
            ->first();

        return response()
            ->view('tournaments.tv.timer', [
                'tournament'  => $tournament,
                'phase'       => $phase,
                'round'       => $round,
                'slotTimes'   => $round?->slot_times ?? [],
                'generatedAt' => now(),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function info(Tournament $tournament): Response
    {
        abort_unless($tournament->is_public || request()->has('token'), 404);

        $participants = $tournament->activeParticipants()
            ->with(['member', 'team'])
            ->orderBy('seed')
            ->orderBy('created_at')
            ->get();

        return response()
            ->view('tournaments.tv.info', [
                'tournament'   => $tournament,
                'phases'       => $tournament->phases,
                'participants' => $participants,
                'generatedAt'  => now(),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function recap(Tournament $tournament): Response
    {
        abort_unless($tournament->is_public || request()->has('token'), 404);

        $phase = $tournament->phases()
            ->whereIn('status', [TournamentPhase::STATUS_ONGOING, TournamentPhase::STATUS_COMPLETED])
            ->with(['standings.participant.member', 'standings.participant.team'])
            ->orderByDesc('phase_number')
            ->first();

        // Last completed round
        $round = $phase?->rounds()
            ->where('status', TournamentRound::STATUS_COMPLETED)
            ->with(['matches.participants.participant.member', 'matches.participants.participant.team'])
            ->orderByDesc('round_number')
            ->first();

        return response()
            ->view('tournaments.tv.recap', [
                'tournament'  => $tournament,
                'phase'       => $phase,
                'round'       => $round,
                'standings'   => $phase?->standings->sortBy('rank') ?? collect(),
                'generatedAt' => now(),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function draw(Tournament $tournament): Response
    {
        abort_unless($tournament->is_public || request()->has('token'), 404);

        $phase = $tournament->phases()
            ->where('status', TournamentPhase::STATUS_ONGOING)
            ->first();

        $round = null;
        $matchesBySlot = collect();

        if ($phase) {
            // Show ongoing round, or last completed if no pending matches
            $round = $phase->rounds()
                ->where('status', TournamentPhase::STATUS_ONGOING)
                ->with(['matches.participants.participant.member', 'matches.participants.participant.team'])
                ->first()
                ?? $phase->rounds()
                ->where('status', TournamentPhase::STATUS_COMPLETED)
                ->with(['matches.participants.participant.member', 'matches.participants.participant.team'])
                ->orderByDesc('round_number')
                ->first();

            if ($round) {
                $matchesBySlot = $round->matches
                    ->where('status', '!=', TournamentMatch::STATUS_BYE)
                    ->groupBy('time_slot')
                    ->sortKeys();
            }
        }

        return response()
            ->view('tournaments.tv.draw', [
                'tournament'    => $tournament,
                'phase'         => $phase,
                'round'         => $round,
                'matchesBySlot' => $matchesBySlot,
                'generatedAt'   => now(),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }
}
