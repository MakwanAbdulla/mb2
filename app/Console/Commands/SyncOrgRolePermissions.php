<?php

namespace App\Console\Commands;

use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\OrganizationalRoleMember;
use App\Models\Organization\OrganizationalRolePeriod;
use App\Models\Organization\SectionMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * Syncs Spatie roles to users based on their organizational role membership.
 *
 * Rules:
 *   - forming / active / reporting period → user gets the Spatie role
 *   - closed period, ended within grace period → user keeps the Spatie role
 *   - closed period, ended more than grace period ago → user loses the Spatie role
 *
 * The grace period gives ex-board members and auditors continued read access
 * while questions about the previous year can still come up.
 *
 * Run daily via the Laravel scheduler.
 */
class SyncOrgRolePermissions extends Command
{
    protected $signature   = 'org-roles:sync-permissions {--dry-run : Visa ändringar utan att tillämpa dem}';
    protected $description = 'Synkronisera Spatie-roller utifrån organisationsroller och karensperiod';

    /**
     * Map organizational role slug → Spatie role name.
     * Add more entries here as new org roles need auto-sync.
     */
    private const ROLE_MAP = [
        'styrelsen'  => 'styrelse',
        'revisorer'  => 'revisor',
    ];

    /**
     * Months of continued access after a period closes.
     * Gives outgoing board/auditors read access during audit and follow-up questions.
     */
    private const GRACE_MONTHS = 6;

    public function handle(): int
    {
        $dryRun   = $this->option('dry-run');
        $granted  = 0;
        $revoked  = 0;
        $retained = 0;

        foreach (self::ROLE_MAP as $orgSlug => $spatieRoleName) {
            $this->line("Bearbetar '{$orgSlug}' → '{$spatieRoleName}'…");

            $spatieRole = Role::where('name', $spatieRoleName)->first();

            if (! $spatieRole) {
                $this->warn("  Spatie-rollen '{$spatieRoleName}' saknas — kör php artisan permissions:add-missing.");
                continue;
            }

            $orgRole = OrganizationalRole::where('slug', $orgSlug)->first();

            if (! $orgRole) {
                $this->line("  Organisationsrollen '{$orgSlug}' finns inte ännu — hoppar över.");
                continue;
            }

            // Collect all members across all periods of this org role
            $memberships = OrganizationalRoleMember::whereHas('period', fn ($q) =>
                $q->where('organizational_role_id', $orgRole->id)
            )->with(['period', 'member.user'])->get();

            // Build set of user IDs that should currently hold the role
            $shouldHave = collect();

            foreach ($memberships as $membership) {
                $user = $membership->member?->user;

                if (! $user) {
                    continue;
                }

                if ($this->shouldHaveRole($membership->period, $membership)) {
                    $shouldHave->push($user->id);
                }
            }

            $shouldHave = $shouldHave->unique();

            // Grant to those who should have it but don't
            foreach ($shouldHave as $userId) {
                $user = User::find($userId);
                if (! $user) {
                    continue;
                }
                if (! $user->hasRole($spatieRoleName)) {
                    $this->line("  [+] Ger '{$spatieRoleName}' till {$user->name}");
                    if (! $dryRun) {
                        $user->assignRole($spatieRoleName);
                    }
                    $granted++;
                } else {
                    $retained++;
                }
            }

            // Revoke from those who have it but shouldn't
            foreach ($spatieRole->users()->pluck('users.id') as $userId) {
                if ($shouldHave->contains($userId)) {
                    continue;
                }
                $user = User::find($userId);
                if (! $user) {
                    continue;
                }
                $reason = $this->revocationReason($orgRole, $user);
                $this->line("  [-] Tar '{$spatieRoleName}' från {$user->name} — {$reason}");
                if (! $dryRun) {
                    $user->removeRole($spatieRoleName);
                }
                $revoked++;
            }
        }

        // Sync section membership for org roles that have a linked section
        $this->syncSectionMemberships($dryRun);

        $suffix = $dryRun ? ' (dry-run, inga ändringar gjorda)' : '';
        $this->info("Klart: +{$granted} givna, -{$revoked} återkallade, {$retained} oförändrade{$suffix}.");

        return self::SUCCESS;
    }

