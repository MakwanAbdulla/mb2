<?php

namespace App\Livewire\Booking;

use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingActivity;
use App\Models\Booking\BookingResource;
use App\Models\Tag;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class BookingEditUser extends Component
{
    public Booking $booking;

    public string $resourceId       = '';
    public string $title            = '';
    public string $activity         = '';
    public string $bookingType      = 'group';
    public string $participationInfo = '';
    public string $notes            = '';
    /** @var array<int, string> */
    public array  $selectedTags     = [];
    public string $date             = '';
    public string $startTime        = '';
    public string $endTime          = '';

    public bool    $saved           = false;
    public ?string $conflictMessage = null;

    public function mount(Booking $booking): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless(Auth::user()->canBook(), 403, 'Lokalbokning kräver medlemskap.');
        abort_unless($booking->user_id === Auth::id(), 403);
        abort_unless($booking->starts_at->isFuture(), 403);
        abort_unless(! in_array($booking->status, ['cancelled', 'rejected']), 403);

        $this->booking           = $booking;
        $this->resourceId        = (string) $booking->resource_id;
        $this->title             = $booking->title;
        $this->activity          = $booking->activity;
        $this->bookingType       = $booking->booking_type;
        $this->participationInfo = $booking->participation_info ?? '';
        $this->notes             = $booking->notes ?? '';
        $this->selectedTags      = $booking->tags->pluck('name')->toArray();
        $this->date              = $booking->starts_at->format('Y-m-d');
        $this->startTime         = $booking->starts_at->format('H:i');
        $endsAt                  = $booking->ends_at;
        $this->endTime           = ($endsAt->hour === 0 && $endsAt->minute === 0 && ! $endsAt->isSameDay($booking->starts_at))
            ? '24:00'
            : $endsAt->format('H:i');
    }

    public function updatedBookingType(): void
    {
        if ($this->bookingType === 'group') $this->participationInfo = '';
    }

    public function save(): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless(Auth::user()->canBook(), 403, 'Lokalbokning kräver medlemskap.');
        abort_unless($this->booking->user_id === Auth::id(), 403);
        abort_unless($this->booking->starts_at->isFuture(), 403);

        $activities = implode(',', BookingActivity::activeNames());

        $this->validate([
            'resourceId'        => ['required', 'exists:booking_resources,id'],
            'title'             => ['required', 'string', 'max:255'],
            'activity'          => ["required", "in:{$activities}"],
            'bookingType'       => ['required', 'in:group,open'],
            'participationInfo' => ['nullable', 'string', 'max:1000'],
            'notes'             => ['nullable', 'string', 'max:1000'],
            'date'              => ['required', 'date', 'after:today'],
            'startTime'         => ['required', 'regex:/^([01]\d|2[0-3]):(00|30)$/'],
            'endTime'           => ['required', 'regex:/^(([01]\d|2[0-3]):(00|30)|24:00)$/'],
        ], [
            'title.required' => 'Ange en rubrik.',
            'date.after'     => 'Datumet måste vara i framtiden.',
        ]);

        if ($this->endTime <= $this->startTime) {
            $this->addError('endTime', 'Sluttiden måste vara efter starttiden.');
            return;
        }

        $resource = BookingResource::findOrFail($this->resourceId);
        $startsAt = Carbon::parse("{$this->date} {$this->startTime}");
        $endsAt   = $this->endTime === '24:00'
            ? Carbon::parse("{$this->date} 00:00")->addDay()
            : Carbon::parse("{$this->date} {$this->endTime}");

        $duration = $startsAt->diffInMinutes($endsAt);

        if ($duration < $resource->min_duration) {
            $this->addError('endTime', "Minsta bokningstid är {$resource->min_duration} minuter.");
            return;
        }

        if ($resource->max_duration && $duration > $resource->max_duration) {
            $this->addError('endTime', "Maximal bokningstid är {$resource->max_duration} minuter.");
            return;
        }

        if (! $resource->isAvailable($startsAt, $endsAt, $this->booking->id)) {
            $this->conflictMessage = "{$resource->name} är redan bokat {$startsAt->locale('sv')->isoFormat('D MMM')} {$this->startTime}–{$this->endTime}.";
            return;
        }

        $this->conflictMessage = null;
        $this->booking->fill([
            'resource_id'        => $this->resourceId,
            'title'              => $this->title,
            'activity'           => $this->activity,
            'booking_type'       => $this->bookingType,
            'participation_info' => $this->bookingType === 'open' ? $this->participationInfo : null,
            'notes'              => $this->notes ?: null,
            'starts_at'          => $startsAt,
            'ends_at'            => $endsAt,
        ]);

        $changes = [];
        foreach ($this->booking->getDirty() as $key => $new) {
            $changes[$key] = ['from' => (string) $this->booking->getOriginal($key), 'to' => (string) $new];
        }
        $this->booking->save();

        $tagIds = collect($this->selectedTags)
            ->map(fn (string $name) => Tag::findOrCreateByName(mb_strtolower(trim($name)))->id)
            ->all();
        $this->booking->tags()->sync($tagIds);

        $this->booking->refresh()->load('resource');
        AuditLog::record('booking_updated', 'bookings',
            "{$this->booking->resource->name} {$this->booking->starts_at->format('Y-m-d H:i')} — {$this->booking->title}",
            $this->booking, $changes ?: null);

        $this->saved = true;
    }

    public function render()
    {
        return view('livewire.booking.booking-edit-user', [
            'activities' => BookingActivity::activeNames(),
            'resources'  => BookingResource::where('active', true)->orderBy('name')->get(),
            'allTags'    => Tag::orderBy('name')->pluck('name')->toArray(),
        ]);
    }
}
