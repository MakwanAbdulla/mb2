<?php

namespace App\Observers;

use App\Models\Gamification\XpLog;
use App\Models\User;
use App\Services\GamificationService;

class UserObserver
{
    public function updated(User $user): void
    {
        if (! $user->wasChanged('avatar_path') || ! $user->avatar_path) {
            return;
        }

        $member = $user->member;
        if (! $member) {
            return;
        }

        $alreadyAwarded = XpLog::where('member_id', $member->id)
            ->where('source_type', XpLog::SOURCE_PROFILE_AVATAR)
            ->exists();

        if (! $alreadyAwarded) {
            app(GamificationService::class)->award(
                $member,
                XpLog::SOURCE_PROFILE_AVATAR,
                GamificationService::XP_VALUES[XpLog::SOURCE_PROFILE_AVATAR],
            );
        }
    }
}
