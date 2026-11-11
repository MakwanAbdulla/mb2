<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\Events\Event;
use App\Models\Events\EventOccurrence;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BookingDaySummary
{
    /**
     * Character budgets that keep a day embed below Discord's limit of
     * 4 096 characters in the description, including the section headings.
     */
    private const BOOKINGS_BUDGET = 2500;

    private const EVENTS_BUDGET = 1400;

    /**
     * Bookings, fully free rooms and events for one day as a Discord embed.
     * Without full access, booking titles and members-only events are left out.
     */
    public function dayEmbed(CarbonInterface $date, bool $full = true): array
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->addDay()->startOfDay();

        $description = "**Bokat**\n".$this->formatDay(
            $dayStart,
            $this->bookingsBetween($dayStart, $dayEnd),
            $this->activeRooms(),
            self::BOOKINGS_BUDGET,
            $full,
        );

        $occurrences = $this->eventsBetween($dayStart, $dayEnd, $full);

        if ($occurrences->isNotEmpty()) {
            $description .= "\n\n**Evenemang**\n".$this->formatEvents($occurrences, $dayStart, $dayEnd);
        }

        return [
            'title' => 'Bokningar '.$date->locale('sv')->isoFormat('dddd').' '.$date->format('d/m Y'),
            'description' => $description,
            'color' => 0x5865F2,
        ];
    }

    /**
     * Whether the day has anything that is only shown with full access:
     * booking titles or members-only events.
     */
    public function dayHasMembersOnlyContent(CarbonInterface $date): bool
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->addDay()->startOfDay();

        return $this->bookingsBetween($dayStart, $dayEnd)->isNotEmpty()
            || $this->eventsBetween($dayStart, $dayEnd)->contains(
                fn (EventOccurrence $occurrence) => $occurrence->event->visibility === Event::VISIBILITY_MEMBERS_ONLY
            );
    }

    /**
     * Confirmed bookings that overlap the period, including bookings that
     * started before it or end after it.
     */
    public function bookingsBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Booking::with('resource')
            ->where('status', 'confirmed')
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Published, non-cancelled event occurrences that overlap the period.
     * Members-only events are included only with full access.
     */
    public function eventsBetween(CarbonInterface $from, CarbonInterface $to, bool $full = true): Collection
    {
        $visibilities = $full
            ? [Event::VISIBILITY_PUBLIC, Event::VISIBILITY_MEMBERS_ONLY]
            : [Event::VISIBILITY_PUBLIC];

        return EventOccurrence::with('event')
            ->whereNull('cancelled_at')
            ->where('starts_at', '<', $to)
            ->where(fn ($query) => $query
                ->where('ends_at', '>', $from)
                ->orWhere(fn ($query) => $query->whereNull('ends_at')->where('starts_at', '>=', $from)))
            ->whereHas('event', fn ($query) => $query
                ->where('status', Event::STATUS_PUBLISHED)
                ->whereIn('visibility', $visibilities))
            ->orderBy('starts_at')
            ->get();
    }

    public function activeRooms(): Collection
    {
        return BookingResource::query()->where('active', true)->orderBy('id')->get(['id', 'name']);
    }

    /**
     * Booking lines for one day followed by the rooms that are free all day,
     * kept within a character budget. Without full access the titles are left
     * out, since they can tell who made the booking.
     */
    public function formatDay(CarbonInterface $day, Collection $bookings, Collection $rooms, int $budget, bool $full = true): string
    {
        $dayStart = $day->copy()->startOfDay();
        $dayEnd = $day->copy()->addDay()->startOfDay();

        $dayBookings = $bookings
            ->filter(fn (Booking $booking) => $booking->starts_at->lt($dayEnd) && $booking->ends_at->gt($dayStart))
            ->values();

        $freeRooms = $rooms->whereNotIn('id', $dayBookings->pluck('resource_id'))->pluck('name');
        $freeLine = 'Helt lediga: '.($freeRooms->isEmpty() ? 'inga' : $freeRooms->implode(', '));

        if ($dayBookings->isEmpty()) {
            return Str::limit("Inga bokningar\n".$freeLine, $budget);
        }

        $lines = $dayBookings->map(function (Booking $booking) use ($dayStart, $dayEnd, $full) {
            $line = $this->timeRange($booking->starts_at, $booking->ends_at, $dayStart, $dayEnd)
                .' '.$booking->resource->name;

            return $full ? "{$line}: {$booking->title}" : $line;
        })->all();

        return $this->fitLines($lines, $freeLine, $budget, 'bokningar');
    }

    /**
     * Time span clipped to the given day, e.g. "00:00–18:00" for the last day
     * of a booking that started earlier. Midnight at the end is shown as 24:00.
     */
    public function timeRange(CarbonInterface $start, ?CarbonInterface $end, CarbonInterface $dayStart, CarbonInterface $dayEnd): string
    {
        $from = $start->lt($dayStart) ? $dayStart : $start;
        $range = $from->format('H:i');

        if ($end) {
            $to = $end->gt($dayEnd) ? $dayEnd : $end;
            $range .= '–'.($to->equalTo($dayEnd) ? '24:00' : $to->format('H:i'));
        }

        return $range;
    }

    /**
     * Join as many lines as fit within the budget, add a note about how many
     * were left out, and end with the optional closing line.
     *
     * @param  list<string>  $lines
     */
    public function fitLines(array $lines, string $closingLine, int $budget, string $noun): string
    {
        $reserve = mb_strlen($closingLine) + 40;
        $kept = [];
        $length = 0;

        foreach ($lines as $line) {
            if ($length + mb_strlen($line) + 1 + $reserve > $budget) {
                break;
            }

            $kept[] = $line;
            $length += mb_strlen($line) + 1;
        }

        $omitted = count($lines) - count($kept);

        if ($omitted > 0) {
            $kept[] = "… och {$omitted} {$noun} till";
        }

        if ($closingLine !== '') {
            $kept[] = $closingLine;
        }

        return Str::limit(implode("\n", $kept), $budget);
    }

    private function formatEvents(Collection $occurrences, CarbonInterface $dayStart, CarbonInterface $dayEnd): string
    {
        $lines = $occurrences->map(function (EventOccurrence $occurrence) use ($dayStart, $dayEnd) {
            $event = $occurrence->event;
            $url = rtrim(config('app.url'), '/').'/evenemang/'.$event->slug;
            $location = $occurrence->location_override ?: $event->location_name;

            $line = $this->timeRange($occurrence->starts_at, $occurrence->ends_at, $dayStart, $dayEnd)
                ." [{$event->title}]({$url})";

            return $location ? "{$line} ({$location})" : $line;
        })->all();

        return $this->fitLines($lines, '', self::EVENTS_BUDGET, 'evenemang');
    }
}
