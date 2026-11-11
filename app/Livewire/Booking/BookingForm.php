<?php

namespace App\Livewire\Booking;

use App\Mail\BookingConfirmed;
use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingActivity;
use App\Models\Booking\BookingResource;
use App\Models\Organization\Section;
use App\Models\Organization\SectionMembership;
use App\Models\Tag;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Url;
use Livewire\Component;

class BookingForm extends Component
{
    public BookingResource $resource;

    #[Url(as: 'rum')]
    public ?string $resourceSlug = null;

    public bool $fixedResource = false;

    public string $title            = '';
    public string $description      = '';
    public string $activity         = '';
    public string $bookingType      = 'group';
    public string $participationInfo = '';
    public string $notes            = '';
    public string $date             = '';
    public string $startTime        = '09:00';
    public string $endTime          = '10:00';

    #[Url(as: 'kopiera')]
    public ?int $copyFrom = null;

    public bool   $bookAll          = false;
    public bool   $exemptFromLimit = false;
    public string $onBehalfUserId  = '';
    public ?int   $bookForSectionId = null;

    /** @var array<int, string> */
    public array $selectedTags = [];

    public bool    $success         = false;
    public ?string $conflictMessage = null;

    /** @var array<int, string> */
    public array $bookedRooms = [];

    /** @var array<int, array{id: int, name: string, slug: string}> */
    public array $alternatives = [];

    public function mount(?BookingResource $resource = null): void
    {
        if ($resource && $resource->exists) {
            $this->fixedResource = true;
            $this->resource      = $resource;
            $this->resourceSlug  = $resource->slug;
        } else {
            if (! $this->resourceSlug) {
                $this->resourceSlug = request()->query('rum');
            }
            if (! $this->resourceSlug) {
                $first = BookingResource::where('active', true)->orderBy('name')->first();
                $this->resourceSlug = $first?->slug ?? '';
            }
            $this->resource = BookingResource::where('slug', $this->resourceSlug)
                ->where('active', true)
                ->firstOrFail();
        }

        if (! $this->bookForSectionId && $sectionId = request()->query('section')) {
            $this->bookForSectionId = (int) $sectionId;
        }

        $this->date      = now()->addDay()->format('Y-m-d');
        $this->startTime = '09:00';
        $this->endTime   = '10:00';

        if (request()->filled('datum')) {
            $this->date = request('datum');
        }
        if (request()->filled('start')) {
            $this->startTime = request('start');
        }
        if (request()->filled('slut')) {
            $this->endTime = request('slut');
        }

        if ($this->copyFrom) {
            $original = Booking::with('tags')->find($this->copyFrom);
            if ($original && ($original->user_id === Auth::id() || Auth::user()->isAdmin())) {
                $this->title             = $original->title;
                $this->description       = $original->description ?? '';
                $this->activity          = $original->activity;
                $this->bookingType       = $original->booking_type;
                $this->participationInfo = $original->participation_info ?? '';
                $this->notes             = $original->notes ?? '';
                $this->startTime         = $original->starts_at->format('H:i');
                $this->endTime           = $this->formatEndTime($original->ends_at, $original->starts_at);
                $this->selectedTags      = $original->tags->pluck('name')->toArray();
            }
        }
    }

    public function updatedResourceSlug(string $slug): void
    {
        $resource = BookingResource::where('slug', $slug)->where('active', true)->first();
        if ($resource) {
            $this->resource        = $resource;
            $this->conflictMessage = null;
            $this->alternatives    = [];
            $this->checkAvailability();
        }
    }

    public function updatedStartTime(string $value): void
    {
        $h          = (int) substr($value, 0, 2);
        $m          = (int) substr($value, 3, 2);
        $endMinutes = min($h * 60 + $m + 60, 24 * 60);
        $eh         = intdiv($endMinutes, 60);
        $em         = $endMinutes % 60;
        $this->endTime = $eh === 24 ? '24:00' : sprintf('%02d:%02d', $eh, $em);
        $this->checkAvailability();
    }

    public function updatedEndTime(): void   { $this->checkAvailability(); }
    public function updatedDate(): void      { $this->checkAvailability(); }

