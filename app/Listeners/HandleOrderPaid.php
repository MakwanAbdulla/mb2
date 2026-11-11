<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Mail\EventRegistrationMail;
use App\Mail\MembershipActivated;
use App\Mail\ShopOrderAdminNotificationMail;
use App\Mail\ShopOrderConfirmationMail;
use App\Models\Events\EventRegistration;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\Shop\OrderLine;
use App\Models\Gamification\XpLog;
use App\Services\Accounting\OrderJournalService;
use App\Services\GamificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class HandleOrderPaid
{
    public function handle(OrderPaid $event): void
    {
        $order = $event->order->load('lines.orderable');

        // Create accounting journal entry — runs first so any exception is caught
        // before downstream side-effects (mails, status changes).
        try {
            app(OrderJournalService::class)->bookOrder($order);
        } catch (\Throwable $e) {
            Log::error('OrderJournalService failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }

        $hasShopLines = false;

        foreach ($order->lines as $line) {
            if ($line->orderable instanceof MembershipPeriod) {
                $this->activateMembershipPeriod($line->orderable, $order);
            } elseif ($line->category === OrderLine::CATEGORY_EVENT) {
                $this->confirmEventRegistration($order);
            } elseif ($line->category === OrderLine::CATEGORY_SHOP) {
                $hasShopLines = true;
            }
        }

        if ($hasShopLines) {
            $this->sendShopConfirmation($order);
            $this->notifyAdmin($order);
        }
    }

    private function activateMembershipPeriod(MembershipPeriod $period, $order): void
    {
        if ($period->status === MembershipPeriod::STATUS_ACTIVE) {
            return; // Already activated (duplicate event)
        }

        $period->update([
            'status'       => MembershipPeriod::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);

        $member = $period->member;

        if ($member && $member->status !== Member::STATUS_ACTIVE) {
            $member->update(['status' => Member::STATUS_ACTIVE]);

            $wsEmail = $member->workspaceAccount?->email;
            if ($wsEmail) {
                app(\App\Services\GoogleWorkspaceService::class)->restoreAccount($wsEmail);
            }
        }

        if ($member?->email) {
            try {
                Mail::to($member->notificationEmail())->queue(new MembershipActivated($member, $period));
            } catch (\Throwable $e) {
                Log::error('Failed to send MembershipActivated mail', [
                    'member_id' => $member->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // Activate included family member periods (same year, same family group)
        if ($member?->family_group_id) {
            MembershipPeriod::whereHas('member', fn ($q) =>
                $q->where('family_group_id', $member->family_group_id)
                  ->where('id', '!=', $member->id)
            )
            ->where('year', $period->year)
            ->where('status', MembershipPeriod::STATUS_PENDING_PAYMENT)
            ->get()
            ->each(function (MembershipPeriod $familyPeriod) use ($order) {
                $this->activateMembershipPeriod($familyPeriod, $order);
            });
        }

        Log::info('Membership period activated via order', [
            'period_id' => $period->id,
            'member_id' => $member?->id,
            'order_id'  => $order->id,
        ]);
    }

    private function sendShopConfirmation($order): void
    {
        $email = $order->user?->email ?? $order->guest_email;

        if (! $email) {
            return;
        }

        $order->loadMissing('lines');

        try {
            Mail::to($email)->queue(new ShopOrderConfirmationMail($order));
        } catch (\Throwable $e) {
            Log::error('Failed to queue ShopOrderConfirmationMail', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }

        Log::info('Shop order confirmation sent', ['order_id' => $order->id]);
    }

    private function notifyAdmin($order): void
    {
        $adminEmail = config('mail.admin_email') ?: config('mail.from.address');

        if (! $adminEmail) {
            return;
        }

        try {
            Mail::to($adminEmail)->queue(new ShopOrderAdminNotificationMail($order));
        } catch (\Throwable $e) {
            Log::error('Failed to queue ShopOrderAdminNotificationMail', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function confirmEventRegistration($order): void
    {
        $registration = EventRegistration::where('order_id', $order->id)
            ->where('status', EventRegistration::STATUS_PENDING_PAYMENT)
            ->first();

        if (! $registration) {
            return; // Already confirmed or not found
        }

        $registration->confirm();
        EventRegistrationMail::sendFor($registration);

        $member = $registration->user?->member;
        if ($member) {
            $alreadyAwarded = XpLog::where('member_id', $member->id)
                ->where('source_type', XpLog::SOURCE_EVENT_REGISTRATION)
                ->where('source_id', $registration->id)
                ->exists();

            if (! $alreadyAwarded) {
                app(GamificationService::class)->award(
                    $member,
                    XpLog::SOURCE_EVENT_REGISTRATION,
                    GamificationService::XP_VALUES[XpLog::SOURCE_EVENT_REGISTRATION],
                    null,
                    $registration->id,
                );
            }
        }

        Log::info('Event registration confirmed via order', [
            'registration_id' => $registration->id,
            'order_id'        => $order->id,
        ]);
    }
}
