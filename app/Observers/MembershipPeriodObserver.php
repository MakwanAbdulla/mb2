<?php

namespace App\Observers;

use App\Models\Gamification\XpLog;
use App\Models\Membership\MembershipPeriod;
use App\Models\Organization\Section;
use App\Models\Organization\SectionMembership;
use App\Services\DiscordService;
use App\Services\GamificationService;

class MembershipPeriodObserver
{
    /**
     * When a membership period is activated, add the member to all mandatory sections
     * they are not already an active member of.
     */
    public function updated(MembershipPeriod $period): void
    {
        if (! $period->wasChanged('status')) {
            return;
        }

        if ($period->status !== MembershipPeriod::STATUS_ACTIVE) {
            return;
        }

        $this->syncMandatorySections($period);
        $this->syncDiscord($period);
        $this->awardMembershipXp($period);
    }

    public function created(MembershipPeriod $period): void
    {
        if ($period->status === MembershipPeriod::STATUS_ACTIVE) {
            $this->syncMandatorySections($period);
            $this->syncDiscord($period);
            $this->awardMembershipXp($period);
        }
    }

    private function syncDiscord(MembershipPeriod $period): void
    {
        $user = $period->member?->user;
        if ($user?->discord_id) {
            app(DiscordService::class)->syncUser($user);
        }
    }

    private function awardMembershipXp(MembershipPeriod $period): void
    {
        $member = $period->member;
        if (! $member) {
            return;
        }

        $alreadyAwarded = XpLog::where('member_id', $member->id)
            ->where('source_type', XpLog::SOURCE_MEMBERSHIP_RENEWAL)
            ->where('source_id', $period->id)
            ->exists();

        if (! $alreadyAwarded) {
            app(GamificationService::class)->award(
                $member,
                XpLog::SOURCE_MEMBERSHIP_RENEWAL,
                GamificationService::XP_VALUES[XpLog::SOURCE_MEMBERSHIP_RENEWAL],
                null,
                $period->id,
            );
        }
    }

    private function syncMandatorySections(MembershipPeriod $period): void
    {
        $memberId = $period->member_id;

        Section::where('membership_type', Section::MEMBERSHIP_MANDATORY)
            ->where('is_active', true)
            ->get()
            ->each(function (Section $section) use ($memberId) {
                $exists = SectionMembership::where('section_id', $section->id)
                    ->where('member_id', $memberId)
                    ->whereNull('left_at')
                    ->exists();

                if (! $exists) {
                    SectionMembership::create([
                        'section_id' => $section->id,
                        'member_id'  => $memberId,
                        'status'     => SectionMembership::STATUS_ACTIVE,
                        'joined_at'  => now(),
                    ]);
                }
            });
    }
}
