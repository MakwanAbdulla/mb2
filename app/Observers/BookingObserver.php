<?php

namespace App\Observers;

use App\Models\Booking\Booking;
use App\Models\Gamification\XpLog;
use App\Services\BookingOverviewService;
use App\Services\GamificationService;

class BookingObserver
{
    /**
     * Fields that change what the Discord booking overview shows.
     */
    private const OVERVIEW_FIELDS = ['status', 'starts_at', 'ends_at', 'resource_id', 'title'];

    public function updated(Booking $booking): void
    {
        if (! $booking->wasChanged('status') || $booking->status !== 'confirmed') {
            return;
        }

        $member = $booking->user?->member;
        if (! $member) {
            return;
        }

        $alreadyAwarded = XpLog::where('member_id', $member->id)
            ->where('source_type', XpLog::SOURCE_BOOKING)
            ->where('source_id', $booking->id)
            ->exists();

        if (! $alreadyAwarded) {
            app(GamificationService::class)->award(
                $member,
                XpLog::SOURCE_BOOKING,
                GamificationService::XP_VALUES[XpLog::SOURCE_BOOKING],
                null,
                $booking->id,
            );
        }
    }

    public function created(Booking $booking): void
    {
        $this->queueDiscordOverview($booking);
    }

    public function saved(Booking $booking): void
    {
        if ($booking->wasChanged(self::OVERVIEW_FIELDS)) {
            $this->queueDiscordOverview($booking);
        }
    }

    public function deleted(Booking $booking): void
    {
        $this->queueDiscordOverview($booking);
    }

    private function queueDiscordOverview(Booking $booking): void
    {
        $overview = app(BookingOverviewService::class);

        if ($overview->covers($booking)) {
            $overview->queueRefresh();
        }
    }
}
