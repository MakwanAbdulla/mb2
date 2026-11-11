<?php

namespace App\Livewire\Booking;

use App\Models\AuditLog;
use App\Models\Booking\BookingActivity;
use App\Models\Booking\BookingResource;
use App\Models\Booking\RecurringBooking;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

class RecurringBookingForm extends Component
{
    #[Url(as: 'redigera')]
    public ?int $editingId = null;

    public array  $resourceIds      = [];
    public string $title            = '';
    public string $description      = '';
    public string $activity         = 'Brädspel';
    public string $bookingType      = 'group';
    public string $participationInfo = '';
    public string $notes            = '';
    public string $startTime        = '17:00';
    public string $endTime          = '22:00';
    public string $endsOn           = '';

    public string $frequencyType = 'weekly';
    public array  $weekdays      = [4];
    public int    $weekday       = 4;
    public int    $weekInterval  = 1;
    public int    $weekNumber    = 1;
    public int    $monthWeek     = 1;
    public int    $monthDay      = 1;
    public int    $month         = 1;
    public int    $monthInterval = 1;

    public bool  $saved     = false;
    public int   $created   = 0;
    public array $conflicts = [];

    public const WEEKDAYS = [
        1 => 'Måndag', 2 => 'Tisdag', 3 => 'Onsdag', 4 => 'Torsdag',
        5 => 'Fredag', 6 => 'Lördag', 7 => 'Söndag',
    ];

    public const WEEK_NUMBERS = [
        1 => 'Första', 2 => 'Andra', 3 => 'Tredje', 4 => 'Fjärde', -1 => 'Sista',
    ];

