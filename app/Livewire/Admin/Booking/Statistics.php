<?php

namespace App\Livewire\Admin\Booking;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

class Statistics extends Component
{
    #[Url(as: 'ar')]
    public int $year = 0;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('bookings.statistics'), 403);
        if ($this->year < 2000) {
            $this->year = now()->year;
        }
    }

    public function render()
    {
        $year      = $this->year;
        $prevYear  = $year - 1;
        $yearStart = Carbon::createFromDate($year,     1, 1)->startOfDay();
        $yearEnd   = Carbon::createFromDate($year,    12, 31)->endOfDay();
        $prevStart = Carbon::createFromDate($prevYear, 1, 1)->startOfDay();
        $prevEnd   = Carbon::createFromDate($prevYear, 12, 31)->endOfDay();

        $currentBookings = Booking::with('user:id,name')
            ->where('status', 'confirmed')
            ->whereBetween('starts_at', [$yearStart, $yearEnd])
            ->get(['id', 'user_id', 'resource_id', 'activity', 'booking_type', 'starts_at', 'ends_at']);

        $prevBookings = Booking::where('status', 'confirmed')
            ->whereBetween('starts_at', [$prevStart, $prevEnd])
            ->get(['id', 'user_id', 'activity', 'starts_at']);

        // KPIs
        $totalCurrent         = $currentBookings->count();
        $totalPrev            = $prevBookings->count();
        $uniqueMembersCurrent = $currentBookings->pluck('user_id')->unique()->count();
        $uniqueMembersPrev    = $prevBookings->pluck('user_id')->unique()->count();
        $openCurrent          = $currentBookings->where('booking_type', 'open')->count();
        $topActivity          = $currentBookings->groupBy('activity')->map->count()->sortDesc()->keys()->first();

        // Monthly
        $monthNames     = ['Jan', 'Feb', 'Mar', 'Apr', 'Maj', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];
        $currentByMonth = $currentBookings->groupBy(fn ($b) => (int) $b->starts_at->format('n'));
        $prevByMonth    = $prevBookings->groupBy(fn ($b) => (int) $b->starts_at->format('n'));
        $monthly        = collect(range(1, 12))->map(fn ($m) => [
            'label'   => $monthNames[$m - 1],
            'current' => $currentByMonth->get($m, collect())->count(),
            'prev'    => $prevByMonth->get($m, collect())->count(),
        ]);

        // Room stats
        $rooms         = BookingResource::where('active', true)->orderBy('name')->get();
        $currentByRoom = $currentBookings->groupBy('resource_id');
        $roomStats     = $rooms->map(function ($room) use ($currentByRoom) {
            $bs        = $currentByRoom->get($room->id, collect());
            $count     = $bs->count();
            $totalMin  = $bs->sum(fn ($b) => $b->starts_at->diffInMinutes($b->ends_at));
            $openCount = $bs->where('booking_type', 'open')->count();
            return [
                'name'     => $room->name,
                'count'    => $count,
                'hours'    => $count > 0 ? round($totalMin / 60, 1) : 0,
                'avg_min'  => $count > 0 ? (int) round($totalMin / $count) : 0,
                'open_pct' => $count > 0 ? (int) round($openCount / $count * 100) : 0,
            ];
        });

        // Activity stats
        $currentByActivity = $currentBookings->groupBy('activity')->map->count()->sortDesc();
        $prevByActivity    = $prevBookings->groupBy('activity')->map->count();
        $activityStats     = $currentByActivity->map(fn ($count, $act) => [
            'label'   => $act,
            'current' => $count,
            'prev'    => (int) $prevByActivity->get($act, 0),
        ])->values();

        // Weekday distribution
        $dayLabels    = ['', 'Mån', 'Tis', 'Ons', 'Tor', 'Fre', 'Lör', 'Sön'];
        $currentByDay = $currentBookings->groupBy(fn ($b) => $b->starts_at->isoWeekday());
        $weekdayStats = collect(range(1, 7))->map(fn ($d) => [
            'label' => $dayLabels[$d],
            'count' => $currentByDay->get($d, collect())->count(),
        ]);

        // Top 10 users
        $topUsers = $currentBookings
            ->filter(fn ($b) => $b->user !== null)
            ->groupBy('user_id')
            ->map(fn ($bs) => [
                'user'    => $bs->first()->user,
                'count'   => $bs->count(),
                'last_at' => $bs->max('starts_at'),
            ])
            ->sortByDesc('count')
            ->take(10)
            ->values();

        // Year selector range
        $earliest  = Booking::min('starts_at');
        $firstYear = $earliest ? Carbon::parse($earliest)->year : $year;
        $years     = array_reverse(range(max($firstYear, $year - 5), now()->year));

        // Chart data package
        $chartData = [
            'year'       => $year,
            'prevYear'   => $prevYear,
            'monthly'    => $monthly->values(),
            'rooms'      => $roomStats->map(fn ($r) => ['label' => $r['name'], 'count' => $r['count'], 'hours' => $r['hours']])->values(),
            'activities' => $activityStats,
            'weekdays'   => $weekdayStats->values(),
        ];

        return view('livewire.admin.booking.statistics', compact(
            'totalCurrent', 'totalPrev',
            'uniqueMembersCurrent', 'uniqueMembersPrev',
            'openCurrent', 'topActivity',
            'monthly', 'roomStats', 'activityStats', 'weekdayStats',
            'topUsers', 'years', 'chartData',
        ));
    }
}
