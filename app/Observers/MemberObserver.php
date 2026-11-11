<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Gamification\XpLog;
use App\Models\Membership\Member;
use App\Services\GamificationService;

class MemberObserver
{
    private array $pendingNameChanges = [];

    public function updating(Member $member): void
    {
        if ($member->isDirty('first_name') || $member->isDirty('last_name')) {
            $wsEmail = $member->workspaceAccount?->email;
            if ($wsEmail) {
                $this->pendingNameChanges[$member->id] = [
                    'old_name'        => $member->getOriginal('first_name') . ' ' . $member->getOriginal('last_name'),
                    'workspace_email' => $wsEmail,
                ];
            }
        }
    }

    public function updated(Member $member): void
    {
        if (! isset($this->pendingNameChanges[$member->id])) {
            return;
        }

        $change  = $this->pendingNameChanges[$member->id];
        $newName = $member->first_name . ' ' . $member->last_name;

        AuditLog::record(
            action:  'member_name_changed',
            area:    'workspace',
            subject: $change['old_name'] . ' → ' . $newName,
            changes: [
                'old_name'        => $change['old_name'],
                'new_name'        => $newName,
                'workspace_email' => $change['workspace_email'],
            ],
        );

        unset($this->pendingNameChanges[$member->id]);

        $this->checkProfileXp($member);
    }

    public function created(Member $member): void
    {
        $this->checkProfileXp($member);
    }

    private function checkProfileXp(Member $member): void
    {
        $gamification = app(GamificationService::class);

        // Avatar uploaded (one-time bonus via User model — handled in UserObserver)
        // Profile complete: date_of_birth + gender + address all filled
        if ($member->date_of_birth && $member->gender && $member->address) {
            $alreadyAwarded = XpLog::where('member_id', $member->id)
                ->where('source_type', XpLog::SOURCE_PROFILE_COMPLETE)
                ->exists();

            if (! $alreadyAwarded) {
                $gamification->award(
                    $member,
                    XpLog::SOURCE_PROFILE_COMPLETE,
                    GamificationService::XP_VALUES[XpLog::SOURCE_PROFILE_COMPLETE],
                );
            }
        }
    }
}
