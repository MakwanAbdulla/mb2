<?php

namespace App\Console\Commands;

use App\Mail\PaymentReminderMail;
use App\Models\Membership\Member;
use App\Models\Content\NotificationLog;
use App\Models\Shop\Order;
use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendPaymentReminders extends Command
{
    protected $signature   = 'members:send-payment-reminders {--dry-run : List would-be recipients without sending}';
    protected $description = 'Send payment reminder emails for pending orders past the reminder threshold';

    public function handle(): int
    {
        $reminder1Days = (int) SystemSetting::get('notification.payment_reminder_1_days', 7);
        $reminder2Days = (int) SystemSetting::get('notification.payment_reminder_2_days', 14);

        $sent = 0;

        Order::with(['user.member', 'lines.orderable'])
            ->where('status', Order::STATUS_PENDING)
            ->whereNull('guest_email') // only registered members, guests handled separately
            ->whereNotNull('user_id')
            ->whereHas('user', fn ($q) => $q->whereNotNull('email'))
            ->where('created_at', '<=', now()->subDays($reminder1Days))
            ->each(function (Order $order) use ($reminder1Days, $reminder2Days, &$sent) {
                $member = $order->user?->member;
                $email  = $order->user?->email;

                if (! $member || ! $email) {
                    return;
                }

                $daysSince = (int) $order->created_at->diffInDays(now());

                // Determine which reminder stage we're in
                if ($daysSince >= $reminder2Days) {
                    $notifType = 'payment_reminder_2';
                    $sinceDate = now()->subDays($reminder2Days - 1);
                } else {
                    $notifType = 'payment_reminder_1';
                    $sinceDate = now()->subDays($reminder1Days - 1);
                }

                // Skip if already sent this reminder type recently
                if (NotificationLog::alreadySent($order, $notifType, $sinceDate)) {
                    $this->line("  Hoppar över #{$order->id} – {$notifType} redan skickat");
                    return;
                }

                if ($this->option('dry-run')) {
                    $this->line("  [dry-run] Skulle skicka {$notifType} till {$email} för order #{$order->id}");
                    return;
                }

                Mail::to($email)->send(new PaymentReminderMail($order, $member));
                NotificationLog::record($order, $notifType, $email);

                $this->line("  Skickade {$notifType} till {$email} för order #{$order->id}");
                $sent++;
            });

        $this->info("Klart – skickade {$sent} påminnelse(r).");

        return self::SUCCESS;
    }
}