    private function checkAvailability(): void
    {
        if (! $this->date || ! $this->startTime || ! $this->endTime) return;
        if ($this->endTime <= $this->startTime) return;

        $startsAt = Carbon::parse("{$this->date} {$this->startTime}");
        $endsAt   = $this->endTime === '24:00'
            ? Carbon::parse("{$this->date} 00:00")->addDay()
            : Carbon::parse("{$this->date} {$this->endTime}");

        if (! $this->resource->isAvailable($startsAt, $endsAt)) {
            $this->conflictMessage = "{$this->resource->name} är redan bokat {$startsAt->format('j M')} {$this->startTime}–{$this->endTime}.";
            $this->alternatives    = $this->findAlternatives($startsAt, $endsAt);
        } else {
            $this->conflictMessage = null;
            $this->alternatives    = [];
        }
    }

    public function canBook(): bool
    {
        return Auth::user()->canBook();
    }

    public function save(): void
    {
        if (! $this->canBook()) return;

        $user = Auth::user();

        if (! $user->isAdmin()) {
            if ($this->bookForSectionId) {
                $section    = Section::findOrFail($this->bookForSectionId);
                $isGroup    = $section->parent_id !== null;
                $defaultMax = $isGroup
                    ? (int) SystemSetting::get('sections.groups_max_bookings', 1)
                    : (int) SystemSetting::get('sections.max_bookings', 2);
                $maxBookings = $section->booking_max_override ?? $defaultMax;

                $activeCount = Booking::where('section_id', $section->id)
                    ->whereIn('status', ['pending', 'confirmed'])
                    ->where('starts_at', '>', now())
                    ->where('exempt_from_limit', false)
                    ->count();

                if ($activeCount >= $maxBookings) {
                    $noun = $maxBookings === 1 ? 'bokning' : 'bokningar';
                    $adj  = $maxBookings === 1 ? 'aktiv' : 'aktiva';
                    $this->conflictMessage = "{$section->name} har redan {$maxBookings} {$adj} {$noun}. Sektionen kan inte ha mer än {$maxBookings} {$adj} {$noun} åt gången.";
                    $this->alternatives    = [];
                    return;
                }
            } else {
                $maxBookings = $user->isSuperBooker()
                    ? (int) SystemSetting::get('max_bookings_super_booker', 3)
                    : (int) SystemSetting::get('max_bookings_per_user', 1);

                $activeCount = Booking::where('user_id', $user->id)
                    ->whereNull('section_id')
                    ->whereIn('status', ['pending', 'confirmed'])
                    ->where('starts_at', '>', now())
                    ->where('exempt_from_limit', false)
                    ->when(SystemSetting::get('open_bookings_exempt', false), fn ($q) => $q->where('booking_type', '!=', 'open'))
                    ->count();

                if ($activeCount >= $maxBookings) {
                    $noun = $maxBookings === 1 ? 'bokning' : 'bokningar';
                    $adj  = $maxBookings === 1 ? 'aktiv' : 'aktiva';
                    $this->conflictMessage = "Du har redan {$maxBookings} {$adj} {$noun}. Du kan inte ha mer än {$maxBookings} {$adj} {$noun} åt gången.";
                    $this->alternatives    = [];
                    return;
                }
            }
        }

        $bookingUserId = ($user->isAdmin() && $this->onBehalfUserId)
            ? (int) $this->onBehalfUserId
            : $user->id;

        $maxMonths = $user->isAdmin()
            ? min(11, (int) SystemSetting::get('admin_max_months_ahead', 11))
            : (int) SystemSetting::get('user_max_months_ahead', 6);
        $maxDate = now()->addMonths($maxMonths)->format('Y-m-d');

        $opensFrom   = SystemSetting::get('booking_opens_from');
        $allowSameDay = (bool) SystemSetting::get('allow_same_day_booking', false);

        $dateRules = ['required', 'date', $allowSameDay ? 'after_or_equal:today' : 'after:today', "before_or_equal:{$maxDate}"];
        if (! $user->isAdmin() && $opensFrom && $opensFrom > now()->format('Y-m-d')) {
            $dateRules[] = "after_or_equal:{$opensFrom}";
        }

        $activities = implode(',', BookingActivity::activeNames());

        $this->validate([
            'title'            => 'required|string|max:255',
            'activity'         => "required|in:{$activities}",
            'bookingType'      => 'required|in:group,open',
            'description'      => 'nullable|string|max:2000',
            'participationInfo' => 'nullable|string|max:1000',
            'date'             => $dateRules,
            'startTime'        => ['required', 'regex:/^([01]\d|2[0-3]):(00|30)$/', $this->startTimeRule()],
            'endTime'          => ['required', 'regex:/^(([01]\d|2[0-3]):(00|30)|24:00)$/', $this->endTimeRule()],
            'notes'            => 'nullable|string|max:1000',
        ], [
            'title.required'        => 'Ange en rubrik för bokningen.',
            'activity.required'     => 'Välj en aktivitet för bokningen.',
            'activity.in'           => 'Välj en giltig aktivitet.',
            'date.after'            => 'Det går inte att boka samma dag — rummen är tillgängliga för drop-in.',
            'date.before_or_equal'  => 'Du kan inte boka så långt fram i tiden.',
            'endTime.after'         => 'Sluttiden måste vara efter starttiden.',
        ]);

        if ($this->endTime <= $this->startTime) {
            $this->addError('endTime', 'Sluttiden måste vara efter starttiden.');
            return;
        }

        $startsAt = Carbon::parse("{$this->date} {$this->startTime}");
        $endsAt   = $this->endTime === '24:00'
            ? Carbon::parse("{$this->date} 00:00")->addDay()
            : Carbon::parse("{$this->date} {$this->endTime}");

        $duration = $startsAt->diffInMinutes($endsAt);

        if ($duration < $this->resource->min_duration) {
            $this->addError('endTime', "Minsta bokningstid är {$this->resource->min_duration} minuter.");
            return;
        }

        if ($this->resource->max_duration && $duration > $this->resource->max_duration) {
            $this->addError('endTime', "Maximal bokningstid är {$this->resource->max_duration} minuter.");
            return;
        }

        if ($this->bookAll && $user->isAdmin()) {
            $this->saveAllRooms($bookingUserId, $startsAt, $endsAt);
            return;
        }

        if (! $this->resource->isAvailable($startsAt, $endsAt)) {
            $this->conflictMessage = "{$this->resource->name} är redan bokat {$startsAt->format('j M')} {$this->startTime}–{$this->endTime}.";
            $this->alternatives    = $this->findAlternatives($startsAt, $endsAt);
            return;
        }

        $this->alternatives    = [];
        $this->conflictMessage = null;
        $this->bookedRooms     = [];

        $status  = $this->resource->requires_approval ? 'pending' : 'confirmed';
        $booking = Booking::create([
            'resource_id'        => $this->resource->id,
            'user_id'            => $bookingUserId,
            'section_id'         => $this->bookForSectionId,
            'title'              => $this->title,
            'description'        => $this->description ?: null,
            'activity'           => $this->activity,
            'booking_type'       => $this->bookingType,
            'participation_info' => $this->bookingType === 'open' ? $this->participationInfo : null,
            'notes'              => $this->notes,
            'starts_at'          => $startsAt,
            'ends_at'            => $endsAt,
            'status'             => $status,
            'exempt_from_limit'  => $user->isAdmin() && $this->exemptFromLimit,
        ]);

        if (! empty($this->selectedTags)) {
            $tagIds = collect($this->selectedTags)
                ->map(fn (string $name) => Tag::findOrCreateByName(mb_strtolower(trim($name)))->id)
                ->all();
            $booking->tags()->sync($tagIds);
        }

        $booking->load('resource');
        AuditLog::record(
            'booking_created', 'bookings',
            "{$booking->resource->name} {$booking->starts_at->format('Y-m-d')} {$this->startTime}–{$this->endTime} — {$booking->title}",
            $booking,
        );

        $bookingUser = User::find($bookingUserId);
        if ($bookingUser?->email) {
            Mail::to($bookingUser->email)->queue(new BookingConfirmed($booking));
        }

        $this->reset(['title', 'description', 'activity', 'bookAll', 'exemptFromLimit', 'bookingType', 'participationInfo', 'notes', 'conflictMessage', 'alternatives', 'selectedTags']);
        $this->success = true;
    }

