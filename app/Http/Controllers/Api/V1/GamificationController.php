<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Membership\Member;
use App\Services\GamificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GamificationController extends Controller
{
    public function __construct(private GamificationService $gamification) {}

    /**
     * GET /api/v1/gamification/leaderboard
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $request->validate([
            'year'  => 'nullable|integer|min:2020|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $year  = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);

        $board = $this->gamification->leaderboard($year, $month, 20);

        return response()->json([
            'year'  => $year,
            'month' => $month,
            'data'  => $board->map(fn ($entry, $i) => [
                'rank'       => $i + 1,
                'member_id'  => $entry['member_id'],
                'name'       => $entry['name'],
                'monthly_xp' => $entry['monthly_xp'],
                'level'      => $entry['level']['level'],
                'level_name' => $entry['level']['name'],
            ])->values(),
        ]);
    }

    /**
     * GET /api/v1/gamification/me
     */
    public function me(Request $request): JsonResponse
    {
        $member = $request->user()?->member;
        if (! $member) {
            return response()->json(['error' => 'No member profile.'], 404);
        }

        $totalXp   = $this->gamification->totalXp($member);
        $level     = $this->gamification->level($totalXp);
        $monthlyXp = $this->gamification->monthlyXp($member);
        $badges    = $member->badges()->orderByDesc('awarded_at')->get()
            ->map(fn ($b) => [
                'key'        => $b->badge_key,
                'label'      => GamificationService::badgeLabel($b->badge_key),
                'awarded_at' => $b->awarded_at->toIso8601String(),
            ]);

        return response()->json([
            'total_xp'    => $totalXp,
            'monthly_xp'  => $monthlyXp,
            'level'       => $level,
            'badges'      => $badges,
            'kudos_remaining' => GamificationService::KUDOS_LIMIT_PER_MONTH - $this->gamification->kudosGivenThisMonth($member),
        ]);
    }

    /**
     * POST /api/v1/gamification/kudos/{member}
     */
    public function giveKudos(Request $request, Member $member): JsonResponse
    {
        $giver = $request->user()?->member;
        if (! $giver) {
            return response()->json(['error' => 'No member profile.'], 403);
        }

        $request->validate([
            'note' => 'nullable|string|max:100',
        ]);

        if (! $this->gamification->canGiveKudos($giver, $member)) {
            return response()->json([
                'error' => $giver->id === $member->id
                    ? 'Du kan inte ge kudos till dig själv.'
                    : 'Du har nått din kudos-gräns för månaden.',
            ], 422);
        }

        $this->gamification->giveKudos($giver, $member, $request->input('note'));

        return response()->json([
            'message'         => 'Kudos skickat!',
            'xp_awarded'      => GamificationService::KUDOS_XP,
            'kudos_remaining' => GamificationService::KUDOS_LIMIT_PER_MONTH - $this->gamification->kudosGivenThisMonth($giver),
        ]);
    }
}
