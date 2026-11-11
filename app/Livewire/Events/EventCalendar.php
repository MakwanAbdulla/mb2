<?php

namespace App\Livewire\Events;

use App\Models\Events\Event;
use App\Models\Events\EventOccurrence;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Evenemang — kalender')]
class EventCalendar extends Component
{
    #[Url(as: 'datum')]
    public string $date = '';

    #[Url(as: 'vy')]
    public string $view = 'week';

    public function mount(): void
    {
        if (! $this->date) {
            $this->date = today()->format('Y-m-d');
        }
        if (! in_array($this->view, ['day', 'week', 'list'])) {
            $this->view = 'week';
        }
    }

    public function previousDay(): void
    {
        $this->date = Carbon::parse($this->date)->subDay()->format('Y-m-d');
    }

    public function nextDay(): void
    {
        $this->date = Carbon::parse($this->date)->addDay()->format('Y-m-d');
    }

    public function goToToday(): void
    {
        $this->date = today()->format('Y-m-d');
    }

    public function previousWeek(): void
    {
        $this->date = Carbon::parse($this->date)->subWeek()->format('Y-m-d');
    }

    public function nextWeek(): void
    {
        $this->date = Carbon::parse($this->date)->addWeek()->format('Y-m-d');
    }

    public function restoreState(string $date, string $view): void
    {
        if ($date) {
            $this->date = $date;
        }
        if (in_array($view, ['day', 'week', 'list'])) {
            $this->view = $view;
        }
    }

    public function render()
    {
        $isAdmin     = Auth::check() && Auth::user()->isAdmin();
        $currentDate = Carbon::parse($this->date);

        $baseQuery = EventOccurrence::with(['event.organizerSection'])
            ->whereNull('cancelled_at')
            ->whereHas('event', function ($q) use ($isAdmin) {
                $q->whereIn('status', $isAdmin
                    ? [Event::STATUS_DRAFT, Event::STATUS_PUBLISHED, Event::STATUS_COMPLETED]
                    : [Event::STATUS_PUBLISHED]
                );
            });

        $listOccurrences = collect();
        $weekData        = collect();
        $weekDays        = collect();
        $weekStart       = null;
        $isCurrentWeek   = false;
        $dayOccurrences  = collect();

        if ($this->view === 'list') {
            $listOccurrences = (clone $baseQuery)
                ->where('starts_at', '<', $currentDate->copy()->endOfDay())
                ->where('ends_at',   '>', $currentDate->copy()->startOfDay())
                ->orderBy('starts_at')
                ->get();
        } elseif ($this->view === 'week') {
            $weekStart     = $currentDate->copy()->startOfWeek(Carbon::MONDAY);
            $weekDays      = collect(range(0, 6))->map(fn ($i) => $weekStart->copy()->addDays($i));
            $weekEnd       = $weekDays->last()->copy()->endOfDay();
            $isCurrentWeek = today()->between($weekStart, $weekDays->last());

            $weekData = (clone $baseQuery)
                ->where('starts_at', '<', $weekEnd)
                ->where('ends_at',   '>', $weekStart)
                ->orderBy('starts_at')
                ->get()
                ->flatMap(fn ($o) => $weekDays
                    ->filter(fn ($day) =>
                        $o->starts_at < $day->copy()->endOfDay() &&
                        $o->ends_at   > $day->copy()->startOfDay()
                    )
                    ->map(fn ($day) => ['date' => $day->format('Y-m-d'), 'occurrence' => $o])
                )
                ->groupBy('date')
                ->map(fn ($items) => $items->pluck('occurrence'));
        } else {
            $dayOccurrences = (clone $baseQuery)
                ->where('starts_at', '<', $currentDate->copy()->endOfDay())
                ->where('ends_at',   '>', $currentDate->copy()->startOfDay())
                ->orderBy('starts_at')
                ->get();
        }

        $holidays      = $this->swedishHolidays((int) $currentDate->format('Y'));
        $isToday       = $this->date === today()->format('Y-m-d');
        $parsedDate    = Carbon::parse($this->date);
        $canGoPrevWeek = true;
        $canGoNextWeek = true;
        $canGoPrevDay  = true;
        $canGoNextDay  = true;

        $ws = $weekStart ?? $parsedDate->copy()->startOfWeek(Carbon::MONDAY);

        return view('livewire.events.event-calendar', compact(
            'currentDate', 'isToday', 'isAdmin',
            'listOccurrences', 'weekData', 'weekDays', 'weekStart', 'isCurrentWeek',
            'dayOccurrences', 'holidays',
            'canGoPrevDay', 'canGoNextDay', 'canGoPrevWeek', 'canGoNextWeek',
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