    /**
     * Should this user currently hold the Spatie role based on the period's lifecycle?
     *
     * - Active phases (forming/active/reporting): always yes
     * - Closed: yes if within GRACE_MONTHS of mandate end
     *   Uses reporting_end as the mandate-end date (more accurate than active_end),
     *   falling back to active_end if reporting_end is not set.
     */
    private function shouldHaveRole(OrganizationalRolePeriod $period, ?OrganizationalRoleMember $membership = null): bool
    {
        // access_until on the individual membership overrides everything
        if ($membership?->access_until && Carbon::today()->lte($membership->access_until)) {
            return true;
        }

        if (in_array($period->status, [
            OrganizationalRolePeriod::STATUS_FORMING,
            OrganizationalRolePeriod::STATUS_ACTIVE,
            OrganizationalRolePeriod::STATUS_REPORTING,
        ])) {
            return true;
        }

        // Closed: check grace period
        $mandateEnd = $period->reporting_end ?? $period->active_end;

        if (! $mandateEnd) {
            return false;
        }

        return Carbon::today()->lte($mandateEnd->copy()->addMonths(self::GRACE_MONTHS));
    }

    private function syncSectionMemberships(bool $dryRun): void
    {
        $orgRoles = OrganizationalRole::whereNotNull('section_id')->with('section')->get();

        if ($orgRoles->isEmpty()) {
            return;
        }

        $this->line('Synkar sektionsmedlemskap…');

        foreach ($orgRoles as $orgRole) {
            $section = $orgRole->section;
            if (! $section) {
                continue;
            }

            // Members that should be in section: active period OR access_until in future
            $shouldBeMemberIds = OrganizationalRoleMember::whereHas('period', fn ($q) =>
                $q->where('organizational_role_id', $orgRole->id)
            )->where(fn ($q) =>
                $q->whereHas('period', fn ($q2) =>
                    $q2->whereIn('status', [
                        OrganizationalRolePeriod::STATUS_FORMING,
                        OrganizationalRolePeriod::STATUS_ACTIVE,
                        OrganizationalRolePeriod::STATUS_REPORTING,
                    ])
                )->orWhere(fn ($q2) =>
                    $q2->whereNotNull('access_until')->where('access_until', '>=', now())
                )
            )->pluck('member_id')->unique();

            // Add missing synced members
            foreach ($shouldBeMemberIds as $memberId) {
                $exists = SectionMembership::where('section_id', $section->id)
                    ->where('member_id', $memberId)
                    ->whereNull('left_at')
                    ->exists();

                if (! $exists) {
                    $this->line("  [+] Lägger till medlem {$memberId} i sektion '{$section->name}'");
                    if (! $dryRun) {
                        SectionMembership::updateOrCreate(
                            ['section_id' => $section->id, 'member_id' => $memberId],
                            [
                                'status'                  => 'active',
                                'joined_at'               => now(),
                                'left_at'                 => null,
                                'synced_from_org_role_id' => $orgRole->id,
                            ]
                        );
                    }
                }
            }

            // Only remove members that were synced from this org role and no longer should be
            $syncedMemberIds = SectionMembership::where('section_id', $section->id)
                ->where('synced_from_org_role_id', $orgRole->id)
                ->whereNull('left_at')
                ->pluck('member_id');

            foreach ($syncedMemberIds as $memberId) {
                if (! $shouldBeMemberIds->contains($memberId)) {
                    $this->line("  [-] Tar bort synkad medlem {$memberId} från sektion '{$section->name}'");
                    if (! $dryRun) {
                        SectionMembership::where('section_id', $section->id)
                            ->where('member_id', $memberId)
                            ->where('synced_from_org_role_id', $orgRole->id)
                            ->update(['left_at' => now()]);
                    }
                }
            }
        }
    }

    private function revocationReason(OrganizationalRole $orgRole, User $user): string
    {
        $latest = OrganizationalRoleMember::whereHas('member', fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('period', fn ($q) =>
                $q->where('organizational_role_id', $orgRole->id)
                  ->where('status', OrganizationalRolePeriod::STATUS_CLOSED)
            )
            ->with('period')
            ->get()
            ->sortByDesc(fn ($m) => $m->period->reporting_end ?? $m->period->active_end)
            ->first();

        if (! $latest) {
            return 'ingen koppling till perioden hittades';
        }

        $end      = $latest->period->reporting_end ?? $latest->period->active_end;
        $graceEnd = $end?->copy()->addMonths(self::GRACE_MONTHS);

        return 'karens löpte ut ' . ($graceEnd?->format('d/m Y') ?? '?');
    }
}
