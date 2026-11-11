<?php

namespace App\Livewire\Booking;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\Booking\RecurringBooking;
use App\Models\Events\EventOccurrence;
use App\Models\Meeting\Meeting;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

class Calendar extends Component
{
    #[Url(as: 'datum')]
    public string $date = '';

    #[Url(as: 'vy')]
    public string $view = 'week';

    public array  $hiddenRooms = [];
    public ?array $slotCheck   = null;

    private const ROOM_SHAPES = [
        '<circle cx="6" cy="6" r="4.5"/>',
        '<polygon points="6,1 11,10.5 1,10.5"/>',
        '<rect x="1.5" y="1.5" width="9" height="9"/>',
        '<polygon points="6,1 11,6 6,11 1,6"/>',
        '<polygon points="6,1.5 7.3,4.8 10.8,4.8 8,7 9,10.5 6,8.3 3,10.5 4,7 1.2,4.8 4.7,4.8"/>',
        '<rect x="4.5" y="1.5" width="3" height="9"/><rect x="1.5" y="4.5" width="9" height="3"/>',
        '<polygon points="6,1 10.5,3.6 10.5,8.4 6,11 1.5,8.4 1.5,3.6"/>',
        '<polygon points="6,1 9.8,3.4 10.8,7.6 8,10.9 4,10.9 1.2,7.6 2.2,3.4"/>',
    ];

    public function mount(): void
    {
        if (! $this->date) {
            $this->date = today()->format('Y-m-d');
        }
        if (! in_array($this->view, ['day', 'week', 'list'])) {
            $this->view = 'week';
        }
    }

    public function toggleRoom(int $id): void
    {
        if (in_array($id, $this->hiddenRooms, true)) {
            $this->hiddenRooms = array_values(
                array_filter($this->hiddenRooms, fn ($r) => $r !== $id)
            );
        } else {
            $this->hiddenRooms[] = $id;
        }
    }

    public function toggleAllRooms(): void
    {
        $allIds = BookingResource::where('active', true)->pluck('id')->all();
        if (count($this->hiddenRooms) === count($allIds)) {
            $this->hiddenRooms = [];
        } else {
            $this->hiddenRooms = $allIds;
        }
    }

    private function navLimits(): array
    {
        if (Auth::check() && Auth::user()->isAdmin()) {
            return ['min' => null, 'max' => null];
        }

        $monthsAhead = (int) SystemSetting::get('user_max_months_ahead', 6);

        return [
            'min' => today()->subMonth()->startOfDay(),
            'max' => today()->addMonths($monthsAhead)->endOfDay(),
        ];
    }

    public function previousDay(): void
    {
        ['min' => $min] = $this->navLimits();
        $new = Carbon::parse($this->date)->subDay();
        if ($min === null || $new >= $min) {
            $this->date = $new->format('Y-m-d');
        }
    }

    public function nextDay(): void
    {
        ['max' => $max] = $this->navLimits();
        $new = Carbon::parse($this->date)->addDay();
        if ($max === null || $new <= $max) {
            $this->date = $new->format('Y-m-d');
        }
    }

    public function goToToday(): void
    {
        $this->date = today()->format('Y-m-d');
    }

    public function previousWeek(): void
    {
        ['min' => $min] = $this->navLimits();
        $new = Carbon::parse($this->date)->subWeek();
        if ($min === null || $new->copy()->startOfWeek(Carbon::MONDAY)->addDays(6) >= $min) {
            $this->date = $new->format('Y-m-d');
        }
    }

    public function nextWeek(): void
    {
        ['max' => $max] = $this->navLimits();
        $new = Carbon::parse($this->date)->addWeek();
        if ($max === null || $new->copy()->startOfWeek(Carbon::MONDAY) <= $max) {
            $this->date = $new->format('Y-m-d');
        }
    }

    public function checkSlot(string $date, string $startTime, string $endTime): void
    {
        if (! Auth::check()) {
            return;
        }
        $user = Auth::user();
        if (! $user->isAdmin() && ! $user->isMember()) {
            return;
        }

        $startsAt = Carbon::parse("{$date} {$startTime}");
        $endsAt   = $endTime === '24:00'
            ? Carbon::parse("{$date} 00:00")->addDay()
            : Carbon::parse("{$date} {$endTime}");

        if ($endsAt <= $startsAt) {
            return;
        }

        $available = BookingResource::where('active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn ($r) => $r->isAvailable($startsAt, $endsAt))
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug])
            ->values()
            ->toArray();

