<?php

namespace App\Livewire\Admin\Booking;

use App\Mail\BookingStatusChanged;
use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingActivity;
use App\Models\Booking\BookingResource;
use App\Models\Tag;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

class BookingEdit extends Component
{
    public int    $bookingId        = 0;
    public string $userId           = '';
    public string $resourceId       = '';
    public string $title            = '';
    public string $activity         = '';
    public string $bookingType      = 'group';
    public string $participationInfo = '';
    public string $notes            = '';
    public string $date             = '';
    public string $startTime        = '';
    public string $endTime          = '';
    public string $status           = '';
    public bool   $exemptFromLimit  = false;
    /** @var array<int, string> */
    public array  $selectedTags     = [];

    public bool    $saved           = false;
    public ?string $conflictMessage = null;
    private string $originalStatus  = '';

    public function mount(Booking $booking): void
    {
        abort_unless(Auth::check() && Auth::user()->isAdmin(), 403);

        $this->bookingId         = $booking->id;
        $this->userId            = (string) $booking->user_id;
        $this->resourceId        = (string) $booking->resource_id;
        $this->title             = $booking->title;
        $this->activity          = $booking->activity;
        $this->bookingType       = $booking->booking_type;
        $this->participationInfo = $booking->participation_info ?? '';
        $this->notes             = $booking->notes ?? '';
        $this->date              = $booking->starts_at->format('Y-m-d');
        $this->startTime         = $booking->starts_at->format('H:i');
        $endsAt                  = $booking->ends_at;
        $this->endTime           = ($endsAt->hour === 0 && $endsAt->minute === 0 && ! $endsAt->isSameDay($booking->starts_at))
            ? '24:00' : $endsAt->format('H:i');
        $this->status            = $booking->status;
        $this->exemptFromLimit   = $booking->exempt_from_limit;
        $this->originalStatus    = $booking->status;
        $this->selectedTags      = $booking->tags->pluck('name')->toArray();
    }

    public function updatedBookingType(): void
    {
        if ($this->bookingType === 'group') $this->participationInfo = '';
    }

    public function save(): void
    {
        abort_unless(Auth::user()->isAdmin(), 403);

        $activities = implode(',', BookingActivity::activeNames());

        $this->validate([
            'userId'            => ['required', 'exists:users,id'],
            'resourceId'        => ['required', 'exists:booking_resources,id'],
            'title'             => ['required', 'string', 'max:255'],
            'activity'          => ["required", "in:{$activities}"],
            'bookingType'       => ['required', 'in:group,open'],
            'participationInfo' => ['nullable', 'string', 'max:1000'],
            'date'              => ['required', 'date'],
            'startTime'         => ['required', 'regex:/^([01]\d|2[0-3]):(00|30)$/'],
            'endTime'           => ['required', 'regex:/^(([01]\d|2[0-3]):(00|30)|24:00)$/'],
            'status'            => ['required', 'in:pending,confirmed,cancelled,rejected'],
            'exemptFromLimit'   => ['boolean'],
            'notes'             => ['nullable', 'string', 'max:1000'],
        ], ['endTime.after' => 'Sluttiden måste vara efter starttiden.']);

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
            $this->addError('endTime', "Minsta bokningstid är {$resource->min_duration} minuter."); return;
        }
        if ($resource->max_duration && $duration > $resource->max_duration) {
            $this->addError('endTime', "Maximal bokningstid är {$resource->max_duration} minuter."); return;
        }
        if (! $resource->isAvailable($startsAt, $endsAt, $this->bookingId)) {
            $this->conflictMessage = "{$resource->name} är redan bokat {$startsAt->locale('sv')->isoFormat('D MMM')} {$this->startTime}–{$this->endTime}.";
            return;
        }

        $this->conflictMessage = null;
        $oldStatus = $this->originalStatus;
        $booking   = Booking::findOrFail($this->bookingId);
        $booking->fill([
            'user_id'            => $this->userId,
            'resource_id'        => $this->resourceId,
            'title'              => $this->title,
            'activity'           => $this->activity,
            'booking_type'       => $this->bookingType,
            'participation_info' => $this->bookingType === 'open' ? $this->participationInfo : null,
            'notes'              => $this->notes ?: null,
            'starts_at'          => $startsAt,
            'ends_at'            => $endsAt,
            'status'             => $this->status,
            'exempt_from_limit'  => $this->exemptFromLimit,
        ]);
        $changes = [];
        foreach ($booking->getDirty() as $key => $new) {
            $changes[$key] = ['from' => (string) $booking->getOriginal($key), 'to' => (string) $new];
        }
        $booking->save();

        $tagIds = collect($this->selectedTags)
            ->map(fn (string $name) => Tag::findOrCreateByName(mb_strtolower(trim($name)))->id)
            ->all();
        $booking->tags()->sync($tagIds);
        $booking->refresh()->load('resource');

        AuditLog::record('booking_updated', 'bookings',
            "{$booking->resource->name} {$booking->starts_at->format('Y-m-d H:i')} — {$booking->title}",
            $booking, $changes ?: null);

        if ($oldStatus !== $this->status && in_array($this->status, ['confirmed', 'cancelled', 'rejected'])) {
            $bookingUser = $booking->user;
            if ($bookingUser?->email) {
                Mail::to($bookingUser->email)->queue(new BookingStatusChanged($booking, $oldStatus));
            }
        }

        $this->originalStatus = $this->status;
        $this->saved = true;
    }

    public function render()
    {
        return view('livewire.admin.booking.booking-edit', [
            'users'      => User::orderBy('name')->get(),
            'resources'  => BookingResource::where('active', true)->orderBy('name')->get(),
            'activities' => BookingActivity::activeNames(),
            'allTags'    => Tag::orderBy('name')->pluck('name')->toArray(),
        ]);
    }
}
