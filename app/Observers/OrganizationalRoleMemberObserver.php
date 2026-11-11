<?php

namespace App\Observers;

use App\Models\Organization\OrganizationalRoleMember;
use App\Models\Organization\OrganizationalRolePeriod;
use App\Services\GoogleWorkspaceService;

class OrganizationalRoleMemberObserver
{
    public function __construct(private GoogleWorkspaceService $workspace) {}

    public function created(OrganizationalRoleMember $roleMember): void
    {
        if (! $this->workspace->isConfigured()) {
            return;
        }

        $member = $roleMember->member;
        if (! $member) {
            return; // external (non-member) entry
        }

        $period = $roleMember->period()->with('organizationalRole')->first();
        if (! $period) {
            return;
        }

        $statusOk = in_array($period->status, [
            OrganizationalRolePeriod::STATUS_FORMING,
            OrganizationalRolePeriod::STATUS_ACTIVE,
        ], true);

        if (! $statusOk || ! $period->organizationalRole->provision_google_workspace) {
            return;
        }

        $this->workspace->provision($member);
    }

    public function deleted(OrganizationalRoleMember $roleMember): void
    {
        if (! $this->workspace->isConfigured()) {
            return;
        }

        $member = $roleMember->member;
        if (! $member || ! $member->workspaceAccount) {
            return;
        }

        // Keep the account if the member is still active in any other provisioning role
        $stillActive = OrganizationalRoleMember::where('member_id', $member->id)
            ->where('id', '!=', $roleMember->id)
            ->whereHas('period', fn ($q) => $q->whereIn('status', [
                OrganizationalRolePeriod::STATUS_FORMING,
                OrganizationalRolePeriod::STATUS_ACTIVE,
            ])->whereHas('organizationalRole', fn ($q2) => $q2->where('provision_google_workspace', true)))
            ->exists();

        if (! $stillActive) {
            $this->workspace->deprovision($member);
        }
    }
}
