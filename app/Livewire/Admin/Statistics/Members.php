<?php

namespace App\Livewire\Admin\Statistics;

use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

class Members extends Component
{
    #[Url(as: 'ar')]
    public int $year = 0;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('statistics.view'), 403);
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

        $snapshotDate = $year === now()->year ? now() : $yearEnd;

        $allMembers = Member::whereNotIn('status', [Member::STATUS_DELETED])
            ->get(['id', 'status', 'membership_type', 'date_of_birth', 'created_at']);
        $activeNow  = $allMembers->where('status', Member::STATUS_ACTIVE);

        // New members (created this year)
        $newThisYear = $allMembers->filter(fn ($m) => $m->created_at >= $yearStart && $m->created_at <= $yearEnd)->count();
        $newPrevYear = $allMembers->filter(fn ($m) => $m->created_at >= $prevStart && $m->created_at <= $prevEnd)->count();

        // Status breakdown
        $statusLabels = [
            Member::STATUS_ACTIVE   => 'Aktiv',
            Member::STATUS_INACTIVE => 'Inaktiv',
            Member::STATUS_EXPIRED  => 'Utgången',
            Member::STATUS_PENDING  => 'Väntar',
            Member::STATUS_DECEASED => 'Avliden',
        ];
        $statusStats = collect($statusLabels)->map(fn ($label, $status) => [
            'label' => $label,
            'count' => $allMembers->where('status', $status)->count(),
        ])->values();

        // Membership type breakdown (active members only)
        $typeLabels = [
            Member::TYPE_SINGLE   => 'Enskild',
            Member::TYPE_FAMILY   => 'Familj',
            Member::TYPE_YOUTH    => 'Ungdom',
            Member::TYPE_CHILD    => 'Barn',
            Member::TYPE_STUDENT  => 'Student',
            Member::TYPE_SENIOR   => 'Senior',
            Member::TYPE_HONORARY => 'Hedersmedlem',
        ];
        $typeStats = collect($typeLabels)->map(fn ($label, $type) => [
            'label' => $label,
            'count' => $activeNow->where('membership_type', $type)->count(),
        ])->filter(fn ($t) => $t['count'] > 0)->values();

        // Age distribution (active members with date_of_birth)
        $withAge    = $activeNow->filter(fn ($m) => $m->date_of_birth !== null);
        $ageBuckets = [
            'Under 18' => [0, 17],
            '18–25'    => [18, 25],
            '26–35'    => [26, 35],
            '36–50'    => [36, 50],
            '51–65'    => [51, 65],
            'Över 65'  => [66, 150],
        ];
        $ageStats = collect($ageBuckets)->map(function ($range, $label) use ($withAge) {
            $count = $withAge->filter(function ($m) use ($range) {
                $age = Carbon::parse($m->date_of_birth)->age;
                return $age >= $range[0] && $age <= $range[1];
            })->count();
            return ['label' => $label, 'count' => $count];
        })->values();

        // Monthly growth: new members (created_at) per month this year vs last
        $monthNames     = ['Jan', 'Feb', 'Mar', 'Apr', 'Maj', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];
        $newByMonth     = $allMembers->filter(fn ($m) => $m->created_at >= $yearStart && $m->created_at <= $yearEnd)
            ->groupBy(fn ($m) => (int) $m->created_at->format('n'));
        $newPrevByMonth = $allMembers->filter(fn ($m) => $m->created_at >= $prevStart && $m->created_at <= $prevEnd)
            ->groupBy(fn ($m) => (int) $m->created_at->format('n'));
        $monthly = collect(range(1, 12))->map(fn ($m) => [
            'label'   => $monthNames[$m - 1],
            'current' => $newByMonth->get($m, collect())->count(),
            'prev'    => $newPrevByMonth->get($m, collect())->count(),
        ]);

        // Seasonality: new members by calendar month, summed across all years
        // (answers "when in the year do we get new members", independent of yearly trend)
        $seasonalityByMonth = $allMembers->groupBy(fn ($m) => (int) $m->created_at->format('n'));
        $seasonalityYears   = $allMembers->min('created_at')?->year ?? $year;
        $seasonality        = collect(range(1, 12))->map(fn ($m) => [
            'label' => $monthNames[$m - 1],
            'count' => $seasonalityByMonth->get($m, collect())->count(),
        ]);

        // Active membership periods this year (renewals)
        $renewals = MembershipPeriod::where('year', $year)
            ->where('status', MembershipPeriod::STATUS_ACTIVE)
            ->count();

        $totalActive = $activeNow->count();

        // ── Över tid ────────────────────────────────────────────────────────────
        // Total active periods per year, new members (first-ever year), churned (had period Y-1 but not Y)
        $firstPeriodYear = (int) MembershipPeriod::min('year');
        $rangeStart      = $firstPeriodYear > 0 ? max($firstPeriodYear, now()->year - 9) : now()->year;
        $rangeYears      = range($rangeStart, now()->year);

        // member_id → min year of any active/expired period (first membership year)
        $firstYearByMember = MembershipPeriod::whereIn('status', [MembershipPeriod::STATUS_ACTIVE, MembershipPeriod::STATUS_EXPIRED])
            ->select('member_id', DB::raw('MIN(year) as first_year'))
            ->groupBy('member_id')
            ->pluck('first_year', 'member_id');

        // active member IDs per year
        $activeByYear = MembershipPeriod::where('status', MembershipPeriod::STATUS_ACTIVE)
            ->whereIn('year', $rangeYears)
            ->select('year', 'member_id')
            ->get()
            ->groupBy('year')
            ->map(fn ($rows) => $rows->pluck('member_id')->flip()); // flip → keyed by member_id for fast lookup

        $overTime = collect($rangeYears)->map(function ($y) use ($activeByYear, $firstYearByMember) {
            $ids    = $activeByYear->get($y, collect());
            $total  = $ids->count();

            // New: first-ever active period is this year
            $new = $ids->keys()->filter(fn ($memberId) => ($firstYearByMember[$memberId] ?? null) === $y)->count();

            // Churned: had active period last year, no active period this year
            $prevIds = $activeByYear->get($y - 1, collect());
            $churned = $prevIds->keys()->filter(fn ($memberId) => ! $ids->has($memberId))->count();

            return ['year' => $y, 'total' => $total, 'new' => $new, 'churned' => $churned];
        });

        // Year selector (for the per-year breakdown sections)
        $earliest  = Member::min('created_at');
        $firstYear = $earliest ? Carbon::parse($earliest)->year : $year;
        $years     = array_reverse(range(max($firstYear, $year - 5), now()->year));

        return view('livewire.admin.statistics.members', compact(
            'year', 'prevYear',
            'totalActive', 'newThisYear', 'newPrevYear', 'renewals',
            'statusStats', 'typeStats', 'ageStats', 'monthly',
            'years', 'overTime', 'seasonality', 'seasonalityYears',
        ));
    }
}
