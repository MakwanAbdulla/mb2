<?php

namespace App\Services;

use App\Models\Gamification\GamificationTask;
use App\Models\Gamification\MemberBadge;
use App\Models\Gamification\TaskCompletion;
use App\Models\Gamification\XpLog;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GamificationService
{
    public const int    KUDOS_LIMIT_PER_MONTH = 5;
    public const int    KUDOS_XP              = 15;

    public const array LEVELS = [
        1  => ['name' => 'Nybörjare',  'xp_min' => 0],
        2  => ['name' => 'Spjutspets', 'xp_min' => 100],
        3  => ['name' => 'Veteran',    'xp_min' => 300],
        4  => ['name' => 'Strateg',    'xp_min' => 700],
        5  => ['name' => 'Mästare',    'xp_min' => 1500],
        6  => ['name' => 'Elite',      'xp_min' => 3000],
        7  => ['name' => 'Legend',     'xp_min' => 6000],
        8  => ['name' => 'Odödlig',    'xp_min' => 10000],
        9  => ['name' => 'Gudom',      'xp_min' => 20000],
        10 => ['name' => 'Armagedon',  'xp_min' => 40000],
    ];

    public const array XP_VALUES = [
        XpLog::SOURCE_MEMBERSHIP_RENEWAL => 50,
        XpLog::SOURCE_EVENT_CHECKIN      => 25,
        XpLog::SOURCE_EVENT_REGISTRATION => 10,
        XpLog::SOURCE_PROFILE_AVATAR     => 30,
        XpLog::SOURCE_PROFILE_COMPLETE   => 20,
        XpLog::SOURCE_PEER_KUDOS         => self::KUDOS_XP,
        XpLog::SOURCE_BOOKING            => 10,
    ];

    public function award(
        Member $member,
        string $sourceType,
        int $points,
        ?Member $giver = null,
        ?int $sourceId = null,
        ?string $note = null,
    ): XpLog {
        $log = XpLog::create([
            'member_id'        => $member->id,
            'giver_member_id'  => $giver?->id,
            'source_type'      => $sourceType,
            'source_id'        => $sourceId,
            'points'           => $points,
            'note'             => $note,
            'created_at'       => now(),
        ]);

        $this->checkAndAwardBadges($member);

        return $log;
    }

    public function totalXp(Member $member): int
    {
        return (int) XpLog::where('member_id', $member->id)->sum('points');
    }

    public function monthlyXp(Member $member, ?int $year = null, ?int $month = null): int
    {
        $year  ??= now()->year;
        $month ??= now()->month;

        return (int) XpLog::where('member_id', $member->id)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->sum('points');
    }

    public function level(int $totalXp): array
    {
        $current = 1;
        foreach (self::LEVELS as $num => $def) {
            if ($totalXp >= $def['xp_min']) {
                $current = $num;
            }
        }

        $def     = self::LEVELS[$current];
        $nextNum = $current < 10 ? $current + 1 : null;
        $nextMin = $nextNum ? self::LEVELS[$nextNum]['xp_min'] : null;

        $progressPct = $nextMin
            ? (int) min(100, round(($totalXp - $def['xp_min']) / ($nextMin - $def['xp_min']) * 100))
            : 100;

        return [
            'level'        => $current,
            'name'         => $def['name'],
            'xp_min'       => $def['xp_min'],
            'xp_next'      => $nextMin,
            'progress_pct' => $progressPct,
            'total_xp'     => $totalXp,
        ];
    }

    public function leaderboard(int $year, int $month, int $limit = 20): Collection
    {
        return XpLog::select('member_id', DB::raw('SUM(points) as monthly_xp'))
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->groupBy('member_id')
            ->orderByDesc('monthly_xp')
            ->limit($limit)
            ->with('member:id,first_name,last_name')
            ->get()
            ->map(fn ($row) => [
                'member_id'   => $row->member_id,
                'name'        => $row->member?->full_name ?? '—',
                'monthly_xp'  => (int) $row->monthly_xp,
                'level'       => $this->level($this->totalXp($row->member)),
            ]);
    }

    public function canGiveKudos(Member $giver, Member $recipient): bool
    {
        if ($giver->id === $recipient->id) {
            return false;
        }

        $given = XpLog::where('giver_member_id', $giver->id)
            ->where('source_type', XpLog::SOURCE_PEER_KUDOS)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        return $given < self::KUDOS_LIMIT_PER_MONTH;
    }

    public function kudosGivenThisMonth(Member $giver): int
    {
        return XpLog::where('giver_member_id', $giver->id)
            ->where('source_type', XpLog::SOURCE_PEER_KUDOS)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();
    }

    public function giveKudos(Member $giver, Member $recipient, ?string $note = null): XpLog
    {
        if (! $this->canGiveKudos($giver, $recipient)) {
            throw new \RuntimeException('Kan inte ge kudos: gränsen nådd eller ogiltigt mottagare.');
        }

        return $this->award($recipient, XpLog::SOURCE_PEER_KUDOS, self::KUDOS_XP, $giver, null, $note);
    }

    public function checkAndAwardBadges(Member $member): array
    {
        $existing  = MemberBadge::where('member_id', $member->id)->pluck('badge_key')->toArray();
        $awarded   = [];
        $totalXp   = $this->totalXp($member);
        $level     = $this->level($totalXp);

        $checks = [
            'welcome'        => fn () => true,
            'level_5'        => fn () => $level['level'] >= 5,
            'level_10'       => fn () => $level['level'] >= 10,
            'kudos_popular'  => fn () => XpLog::where('member_id', $member->id)->where('source_type', XpLog::SOURCE_PEER_KUDOS)->count() >= 10,
            'event_first'    => fn () => XpLog::where('member_id', $member->id)->where('source_type', XpLog::SOURCE_EVENT_CHECKIN)->exists(),
            'event_10'       => fn () => XpLog::where('member_id', $member->id)->where('source_type', XpLog::SOURCE_EVENT_CHECKIN)->count() >= 10,
            'event_25'       => fn () => XpLog::where('member_id', $member->id)->where('source_type', XpLog::SOURCE_EVENT_CHECKIN)->count() >= 25,
            'event_50'       => fn () => XpLog::where('member_id', $member->id)->where('source_type', XpLog::SOURCE_EVENT_CHECKIN)->count() >= 50,
            'member_1y'      => fn () => $this->membershipYears($member) >= 1,
            'member_3y'      => fn () => $this->membershipYears($member) >= 3,
            'member_5y'      => fn () => $this->membershipYears($member) >= 5,
            'member_10y'     => fn () => $this->membershipYears($member) >= 10,
        ];

        foreach ($checks as $key => $condition) {
            if (! in_array($key, $existing) && $condition()) {
                MemberBadge::create([
                    'member_id'  => $member->id,
                    'badge_key'  => $key,
                    'awarded_at' => now(),
                ]);
                $awarded[] = $key;
            }
        }

        return $awarded;
    }

    private function membershipYears(Member $member): int
    {
        $first = MembershipPeriod::where('member_id', $member->id)
            ->where('status', MembershipPeriod::STATUS_ACTIVE)
            ->min('year');

        return $first ? now()->year - (int) $first : 0;
    }

    public static function badgeLabel(string $key): string
    {
        return match ($key) {
            'welcome'       => 'Välkommen',
            'level_5'       => 'Mästare',
            'level_10'      => 'Armagedon',
            'kudos_popular' => 'Folkkär',
            'event_first'   => 'Evenemangsdebutant',
            'event_10'      => 'Evenemangsvan',
            'event_25'      => 'Evenemangsräv',
            'event_50'      => 'Evenemangslegend',
            'member_1y'     => '1 år som medlem',
            'member_3y'     => '3 år som medlem',
            'member_5y'     => '5 år som medlem',
            'member_10y'    => '10 år som medlem',
            default         => $key,
        };
    }

    // ── Tasks ─────────────────────────────────────────────────────────────────

    /**
     * Admin assigns task directly to a member — XP awarded immediately.
     */
    public function adminAssignTask(GamificationTask $task, Member $recipient, User $admin, ?string $note = null): TaskCompletion
    {
        $completion = TaskCompletion::create([
            'task_id'             => $task->id,
            'member_id'           => $recipient->id,
            'given_by_member_id'  => null,
            'approved_by_user_id' => $admin->id,
            'status'              => TaskCompletion::STATUS_APPROVED,
            'note'                => $note,
            'decided_at'          => now(),
        ]);

        $xpLog = $this->award($recipient, XpLog::SOURCE_TASK, $task->xp_value, null, $task->id, $task->title);
        $completion->update(['xp_log_id' => $xpLog->id]);

        return $completion;
    }

    /**
     * Member claims a claimable task (always requires admin approval).
     */
    public function memberClaimTask(GamificationTask $task, Member $claimant, ?string $note = null): TaskCompletion
    {
        if ($task->type !== GamificationTask::TYPE_CLAIMABLE) {
            throw new \RuntimeException('Task är inte av typen claimable.');
        }

        if (! $this->canCompleteTaskToday($task, $claimant)) {
            throw new \RuntimeException('Du har nått dagens gräns för denna task.');
        }

        return TaskCompletion::create([
            'task_id'            => $task->id,
            'member_id'          => $claimant->id,
            'given_by_member_id' => null,
            'status'             => TaskCompletion::STATUS_PENDING,
            'note'               => $note,
        ]);
    }

    /**
     * Member gives a peer task to another member.
     */
    public function memberGiveTask(GamificationTask $task, Member $giver, Member $recipient, ?string $note = null): TaskCompletion
    {
        if ($task->type !== GamificationTask::TYPE_PEER) {
            throw new \RuntimeException('Task är inte av typen peer.');
        }

        if ($giver->id === $recipient->id) {
            throw new \RuntimeException('Du kan inte ge en task till dig själv.');
        }

        if (! $this->canGiveTaskToday($task, $giver)) {
            throw new \RuntimeException('Du har nått dagens gräns för att ge denna task.');
        }

        if (! $this->canCompleteTaskToday($task, $recipient)) {
            throw new \RuntimeException('Mottagaren har redan fått denna task max antal gånger idag.');
        }

        $status = $task->requires_approval
            ? TaskCompletion::STATUS_PENDING
            : TaskCompletion::STATUS_APPROVED;

        $completion = TaskCompletion::create([
            'task_id'            => $task->id,
            'member_id'          => $recipient->id,
            'given_by_member_id' => $giver->id,
            'status'             => $status,
            'note'               => $note,
            'decided_at'         => $task->requires_approval ? null : now(),
        ]);

        if ($status === TaskCompletion::STATUS_APPROVED) {
            $xpLog = $this->award($recipient, XpLog::SOURCE_TASK, $task->xp_value, $giver, $task->id, $task->title);
            $completion->update(['xp_log_id' => $xpLog->id]);
        }

        return $completion;
    }

    /**
     * Admin approves a pending completion.
     */
    public function approveCompletion(TaskCompletion $completion, User $admin): void
    {
        if ($completion->status !== TaskCompletion::STATUS_PENDING) {
            return;
        }

        $xpLog = $this->award(
            $completion->member,
            XpLog::SOURCE_TASK,
            $completion->task->xp_value,
            $completion->givenBy,
            $completion->task_id,
            $completion->task->title,
        );

        $completion->update([
            'status'              => TaskCompletion::STATUS_APPROVED,
            'approved_by_user_id' => $admin->id,
            'xp_log_id'           => $xpLog->id,
            'decided_at'          => now(),
        ]);
    }

    /**
     * Admin rejects a pending completion.
     */
    public function rejectCompletion(TaskCompletion $completion, User $admin, ?string $note = null): void
    {
        $completion->update([
            'status'              => TaskCompletion::STATUS_REJECTED,
            'approved_by_user_id' => $admin->id,
            'note'                => $note ?? $completion->note,
            'decided_at'          => now(),
        ]);
    }

    public function canCompleteTaskToday(GamificationTask $task, Member $member): bool
    {
        $count = TaskCompletion::where('task_id', $task->id)
            ->where('member_id', $member->id)
            ->where('status', '!=', TaskCompletion::STATUS_REJECTED)
            ->whereDate('created_at', today())
            ->count();

        return $count < $task->daily_completions_per_member;
    }

    public function canGiveTaskToday(GamificationTask $task, Member $giver): bool
    {
        $count = TaskCompletion::where('task_id', $task->id)
            ->where('given_by_member_id', $giver->id)
            ->whereDate('created_at', today())
            ->count();

        return $count < $task->daily_gives_per_member;
    }

    public function pendingCompletionsCount(): int
    {
        return TaskCompletion::where('status', TaskCompletion::STATUS_PENDING)->count();
    }

    public static function badgeIcon(string $key): string
    {
        return match ($key) {
            'welcome'       => 'hand-wave',
            'level_5'       => 'trophy',
            'level_10'      => 'fire',
            'kudos_popular' => 'heart-fill',
            'event_first'   => 'calendar-event',
            'event_10'      => 'calendar2-check',
            'event_25'      => 'calendar2-heart',
            'event_50'      => 'stars',
            'member_1y'     => 'patch-check',
            'member_3y'     => 'patch-check-fill',
            'member_5y'     => 'award',
            'member_10y'    => 'award-fill',
            default         => 'patch-question',
        };
    }
}
