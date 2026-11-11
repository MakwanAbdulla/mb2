<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

class BookingOverviewService
{
    public const DAYS = 7;

    /**
     * Characters per day field. Seven days stay well below Discord's limit of
     * 6 000 characters per embed, including title and footer.
     */
    private const DAY_BUDGET = 800;

    public function __construct(
        private DiscordService $discord,
        private BookingDaySummary $summary,
    ) {}

    /**
     * Whether a booking overlaps the overview period, before or after the change.
     */
    public function covers(Booking $booking): bool
    {
        [$start, $end] = $this->period();

        $ranges = [
            [$booking->starts_at, $booking->ends_at],
            [$booking->getOriginal('starts_at'), $booking->getOriginal('ends_at')],
        ];

        foreach ($ranges as [$from, $to]) {
            if ($from && $to && Carbon::parse($from)->lt($end) && Carbon::parse($to)->gt($start)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refresh the overview once after the response has been sent, no matter
     * how many bookings change during the request.
     */
    public function queueRefresh(): void
    {
        defer(fn () => $this->refresh(), 'discord-booking-overview');
    }

    /**
     * Publish without throwing. A Discord problem must never affect bookings.
     */
    public function refresh(): void
    {
        try {
            $this->publish();
        } catch (Throwable $e) {
            Log::warning('Discord: booking overview could not be refreshed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Edit the existing overview message, or post a new one if there is none
     * or Discord reports that it has been deleted.
     */
    public function publish(): bool
    {
        $webhookUrl = SystemSetting::get('discord.webhook.bookings');

        if (! $webhookUrl) {
            return false;
        }

        $embed = $this->buildEmbed();
        $messageId = SystemSetting::get('discord.bookings_overview.message_id');

        if ($messageId) {
            $status = $this->discord->editWebhookMessage($webhookUrl, $messageId, $embed);

            if ($status >= 200 && $status < 300) {
                return true;
            }

            // Rate limits and server errors are retried by the next change or
            // the nightly run. Only a deleted message gets replaced.
            if ($status !== 404) {
                return false;
            }
        }

        $newMessageId = $this->discord->createWebhookMessage($webhookUrl, $embed);

        if (! $newMessageId) {
            return false;
        }

        SystemSetting::set('discord.bookings_overview.message_id', $newMessageId);

        return true;
    }

    public function buildEmbed(bool $full = true): array
    {
        [$start, $end] = $this->period();

        $bookings = $this->summary->bookingsBetween($start, $end);
        $rooms = $this->summary->activeRooms();

        $fields = [];

        for ($i = 0; $i < self::DAYS; $i++) {
            $day = $start->copy()->addDays($i);

            $fields[] = [
                'name' => ucfirst($day->locale('sv')->isoFormat('dddd')).' '.$day->format('d/m'),
                'value' => $this->summary->formatDay($day, $bookings, $rooms, self::DAY_BUDGET, $full),
                'inline' => false,
            ];
        }

        return [
            'title' => 'Bokningar kommande '.self::DAYS.' dagar',
            'color' => 0x5865F2,
            'fields' => $fields,
            'footer' => ['text' => 'Uppdaterad '.now()->format('d/m H:i')],
        ];
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public function period(): array
    {
        return [now()->startOfDay(), now()->addDays(self::DAYS)->startOfDay()];
    }
}
