<?php

namespace App\Models\Booking;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int         $id
 * @property int         $resource_id
 * @property array|null  $resource_ids
 * @property int         $user_id
 * @property string      $title
 * @property string      $activity
 * @property string      $booking_type
 * @property string|null $participation_info
 * @property string|null $notes
 * @property string      $start_time
 * @property string      $end_time
 * @property string      $frequency_type
 * @property int|null    $weekday
 * @property array|null  $weekdays
 * @property int         $week_interval
 * @property int|null    $week_number
 * @property int|null    $month_week
 * @property int|null    $month_day
 * @property int         $month_interval
 * @property int|null    $month
 * @property \Carbon\Carbon|null $generate_until
 * @property \Carbon\Carbon|null $ends_on
 * @property array|null  $last_conflicts
 */
class RecurringBooking extends Model
{
    protected $fillable = [
        'resource_id', 'resource_ids', 'user_id', 'title', 'description', 'activity', 'booking_type',
        'participation_info', 'notes', 'start_time', 'end_time',
        'frequency_type', 'weekday', 'weekdays', 'week_interval', 'week_number',
        'month_week', 'month_day', 'month_interval', 'month',
        'generate_until', 'ends_on', 'last_conflicts',
    ];

    protected $casts = [
        'generate_until' => 'date',
        'ends_on'        => 'date',
        'weekdays'       => 'array',
        'resource_ids'   => 'array',
        'last_conflicts' => 'array',
    ];

    public const WEEKDAYS = [
        1 => 'måndag', 2 => 'tisdag', 3 => 'onsdag', 4 => 'torsdag',
        5 => 'fredag', 6 => 'lördag', 7 => 'söndag',
    ];

    public const WEEK_NUMBERS = [
        1 => 'första', 2 => 'andra', 3 => 'tredje', 4 => 'fjärde', -1 => 'sista',
    ];

    public const MONTHS = [
        1 => 'januari',   2 => 'februari', 3 => 'mars',     4 => 'april',
        5 => 'maj',       6 => 'juni',     7 => 'juli',     8 => 'augusti',
        9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december',
    ];

    /** Returnerar alla valda rum-IDs (ny resource_ids eller gammalt resource_id). */
    public function effectiveResourceIds(): array
    {
        return $this->resource_ids ?: [$this->resource_id];
    }

    /** Returnerar effektiva veckodagar för weekly-mönstret (ny weekdays eller gammal weekday). */
    public function effectiveWeekdays(): array
    {
        $days = $this->weekdays ?: [$this->weekday];
        sort($days);
        return $days;
    }

    /** Kommaavgränsad lista med rumsnamn för visning. */
    public function resourceNames(): string
    {
        $ids = $this->effectiveResourceIds();
        if (count($ids) === 1) {
            return $this->resource->name ?? '–';
        }
        return BookingResource::whereIn('id', $ids)->orderBy('name')->pluck('name')->join(', ');
    }

    public function frequencyLabel(): string
    {
        $wd = self::WEEKDAYS[$this->weekday] ?? '?';
        $wn = self::WEEK_NUMBERS[$this->month_week] ?? '?';
        $mo = self::MONTHS[$this->month] ?? '?';

        return match ($this->frequency_type) {
            'weekly'          => $this->weeklyLabel(),
            'monthly_weekday' => $this->monthlyIntervalPrefix() . ucfirst("$wn $wd"),
            'monthly_date'    => $this->monthlyIntervalPrefix() . "{$this->month_day}:e",
            'yearly'          => "{$this->month_day} $mo/år",
            'yearly_week'     => "V.{$this->week_number} $wd/år",
            default           => '?',
        };
    }

    private function weeklyLabel(): string
    {
        $days     = $this->effectiveWeekdays();
        $names    = array_map(fn ($d) => self::WEEKDAYS[$d] ?? '?', $days);
        $dayStr   = count($names) === 1
            ? $names[0]
            : implode(', ', array_slice($names, 0, -1)) . ' och ' . end($names);
        $interval = (int) $this->week_interval;

        if (count($days) === 1) {
            return match ($interval) {
                1       => "Varje $dayStr",
                2       => "Varannan $dayStr",
                3       => "Var tredje $dayStr",
                4       => "Var fjärde $dayStr",
                5       => "Var femte $dayStr",
                6       => "Var sjätte $dayStr",
                default => "Var {$interval}:e $dayStr",
            };
        }

        return match ($interval) {
            1       => "Varje vecka: $dayStr",
            2       => "Varannan vecka: $dayStr",
            3       => "Var tredje vecka: $dayStr",
            4       => "Var fjärde vecka: $dayStr",
            5       => "Var femte vecka: $dayStr",
            6       => "Var sjätte vecka: $dayStr",
            default => "Var {$interval}:e vecka: $dayStr",
        };
    }

