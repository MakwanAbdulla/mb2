<?php

namespace App\Listeners;

use App\Events\OrderPaymentFailed;
use App\Mail\MembershipPaymentFailed;
use App\Models\AuditLog;
use App\Models\Membership\MembershipPeriod;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class HandleOrderPaymentFailed
{
    public function handle(OrderPaymentFailed $event): void
    {
        $order = $event->order->load('lines.orderable');

        foreach ($order->lines as $line) {
            if ($line->orderable instanceof MembershipPeriod) {
                $this->notifyMembershipPaymentFailed($line->orderable, $order, $event->reason);
            }
        }
    }

    private function notifyMembershipPaymentFailed(MembershipPeriod $period, $order, string $reason): void
    {
        $member = $period->member;

        if ($member?->email) {
            try {
                Mail::to($member->notificationEmail())->queue(new MembershipPaymentFailed($member, $period));
            } catch (\Throwable $e) {
                Log::error('MembershipPaymentFailed mail failed', [
                    'member_id' => $member->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        AuditLog::record(
            'membership_payment_failed',
            'members',
            $member?->full_name ?? "Order #{$order->id}",
            $period,
            ['reason' => $reason, 'order_id' => $order->id],
        );
    }
}