    private function saveAllRooms(int $bookingUserId, Carbon $startsAt, Carbon $endsAt): void
    {
        $resources  = BookingResource::where('active', true)->where('category_id', $this->resource->category_id)->get();
        $booked     = [];
        $conflicted = [];

        foreach ($resources as $r) {
            if ($r->isAvailable($startsAt, $endsAt)) {
                $b = Booking::create([
                    'resource_id'        => $r->id,
                    'user_id'            => $bookingUserId,
                    'title'              => $this->title,
                    'activity'           => $this->activity,
                    'booking_type'       => $this->bookingType,
                    'participation_info' => $this->bookingType === 'open' ? $this->participationInfo : null,
                    'notes'              => $this->notes,
                    'starts_at'          => $startsAt,
                    'ends_at'            => $endsAt,
                    'status'             => 'confirmed',
                ]);
                AuditLog::record('booking_created', 'bookings',
                    "{$r->name} {$startsAt->format('Y-m-d')} {$this->startTime}–{$this->endTime} — {$this->title}", $b);
                $booked[] = $r->name;
            } else {
                $conflicted[] = $r->name;
            }
        }

        $this->bookedRooms     = $booked;
        $this->conflictMessage = ! empty($conflicted)
            ? 'Konflikt – följande rum hade redan bokningar och hoppades över: ' . implode(', ', $conflicted) . '.'
            : null;
        $this->alternatives = [];
        $this->reset(['title', 'activity', 'bookAll', 'bookingType', 'participationInfo', 'notes']);
        $this->success = true;
    }

