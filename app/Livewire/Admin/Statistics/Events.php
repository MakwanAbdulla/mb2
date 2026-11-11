<?php

namespace App\Livewire\Admin\Statistics;

use App\Models\Events\Event;
use App\Models\Events\EventOccurrence;
use App\Models\Events\EventRegistration;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

class Events extends Component
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

        // Events published/completed this year (based on first occurrence)
        $events = Event::with('occurrences')
            ->whereIn('status', [Event::STATUS_PUBLISHED, Event::STATUS_COMPLETED])
            ->get(['id', 'title', 'status', 'visibility']);

        // Filter events that had occurrences this year
        $eventsThisYear = $events->filter(function ($event) use ($yearStart, $yearEnd) {
            return $event->occurrences->some(
                fn ($o) => $o->starts_at >= $yearStart && $o->starts_at <= $yearEnd
            );
        });

        $eventsLastYear = $events->filter(function ($event) use ($prevStart, $prevEnd) {
            return $event->occurrences->some(
                fn ($o) => $o->starts_at >= $prevStart && $o->starts_at <= $prevEnd
            );
        });

        // Confirmed registrations for events that had occurrences in date range
        $eventIdsThisYear = EventOccurrence::whereBetween('starts_at', [$yearStart, $yearEnd])
            ->pluck('event_id')->unique();
        $eventIdsLastYear = EventOccurrence::whereBetween('starts_at', [$prevStart, $prevEnd])
            ->pluck('event_id')->unique();

        $regsThisYear = EventRegistration::whereIn('event_id', $eventIdsThisYear)
            ->where('status', EventRegistration::STATUS_CONFIRMED)
            ->get(['id', 'event_id', 'created_at']);

        $regsLastYear = EventRegistration::whereIn('event_id', $eventIdsLastYear)
            ->where('status', EventRegistration::STATUS_CONFIRMED)
            ->count();

        // KPIs
        $totalEvents      = $eventsThisYear->count();
        $totalEventsPrev  = $eventsLastYear->count();
        $totalRegs        = $regsThisYear->count();
        $avgRegsPerEvent  = $totalEvents > 0 ? round($totalRegs / $totalEvents, 1) : 0;

        // Monthly breakdown
        $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'Maj', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];
        $regsByMonth = $regsThisYear->groupBy(fn ($r) => (int) $r->created_at->format('n'));

        // Occurrences per month this year
        $occurrencesByMonth = EventOccurrence::whereIn('event_id', $eventIdsThisYear)
            ->whereBetween('starts_at', [$yearStart, $yearEnd])
            ->get(['id', 'starts_at'])
            ->groupBy(fn ($o) => (int) $o->starts_at->format('n'));

        $monthly = collect(range(1, 12))->map(fn ($m) => [
            'label'       => $monthNames[$m - 1],
            'occurrences' => $occurrencesByMonth->get($m, collect())->count(),
            'regs'        => $regsByMonth->get($m, collect())->count(),
        ]);

        // Top events by registration count
        $topEvents = $regsThisYear->groupBy('event_id')
            ->map(fn ($regs, $eventId) => [
                'title' => $events->firstWhere('id', $eventId)?->title ?? '—',
                'count' => $regs->count(),
            ])
            ->sortByDesc('count')
            ->take(10)
            ->values();

        // Year selector
        $earliest  = EventOccurrence::min('starts_at');
        $firstYear = $earliest ? Carbon::parse($earliest)->year : $year;
        $years     = array_reverse(range(max($firstYear, $year - 5), now()->year));

        return view('livewire.admin.statistics.events', compact(
            'year', 'prevYear',
            'totalEvents', 'totalEventsPrev',
            'totalRegs', 'regsLastYear', 'avgRegsPerEvent',
            'monthly', 'topEvents',
            'years',
        ));
    }
}
