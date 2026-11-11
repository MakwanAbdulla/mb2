<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tournament\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentController extends Controller
{
    /**
     * GET /api/v1/tournaments
     */
    public function index(): JsonResponse
    {
        $tournaments = Tournament::where('is_public', true)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->withCount('participants')
            ->latest()
            ->get()
            ->map(fn ($t) => [
                'id'                => $t->id,
                'title'             => $t->title,
                'description'       => $t->description,
                'status'            => $t->status,
                'status_label'      => $t->statusLabel(),
                'participant_type'  => $t->participant_type,
                'participant_count' => $t->participants_count,
                'is_public'         => $t->is_public,
                'created_at'        => $t->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $tournaments]);
    }

    /**
     * GET /api/v1/tournaments/{tournament}
     */
    public function show(Tournament $tournament): JsonResponse
    {
        if (! $tournament->is_public) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        $tournament->load(['phases.standings.participant.member', 'participants.member']);

        return response()->json([
            'id'          => $tournament->id,
            'title'       => $tournament->title,
            'description' => $tournament->description,
            'status'      => $tournament->status,
            'phases'      => $tournament->phases->map(fn ($p) => [
                'id'           => $p->id,
                'name'         => $p->name,
                'format'       => $p->format,
                'format_label' => $p->formatLabel(),
                'status'       => $p->status,
                'standings'    => $p->standings->sortBy('rank')->values()->map(fn ($s) => [
                    'rank'         => $s->rank,
                    'participant'  => $s->participant?->displayName(),
                    'match_points' => $s->match_points,
                    'game_points'  => $s->game_points,
                    'wins'         => $s->wins,
                    'losses'       => $s->losses,
                    'draws'        => $s->draws,
                ]),
            ]),
        ]);
    }
}