    /** @return array<int, array{id: int, name: string, slug: string}> */
    private function findAlternatives(Carbon $startsAt, Carbon $endsAt): array
    {
        return BookingResource::where('active', true)
            ->where('id', '!=', $this->resource->id)
            ->where('category_id', $this->resource->category_id)
            ->get()
            ->filter(fn (BookingResource $r) => $r->isAvailable($startsAt, $endsAt))
            ->map(fn (BookingResource $r) => ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug])
            ->values()
            ->toArray();
    }

    public function render()
    {
        $user      = Auth::user();
        $maxMonths = $user->isAdmin()
            ? min(11, (int) SystemSetting::get('admin_max_months_ahead', 11))
            : (int) SystemSetting::get('user_max_months_ahead', 6);

        $opensFrom = SystemSetting::get('booking_opens_from');
        $minDate   = (! $user->isAdmin() && $opensFrom && $opensFrom > now()->format('Y-m-d'))
            ? $opensFrom
            : now()->addDay()->format('Y-m-d');

        $members = $user->isAdmin() ? User::orderBy('name')->get() : collect();
        $rooms   = $this->fixedResource ? collect() : BookingResource::where('active', true)->orderBy('name')->get();

        $bookableSections = collect();
        if (SystemSetting::get('sections.room_booking_enabled', false) && $user->member) {
            $bookableSections = Section::where('can_book_rooms', true)
                ->whereHas('memberships', fn ($q) => $q
                    ->where('member_id', $user->member->id)
                    ->where('status', SectionMembership::STATUS_ACTIVE)
                )
                ->orderBy('name')
                ->get();
        }

        return view('livewire.booking.booking-form', [
            'maxDate'          => now()->addMonths($maxMonths)->format('Y-m-d'),
            'minDate'          => $minDate,
            'opensFrom'        => $opensFrom,
            'members'          => $members,
            'activities'       => BookingActivity::activeNames(),
            'rooms'            => $rooms,
            'allTags'          => Tag::orderBy('name')->pluck('name')->toArray(),
            'bookableSections' => $bookableSections,
        ]);
    }

    private function startTimeRule(): \Closure
    {
        return function ($attr, $value, $fail) {
            $min = SystemSetting::get('booking_start_time', '00:00');
            $max = SystemSetting::get('booking_end_time', '24:00');
            if ($value < $min) $fail("Starttiden kan inte vara före {$min}.");
            if ($value >= $max) $fail("Starttiden måste vara före {$max}.");
        };
    }

    private function endTimeRule(): \Closure
    {
        return function ($attr, $value, $fail) {
            $max = SystemSetting::get('booking_end_time', '24:00');
            if ($value > $max) $fail("Sluttiden kan inte vara efter {$max}.");
        };
    }

    private function formatEndTime(Carbon $endsAt, Carbon $startsAt): string
    {
        if ($endsAt->hour === 0 && $endsAt->minute === 0 && ! $endsAt->isSameDay($startsAt)) {
            return '24:00';
        }
        return $endsAt->format('H:i');
    }
}
