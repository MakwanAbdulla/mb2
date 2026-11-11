<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\SystemSetting;
use Illuminate\Console\Command;

class DeactivateExpiredMemberships extends Command
{
    protected $signature   = 'members:deactivate-expired {--dry-run : Visa vilka som skulle avaktiveras utan att göra ändringar}';
    protected $description = 'Avaktiverar medlemmar vars nådeperiod löpt ut och som inte betalat för innevarande år';

    public function handle(): int
    {
        $graceMonths  = (int) SystemSetting::get('membership.grace_period_months', 1);
        $graceEnds    = now()->startOfYear()->addMonths($graceMonths);
        $currentYear  = now()->year;
        $deactivated  = 0;
        $skipped      = 0;

        // Fetch all currently active members who don't have a paid period this year
        Member::where('status', Member::STATUS_ACTIVE)
            ->whereNotIn('membership_type', [Member::TYPE_HONORARY]) // honorary members never expire
            ->whereDoesntHave('membershipPeriods', fn ($q) =>
                $q->where('year', $currentYear)
                  ->where('status', MembershipPeriod::STATUS_ACTIVE)
            )
            ->with(['membershipPeriods' => fn ($q) =>
                $q->where('year', $currentYear - 1)
                  ->where('status', MembershipPeriod::STATUS_ACTIVE)
            ])
            ->each(function (Member $member) use ($graceEnds, $currentYear, &$deactivated, &$skipped) {
                $hadLastYear = $member->membershipPeriods->isNotEmpty();

                // If they had last year's period: only deactivate after grace period ends
                if ($hadLastYear && now()->lt($graceEnds)) {
                    $skipped++;
                    $this->line("  Hoppar över #{$member->id} {$member->full_name} — inom nådeperioden");
                    return;
                }

                $reason = $hadLastYear ? 'grace_period_expired' : 'no_membership_period';
                $reasonLabel = AuditLog::DEACTIVATION_REASON_LABELS[$reason];

                if ($this->option('dry-run')) {
                    $this->line("  [dry-run] Skulle avaktivera #{$member->id} {$member->full_name} — {$reasonLabel}");
                    $deactivated++;
                    return;
                }

                $member->expire();

                AuditLog::record(
                    action: 'membership_deactivated',
                    area: 'members',
                    subject: "Medlemskap avaktiverat automatiskt: {$member->full_name} (#{$member->membership_number})",
                    auditable: $member,
                    changes: [
                        'reason'          => $reason,
                        'reason_label'    => $reasonLabel,
                        'previous_status' => Member::STATUS_ACTIVE,
                        'new_status'      => Member::STATUS_EXPIRED,
                        'year'            => $currentYear,
                        'had_last_year'   => $hadLastYear,
                    ],
                    userId: null,
                );

                $this->line("  Avaktiverade #{$member->id} {$member->full_name} — {$reasonLabel}");
                $deactivated++;
            });

        $suffix = $this->option('dry-run') ? ' (dry-run, inga ändringar gjorda)' : '';
        $this->info("Klart — {$deactivated} avaktiverade, {$skipped} kvar i nådeperioden{$suffix}.");

        return self::SUCCESS;
    }
}
