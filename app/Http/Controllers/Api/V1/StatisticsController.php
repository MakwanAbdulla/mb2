<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Events\Event;
use App\Models\Events\EventRegistration;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\Shop\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    // GET /api/v1/admin/statistics/members
    public function members(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('statistics.view'), 403);

        $year = $request->integer('year', now()->year);

        $total     = Member::count();
        $active    = MembershipPeriod::where('year', $year)->where('status', 'active')->count();
        $byType    = MembershipPeriod::where('year', $year)
            ->where('status', 'active')
            ->selectRaw('membership_type, COUNT(*) as count')
            ->groupBy('membership_type')
            ->pluck('count', 'membership_type');

        $byMonth = MembershipPeriod::where('year', $year)
            ->where('status', 'active')
            ->whereNotNull('activated_at')
            ->selectRaw('MONTH(activated_at) as month, COUNT(*) as count')
            ->groupBy('month')
            ->pluck('count', 'month');

        return response()->json([
            'year'              => $year,
            'total_members'     => $total,
            'active_this_year'  => $active,
            'by_type'           => $byType,
            'activations_by_month' => $byMonth,
        ]);
    }

    // GET /api/v1/admin/statistics/events
    public function events(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('statistics.view'), 403);

        $year = $request->integer('year', now()->year);

        $total       = Event::whereYear('created_at', $year)->count();
        $published   = Event::where('status', 'published')->whereYear('created_at', $year)->count();
        $registrations = EventRegistration::whereYear('created_at', $year)->count();
        $checkedIn   = EventRegistration::where('checked_in', true)->whereYear('created_at', $year)->count();

        return response()->json([
            'year'              => $year,
            'total_events'      => $total,
            'published_events'  => $published,
            'total_registrations' => $registrations,
            'checked_in'        => $checkedIn,
        ]);
    }

    // GET /api/v1/admin/statistics/revenue
    public function revenue(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyPermission(['statistics.view', 'accounting.view']),
            403
        );

        $year = $request->integer('year', now()->year);

        $byMonth = Order::where('status', 'paid')
            ->whereYear('paid_at', $year)
            ->selectRaw('MONTH(paid_at) as month, SUM(total_ore) as total_ore, COUNT(*) as count')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($r) => [
                'month'     => $r->month,
                'total_ore' => $r->total_ore,
                'count'     => $r->count,
            ]);

        $total = $byMonth->sum('total_ore');

        return response()->json([
            'year'       => $year,
            'total_ore'  => $total,
            'by_month'   => $byMonth,
        ]);
    }
}