        $this->slotCheck = [
            'date'           => $date,
            'startTime'      => $startTime,
            'endTime'        => $endTime,
            'availableRooms' => $available,
        ];
    }

    public function clearSlotCheck(): void
    {
        $this->slotCheck = null;
    }

    public function restoreState(string $date, string $view, array $hiddenRooms = []): void
    {
        if ($date) {
            $this->date = $date;
        }
        if (in_array($view, ['day', 'week', 'list'])) {
            $this->view = $view;
        }
        $this->hiddenRooms = $hiddenRooms;
    }

    public function render()
    {
        $currentDate = Carbon::parse($this->date);
        $allRooms    = BookingResource::where('active', true)->orderBy('name')->get();
        $rooms       = $allRooms->filter(fn ($r) => ! in_array($r->id, $this->hiddenRooms, true))->values();

        $roomColors = $allRooms->mapWithKeys(fn ($room) => [$room->id => $room->color ?? '#818cf8']);
        $shapes     = self::ROOM_SHAPES;
        $roomShapes = $allRooms->values()->mapWithKeys(fn ($room, $i) => [$room->id => $shapes[$i % count($shapes)]]);

        $bookingsByRoom = collect();
        $listBookings   = collect();
        $weekData       = collect();
        $weekDays       = collect();
        $weekStart      = null;
        $isCurrentWeek  = false;

        $morphMap = [
            EventOccurrence::class => ['event:id,title,slug'],
            Meeting::class         => [],
            RecurringBooking::class => [],
        ];

        if ($this->view === 'list') {
            $listBookings = Booking::with(['user', 'resource'])
                ->where('starts_at', '<', $currentDate->copy()->endOfDay())
                ->where('ends_at',   '>', $currentDate->copy()->startOfDay())
                ->whereIn('resource_id', $rooms->pluck('id'))
                ->whereIn('status', ['confirmed', 'pending'])
                ->orderBy('starts_at')
                ->get();
            $listBookings->loadMorph('source', $morphMap);
        } elseif ($this->view === 'week') {
            $weekStart     = $currentDate->copy()->startOfWeek(Carbon::MONDAY);
            $weekDays      = collect(range(0, 6))->map(fn ($i) => $weekStart->copy()->addDays($i));
            $weekEnd       = $weekDays->last()->copy()->endOfDay();
            $isCurrentWeek = today()->between($weekStart, $weekDays->last());

            $rawWeek = Booking::with('user')
                ->whereIn('resource_id', $rooms->pluck('id'))
                ->whereIn('status', ['confirmed', 'pending'])
                ->where('starts_at', '<', $weekEnd)
                ->where('ends_at', '>', $weekStart)
                ->orderBy('starts_at')
                ->get();
            $rawWeek->loadMorph('source', $morphMap);

            $weekData = $rawWeek
                ->flatMap(fn ($b) => $weekDays
                    ->filter(fn ($day) =>
                        $b->starts_at < $day->copy()->endOfDay() &&
                        $b->ends_at   > $day->copy()->startOfDay()
                    )
                    ->map(fn ($day) => ['date' => $day->format('Y-m-d'), 'booking' => $b])
                )
                ->groupBy('date')
                ->map(fn ($items) => $items->pluck('booking'));
        } else {
            $raw = Booking::with(['user'])
                ->where('starts_at', '<', $currentDate->copy()->endOfDay())
                ->where('ends_at',   '>', $currentDate->copy()->startOfDay())
                ->whereIn('resource_id', $rooms->pluck('id'))
                ->whereIn('status', ['confirmed', 'pending'])
                ->orderBy('starts_at')
                ->get();
            $raw->loadMorph('source', $morphMap);
            $bookingsByRoom = $raw->groupBy('resource_id');
        }

        $holidays            = $this->swedishHolidays((int) $currentDate->format('Y'));
        $isToday             = $this->date === today()->format('Y-m-d');
        $allowSameDayBooking = (bool) SystemSetting::get('allow_same_day_booking', false);

        $limits      = $this->navLimits();
        $navMinDate  = $limits['min']?->format('Y-m-d');
        $navMaxDate  = $limits['max']?->format('Y-m-d');
        $parsedDate  = Carbon::parse($this->date);

        $canGoPrevDay  = $limits['min'] === null || $parsedDate->copy()->subDay() >= $limits['min'];
        $canGoNextDay  = $limits['max'] === null || $parsedDate->copy()->addDay() <= $limits['max'];

        $ws = $weekStart ?? $parsedDate->copy()->startOfWeek(Carbon::MONDAY);
        $canGoPrevWeek = $limits['min'] === null || $ws->copy()->subWeek()->addDays(6) >= $limits['min'];
        $canGoNextWeek = $limits['max'] === null || $ws->copy()->addWeek() <= $limits['max'];

        return view('livewire.booking.calendar', compact(
            'allRooms', 'rooms', 'bookingsByRoom', 'listBookings', 'currentDate', 'isToday',
            'weekData', 'weekDays', 'weekStart', 'roomColors', 'roomShapes', 'isCurrentWeek',
            'holidays', 'allowSameDayBooking',
            'canGoPrevDay', 'canGoNextDay', 'canGoPrevWeek', 'canGoNextWeek',
            'navMinDate', 'navMaxDate'
        ));
    }

    private function swedishHolidays(int $year): array
    {
        $prev   = $year - 1;
        $next   = $year + 1;
        $easter = date('Y-m-d', easter_date($year));

        return array_values(array_unique([
            $prev . '-12-25',
            $prev . '-12-26',
            $year . '-01-01',
            $year . '-01-06',
            date('Y-m-d', strtotime($easter . ' -2 days')),
            date('Y-m-d', strtotime($easter . ' +1 days')),
            $year . '-05-01',
            date('Y-m-d', strtotime($easter . ' +39 days')),
            $year . '-06-06',
            date('Y-m-d', strtotime('next saturday', strtotime($year . '-06-19'))),
            date('Y-m-d', strtotime('next saturday', strtotime($year . '-10-30'))),
            $year . '-12-25',
            $year . '-12-26',
            $next . '-01-01',
            $next . '-01-06',
        ]));
    }
}