    public const MONTHS = [
        1 => 'Januari',  2 => 'Februari', 3 => 'Mars',      4 => 'April',
        5 => 'Maj',      6 => 'Juni',     7 => 'Juli',      8 => 'Augusti',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'December',
    ];

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->isAdmin(), 403);

        if ($this->editingId) {
            $recurring = RecurringBooking::findOrFail($this->editingId);

            $this->resourceIds       = $recurring->effectiveResourceIds();
            $this->title             = $recurring->title;
            $this->description       = $recurring->description ?? '';
            $this->activity          = $recurring->activity;
            $this->bookingType       = $recurring->booking_type;
            $this->participationInfo = $recurring->participation_info ?? '';
            $this->notes             = $recurring->notes ?? '';
            $this->startTime         = substr($recurring->start_time, 0, 5);
            $this->endTime           = substr($recurring->end_time, 0, 5);
            $this->endsOn            = $recurring->ends_on?->toDateString() ?? '';
            $this->frequencyType     = $recurring->frequency_type;
            $this->weekdays          = $recurring->effectiveWeekdays();
            $this->weekday           = $recurring->weekday ?? 4;
            $this->weekInterval      = $recurring->week_interval ?? 1;
            $this->weekNumber        = $recurring->week_number ?? 1;
            $this->monthWeek         = $recurring->month_week ?? 1;
            $this->monthDay          = $recurring->month_day ?? 1;
            $this->month             = $recurring->month ?? 1;
            $this->monthInterval     = $recurring->month_interval ?? 1;
            $this->conflicts         = $recurring->last_conflicts ?? [];
        }
    }

    public function updatedBookingType(): void
    {
        if ($this->bookingType === 'group') {
            $this->participationInfo = '';
        }
    }

    public function save(): void
    {
        abort_unless(Auth::user()->isAdmin(), 403);

        $isWeekly        = $this->frequencyType === 'weekly';
        $isMonthlyWd     = $this->frequencyType === 'monthly_weekday';
        $isMonthlyDate   = $this->frequencyType === 'monthly_date';
        $isYearly        = $this->frequencyType === 'yearly';
        $isYearlyWeek    = $this->frequencyType === 'yearly_week';
        $needsSingleWd   = $isMonthlyWd || $isYearlyWeek;
        $needsMonthDay   = $isMonthlyDate || $isYearly;
        $needsMonthInt   = $isMonthlyWd || $isMonthlyDate;

        $this->validate([
            'resourceIds'       => ['required', 'array', 'min:1'],
            'resourceIds.*'     => ['integer', 'exists:booking_resources,id'],
            'title'             => ['required', 'string', 'max:255'],
            'activity'          => ['required', 'in:' . implode(',', BookingActivity::activeNames())],
            'bookingType'       => ['required', 'in:group,open'],
            'description'       => ['nullable', 'string', 'max:2000'],
            'participationInfo' => ['nullable', 'string', 'max:1000'],
            'startTime'         => ['required', 'regex:/^([01]\d|2[0-3]):(00|30)$/', $this->startTimeRule()],
            'endTime'           => ['required', 'regex:/^(([01]\d|2[0-3]):(00|30)|24:00)$/', $this->endTimeRule()],
            'endsOn'            => ['nullable', 'date'],
            'frequencyType'     => ['required', 'in:weekly,monthly_weekday,monthly_date,yearly,yearly_week'],
            'weekdays'          => $isWeekly      ? ['required', 'array', 'min:1'] : ['nullable'],
            'weekdays.*'        => $isWeekly      ? ['integer', 'min:1', 'max:7']  : [],
            'weekday'           => $needsSingleWd ? ['required', 'integer', 'min:1', 'max:7'] : ['nullable'],
            'weekInterval'      => $isWeekly      ? ['required', 'integer', 'min:1', 'max:6'] : ['nullable'],
            'weekNumber'        => $isYearlyWeek  ? ['required', 'integer', 'min:1', 'max:53'] : ['nullable'],
            'monthWeek'         => $isMonthlyWd   ? ['required', 'integer', 'in:-1,1,2,3,4'] : ['nullable'],
            'monthDay'          => $needsMonthDay ? ['required', 'integer', 'min:1', 'max:31'] : ['nullable'],
            'month'             => $isYearly      ? ['required', 'integer', 'min:1', 'max:12'] : ['nullable'],
            'monthInterval'     => $needsMonthInt ? ['required', 'integer', 'in:1,2,3,4,6']  : ['nullable'],
        ], [], [
            'resourceIds'   => 'rum',
            'title'         => 'rubrik',
            'startTime'     => 'starttid',
            'endTime'       => 'sluttid',
            'endsOn'        => 'slutdatum',
            'weekdays'      => 'veckodagar',
            'weekday'       => 'veckodag',
            'weekInterval'  => 'intervall',
            'weekNumber'    => 'veckonummer',
            'monthWeek'     => 'förekomst i månaden',
            'monthDay'      => 'dag i månaden',
            'month'         => 'månad',
            'monthInterval' => 'månadsintervall',
        ]);

        $resourceIds = array_map('intval', $this->resourceIds);
        sort($resourceIds);

        $attributes = [
            'resource_id'        => $resourceIds[0],
            'resource_ids'       => count($resourceIds) > 1 ? $resourceIds : null,
            'user_id'            => Auth::id(),
            'title'              => $this->title,
            'description'        => $this->description ?: null,
            'activity'           => $this->activity,
            'booking_type'       => $this->bookingType,
            'participation_info' => $this->bookingType === 'open' ? $this->participationInfo : null,
            'notes'              => $this->notes ?: null,
            'start_time'         => $this->startTime,
            'end_time'           => $this->endTime,
            'ends_on'            => $this->endsOn ?: null,
            'frequency_type'     => $this->frequencyType,
            'weekdays'           => $isWeekly        ? array_map('intval', $this->weekdays) : null,
            'weekday'            => $needsSingleWd   ? $this->weekday : null,
            'week_interval'      => $isWeekly        ? $this->weekInterval : 1,
            'week_number'        => $isYearlyWeek    ? $this->weekNumber : null,
            'month_week'         => $isMonthlyWd     ? $this->monthWeek : null,
            'month_day'          => $needsMonthDay   ? $this->monthDay : null,
            'month'              => $isYearly        ? $this->month : null,
            'month_interval'     => $needsMonthInt   ? $this->monthInterval : 1,
        ];

        if ($this->editingId) {
            $recurring = RecurringBooking::findOrFail($this->editingId);
            $recurring->bookings()->where('starts_at', '>=', today())->delete();
            $recurring->update(array_merge($attributes, ['generate_until' => null]));
            $this->created = $recurring->generateUntil(now()->addYear());
            AuditLog::record('recurring_booking_updated', 'recurring_bookings', "{$this->title} ({$this->created} tillfällen regenererade)", $recurring);
        } else {
            $recurring = RecurringBooking::create($attributes);
            $this->created = $recurring->generateUntil(now()->addYear());
            AuditLog::record('recurring_booking_created', 'recurring_bookings', "{$this->title} ({$this->created} tillfällen)", $recurring);
        }

        $this->conflicts = $recurring->fresh()->last_conflicts ?? [];
        $this->saved     = true;
    }

    public function render()
    {
        return view('livewire.booking.recurring-booking-form', [
            'rooms'      => BookingResource::where('active', true)->orderBy('name')->get(),
            'activities' => BookingActivity::activeNames(),
        ]);
    }

    private function startTimeRule(): \Closure
    {
        return function ($attr, $value, $fail) {
            $min = \App\Models\SystemSetting::get('booking_start_time', '00:00');
            $max = \App\Models\SystemSetting::get('booking_end_time', '24:00');
            if ($value < $min) $fail("Starttiden kan inte vara före {$min}.");
            if ($value >= $max) $fail("Starttiden måste vara före {$max}.");
        };
    }

    private function endTimeRule(): \Closure
    {
        return function ($attr, $value, $fail) {
            $max = \App\Models\SystemSetting::get('booking_end_time', '24:00');
            if ($value > $max) $fail("Sluttiden kan inte vara efter {$max}.");
        };
    }
}
