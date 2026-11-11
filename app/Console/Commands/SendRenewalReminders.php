<?php

namespace App\Console\Commands;

use App\Mail\MembershipRenewalMail;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\Content\NotificationLog;
use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendRenewalReminders extends Command
{
    protected $signature   = 'members:send-renewal-reminders {--dry-run : List would-be recipients without sending}';
    protected $description = 'Send membership renewal reminder emails to active members who haven\'t renewed for next year';

    public function handle(): int
    {
        $renewalMonth = (int) SystemSetting::get('membership.renewal_visible_month', 12);
        $currentMonth = now()->month;

        if ($currentMonth < $renewalMonth) {
            $this->info("Inte dags för förnyelsemail (öppnar månad {$renewalMonth}, nu är det månad {$currentMonth}).");
            return self::SUCCESS;
        }

        $renewalYear  = now()->year + 1;
        $notifType    = "renewal_reminder_{$renewalYear}";
        $sent         = 0;

        // Members with an active period this year who haven't yet renewed for next year
        Member::with(['user', 'membershipPeriods'])
            ->where('status', Member::STATUS_ACTIVE)
            ->whereHas('membershipPeriods', fn ($q) => $q
                ->where('year', now()->year)
                ->where('status', MembershipPeriod::STATUS_ACTIVE)
            )
            ->whereDoesntHave('membershipPeriods', fn ($q) => $q
                ->where('year', $renewalYear)
                ->whereIn('status', [MembershipPeriod::STATUS_ACTIVE, MembershipPeriod::STATUS_PENDING_PAYMENT])
            )
            ->each(function (Member $member) use ($renewalYear, $notifType, &$sent) {
                $email = $member->notificationEmail();

                if (! $email) {
                    return;
                }

                // Only send once per renewal year
                if (NotificationLog::alreadySent($member, $notifType)) {
                    $this->line("  Hoppar över #{$member->id} {$member->full_name} – redan skickat");
                    return;
                }

                if ($this->option('dry-run')) {
                    $this->line("  [dry-run] Skulle skicka förnyelsemail till {$email} ({$member->full_name})");
                    return;
                }

                Mail::to($email)->send(new MembershipRenewalMail($member, $renewalYear));
                NotificationLog::record($member, $notifType, $email);

                $this->line("  Skickade förnyelsemail till {$email} ({$member->full_name})");
                $sent++;
            });

        $this->info("Klart – skickade {$sent} förnyelsemail(s) för {$renewalYear}.");

        return self::SUCCESS;
    }
}
