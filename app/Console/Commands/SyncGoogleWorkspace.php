<?php

namespace App\Console\Commands;

use App\Models\Membership\Member;
use App\Models\Organization\OrganizationalRoleMember;
use App\Models\Organization\OrganizationalRolePeriod;
use App\Models\WorkspaceAccount;
use App\Services\GoogleWorkspaceService;
use Illuminate\Console\Command;

class SyncGoogleWorkspace extends Command
{
    protected $signature = 'workspace:sync
                            {--dry-run : Visa vad som skulle göras utan att anropa API}';

    protected $description = 'Synkronisera Google Workspace-konton mot aktiva organisationsroller';

    public function handle(GoogleWorkspaceService $workspace): int
    {
        if (! $workspace->isConfigured()) {
            $this->error('Google Workspace är inte konfigurerat (GOOGLE_WORKSPACE_ENABLED=false eller saknade env-variabler).');
            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — inga ändringar görs.');
        }

        // Members that SHOULD have accounts: active in a provisioning role
        $activeRoleMembers = OrganizationalRoleMember::query()
            ->whereNotNull('member_id')
            ->whereHas('period', fn ($q) => $q
                ->whereIn('status', [OrganizationalRolePeriod::STATUS_FORMING, OrganizationalRolePeriod::STATUS_ACTIVE])
                ->whereHas('organizationalRole', fn ($q2) => $q2->where('provision_google_workspace', true))
            )
            ->with('member')
            ->get()
            ->unique('member_id');

        $activeMemberIds = $activeRoleMembers->pluck('member_id')->unique();

        $this->info("Aktiva rollmedlemmar med workspace-provisioning: {$activeMemberIds->count()}");

        $created  = 0;
        $restored = 0;
        $skipped  = 0;

        foreach ($activeRoleMembers as $rm) {
            $member = $rm->member;
            if (! $member) {
                continue;
            }

            $wsEmail = $member->workspaceAccount?->email;
            if ($wsEmail) {
                $this->line("  <fg=yellow>Befintlig:</> {$member->full_name} ({$wsEmail})");
                $skipped++;
                continue;
            }

            $email = $workspace->resolveEmail($member);
            $this->line("  <fg=green>Skapar:</> {$member->full_name} → {$email}");

            if (! $dryRun) {
                $workspace->createAccount($member);
                $created++;
            }
        }

        // Members that SHOULD NOT have accounts but do: suspend them
        $membersToSuspend = Member::whereHas('workspaceAccount')
            ->whereNotIn('id', $activeMemberIds)
            ->with('workspaceAccount')
            ->get();

        $suspended = 0;

        foreach ($membersToSuspend as $member) {
            $this->line("  <fg=red>Avaktiverar:</> {$member->full_name} ({$member->workspaceAccount->email})");

            if (! $dryRun) {
                $workspace->deprovision($member);
                $suspended++;
            }
        }

        $this->newLine();
        $this->info("Klart. Skapade: {$created}, befintliga: {$skipped}, avaktiverade: {$suspended}.");

        if ($dryRun) {
            $this->warn('DRY RUN — inga ändringar gjordes.');
        }

        return self::SUCCESS;
    }
}