    private function monthlyIntervalPrefix(): string
    {
        $i = max(1, (int) ($this->month_interval ?? 1));
        return match ($i) {
            1       => '',
            2       => 'Varannan mån: ',
            3       => 'Var 3:e mån: ',
            4       => 'Tertialvis: ',
            6       => 'Var 6:e mån: ',
            default => "Var {$i}:e mån: ",
        };
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookingResource::class, 'resource_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function bookings(): MorphMany
    {
        return $this->morphMany(Booking::class, 'source');
    }

    /**
     * Generera bokningsinstanser upp till och med $until.
     * Returnerar antal nyligen skapade bokningar.
     */
    public function generateUntil(Carbon $until): int
    {
        $limit = $this->ends_on ? min($until, $this->ends_on) : $until;

        $from = $this->generate_until
            ? $this->generate_until->copy()->addDay()
            : today();

        $dates = match ($this->frequency_type) {
            'weekly'          => $this->weeklyDates($from, $limit),
            'monthly_weekday' => $this->monthlyWeekdayDates($from, $limit),
            'monthly_date'    => $this->monthlyDateDates($from, $limit),
            'yearly'          => $this->yearlyDates($from, $limit),
            'yearly_week'     => $this->yearlyWeekDates($from, $limit),
            default           => [],
        };

        [$startH, $startM] = explode(':', $this->start_time);
        [$endH,   $endM]   = explode(':', $this->end_time);

        $resources = BookingResource::whereIn('id', $this->effectiveResourceIds())->get()->keyBy('id');

        $created   = 0;
        $conflicts = [];

        foreach ($dates as $date) {
            $startsAt = $date->copy()->setHour((int) $startH)->setMinute((int) $startM)->setSecond(0);

            if ($this->end_time === '24:00' || $this->end_time === '00:00') {
                $endsAt = $date->copy()->addDay()->startOfDay();
            } else {
                $endsAt = $date->copy()->setHour((int) $endH)->setMinute((int) $endM)->setSecond(0);
            }

            foreach ($resources as $resource) {
                if (! $resource->isAvailable($startsAt, $endsAt)) {
                    $blocking = $resource->firstConflict($startsAt, $endsAt);
                    $conflicts[] = [
                        'date'           => $date->toDateString(),
                        'resource_id'    => $resource->id,
                        'resource_name'  => $resource->name,
                        'blocking_id'    => $blocking?->id,
                        'blocking_title' => $blocking?->title,
                    ];
                    continue;
                }

                Booking::create([
                    'source_type'        => self::class,
                    'source_id'          => $this->id,
                    'resource_id'        => $resource->id,
                    'user_id'            => $this->user_id,
                    'title'              => $this->title,
                    'activity'           => $this->activity,
                    'booking_type'       => $this->booking_type,
                    'participation_info' => $this->participation_info,
                    'notes'              => $this->notes,
                    'starts_at'          => $startsAt,
                    'ends_at'            => $endsAt,
                    'status'             => 'confirmed',
                ]);

                $created++;
            }
        }

        $this->update([
            'generate_until' => $limit->format('Y-m-d'),
            'last_conflicts' => $conflicts ?: null,
        ]);

        return $created;
    }

    private function weeklyDates(Carbon $from, Carbon $until): array
    {
        $dates     = [];
        $interval  = max(1, (int) $this->week_interval);
        $weekdays  = $this->effectiveWeekdays();
        $weekStart = $from->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();

        while ($weekStart->lte($until)) {
            foreach ($weekdays as $wd) {
                $date = $weekStart->copy()->isoWeekday($wd);
                if ($date->gte($from) && $date->lte($until)) {
                    $dates[] = $date;
                }
            }
            $weekStart->addWeeks($interval);
        }

        return $dates;
    }

    private function monthlyWeekdayDates(Carbon $from, Carbon $until): array
    {
        $dates    = [];
        $interval = max(1, (int) ($this->month_interval ?? 1));
        $month    = $from->copy()->startOfMonth();

        while ($month->lte($until)) {
            $date = $this->nthWeekdayOfMonth($month, $this->weekday, $this->month_week);

            if ($date !== null && $date->gte($from) && $date->lte($until)) {
                $dates[] = $date;
            }

            $month->addMonths($interval);
        }

        return $dates;
    }

    private function monthlyDateDates(Carbon $from, Carbon $until): array
    {
        $dates    = [];
        $day      = (int) $this->month_day;
        $interval = max(1, (int) ($this->month_interval ?? 1));
        $current  = $from->copy()->startOfMonth();

        while ($current->lte($until)) {
            if ($day <= $current->daysInMonth) {
                $date = $current->copy()->setDay($day);
                if ($date->gte($from) && $date->lte($until)) {
                    $dates[] = $date;
                }
            }
            $current->addMonths($interval);
        }

        return $dates;
    }

    private function yearlyDates(Carbon $from, Carbon $until): array
    {
        $dates   = [];
        $month   = (int) $this->month;
        $day     = (int) $this->month_day;
        $current = $from->copy()->startOfYear();

        while ($current->lte($until)) {
            $daysInMonth = $current->copy()->setMonth($month)->daysInMonth;
            if ($day <= $daysInMonth) {
                $date = $current->copy()->setMonth($month)->setDay($day);
                if ($date->gte($from) && $date->lte($until)) {
                    $dates[] = $date;
                }
            }
            $current->addYear();
        }

        return $dates;
    }

    private function yearlyWeekDates(Carbon $from, Carbon $until): array
    {
        $dates      = [];
        $weekNumber = (int) $this->week_number;
        $weekday    = (int) $this->weekday;
        $year       = $from->copy()->year;

        while (true) {
            $date = Carbon::now()->setISODate($year, $weekNumber, $weekday)->startOfDay();

            if ($date->gt($until)) {
                break;
            }

            if ($date->gte($from)) {
                $dates[] = $date;
            }

            $year++;
        }

        return $dates;
    }

    /** Beräknar den Nth förekomsten av en ISO-veckodag i en given månad. $n=-1 ger sista. */
    private function nthWeekdayOfMonth(Carbon $month, int $isoWeekday, int $n): ?Carbon
    {
        if ($n === -1) {
            $date = $month->copy()->endOfMonth()->startOfDay();
            while ($date->isoWeekday() !== $isoWeekday) {
                $date->subDay();
            }
            return $date;
        }

        $date = $month->copy()->startOfMonth();

        while ($date->isoWeekday() !== $isoWeekday) {
            $date->addDay();
        }

        $date->addWeeks($n - 1);

        if ($date->month !== $month->month) {
            return null;
        }

        return $date;
    }
}
