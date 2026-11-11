<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingStatisticsController extends Controller
{
    // GET /api/v1/admin/booking-statistics
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('bookings.statistics'), 403);

        $year     = $request->integer('year', now()->year);
        $prevYear = $year - 1;

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

        // Monthly breakdown
        $monthNames     = ['Jan', 'Feb', 'Mar', 'Apr', 'Maj', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];
        $currentByMonth = $currentBookings->groupBy(fn ($b) => (int) $b->starts_at->format('n'));
        $prevByMonth    = $prevBookings->groupBy(fn ($b) => (int) $b->starts_at->format('n'));
        $monthly        = collect(range(1, 12))->map(fn ($m) => [
            'month'   => $m,
            'label'   => $monthNames[$m - 1],
            'current' => $currentByMonth->get($m, collect())->count(),
            'prev'    => $prevByMonth->get($m, collect())->count(),
        ])->values();

        // By-resource breakdown
        $rooms         = BookingResource::where('active', true)->orderBy('name')->get();
        $currentByRoom = $currentBookings->groupBy('resource_id');
        $byResource    = $rooms->map(function ($room) use ($currentByRoom) {
            $bs       = $currentByRoom->get($room->id, collect());
            $count    = $bs->count();
            $totalMin = $bs->sum(fn ($b) => $b->starts_at->diffInMinutes($b->ends_at));
            $openCount = $bs->where('booking_type', 'open')->count();
            return [
                'resource_id' => $room->id,
                'name'        => $room->name,
                'count'       => $count,
                'hours'       => $count > 0 ? round($totalMin / 60, 1) : 0,
                'avg_min'     => $count > 0 ? (int) round($totalMin / $count) : 0,
                'open_pct'    => $count > 0 ? (int) round($openCount / $count * 100) : 0,
            ];
        })->values();

        // Activity breakdown
        $currentByActivity = $currentBookings->groupBy('activity')->map->count()->sortDesc();
        $prevByActivity    = $prevBookings->groupBy('activity')->map->count();
        $byActivity        = $currentByActivity->map(fn ($count, $act) => [
            'activity' => $act,
            'current'  => $count,
            'prev'     => (int) $prevByActivity->get($act, 0),
        ])->values();

        // Weekday distribution
        $dayLabels    = ['', 'Mån', 'Tis', 'Ons', 'Tor', 'Fre', 'Lör', 'Sön'];
        $currentByDay = $currentBookings->groupBy(fn ($b) => $b->starts_at->isoWeekday());
        $byWeekday    = collect(range(1, 7))->map(fn ($d) => [
            'iso_weekday' => $d,
            'label'       => $dayLabels[$d],
            'count'       => $currentByDay->get($d, collect())->count(),
        ])->values();

        // Top 10 users
        $topUsers = $currentBookings
            ->filter(fn ($b) => $b->user !== null)
            ->groupBy('user_id')
            ->map(fn ($bs) => [
                'user_id'   => $bs->first()->user_id,
                'user_name' => $bs->first()->user->name,
                'count'     => $bs->count(),
                'last_at'   => $bs->max('starts_at')?->toIso8601String(),
            ])
            ->sortByDesc('count')
            ->take(10)
            ->values();

        return response()->json([
            'data' => [
                'year'      => $year,
                'prev_year' => $prevYear,
                'kpis' => [
                    'total_bookings'         => $totalCurrent,
                    'total_bookings_prev'    => $totalPrev,
                    'unique_members'         => $uniqueMembersCurrent,
                    'unique_members_prev'    => $uniqueMembersPrev,
                    'open_bookings'          => $openCurrent,
                    'top_activity'           => $topActivity,
                ],
                'by_month'    => $monthly,
                'by_resource' => $byResource,
                'by_activity' => $byActivity,
                'by_weekday'  => $byWeekday,
                'top_users'   => $topUsers,
            ],
        ]);
    }
}
