<?php

namespace App\Livewire\Booking;

use App\Mail\VenueApplicationReceived;
use App\Models\AuditLog;
use App\Models\Booking\BookingActivity;
use App\Models\Booking\BookingResource;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Booking\VenueApplication;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Url;
use Livewire\Component;

class VenueApplicationForm extends Component
{
    #[Url(as: 'kopiera')]
    public ?int $copyFrom = null;

    public string $eventName        = '';
    public string $responsibleName  = '';
    public string $responsiblePhone = '';
    public string $responsibleEmail = '';
    public string $startDate        = '';
    public string $startTime        = '09:00';
    public string $endDate          = '';
    public string $endTime          = '17:00';
    public int    $participants     = 0;
    public string $activity         = '';
    public bool   $needsOvernight   = false;
    public string $needsKey         = 'none';
    public bool   $hasAlcohol       = false;
    public string $eventType        = 'open';
    public string $eventTypeReason  = '';
    public array  $resourceIds      = [];
    public string $description      = '';
    public string $notes            = '';

    public bool    $success         = false;
    public ?string $conflictMessage = null;

    public function mount(): void
    {
        abort_unless(Auth::check(), 403);

        $user = Auth::user();
        $this->responsibleName  = $user->name;
        $this->responsibleEmail = $user->email ?? '';
        $this->startDate        = today()->format('Y-m-d');
        $this->endDate          = today()->format('Y-m-d');

        if ($this->copyFrom) {
            $source = VenueApplication::where('user_id', Auth::id())->find($this->copyFrom);
            if ($source) {
                $this->eventName        = $source->event_name;
                $this->description      = $source->description ?? '';
                $this->responsiblePhone = $source->responsible_phone ?? '';
                $this->participants     = $source->participants;
                $this->activity         = $source->activity ?? '';
                $this->needsOvernight   = $source->needs_overnight;
                $this->needsKey         = $source->needs_key;
                $this->hasAlcohol       = $source->has_alcohol;
                $this->eventType        = $source->event_type;
                $this->eventTypeReason  = $source->event_type_reason ?? '';
                $this->resourceIds      = array_map('strval', $source->resource_ids ?? []);
                $this->notes            = $source->notes ?? '';
            }
        }
    }

    public function updatedStartDate(string $value): void
    {
        if ($this->endDate === '' || $this->endDate < $value) {
            $this->endDate = $value;
        }
    }

    public function updatedHasAlcohol(): void
    {
        if ($this->hasAlcohol) {
            $this->eventType   = 'group';
            $this->resourceIds = BookingResource::where('active', true)->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        }
    }

    public function updatedEventType(): void
    {
        if ($this->eventType === 'open') $this->eventTypeReason = '';
    }

    public function save(): void
    {
        abort_unless(Auth::check(), 403);

        if ($this->hasAlcohol) {
            $this->eventType   = 'group';
            $this->resourceIds = BookingResource::where('active', true)->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        }

        $this->validate([
            'eventName'        => ['required', 'string', 'max:255'],
            'responsibleName'  => ['required', 'string', 'max:255'],
            'responsiblePhone' => ['nullable', 'string', 'max:50'],
            'responsibleEmail' => ['required', 'email', 'max:255'],
            'startDate'        => ['required', 'date'],
            'startTime'        => ['required', 'regex:/^([01]\d|2[0-3]):(00|30)$/', $this->startTimeRule()],
            'endDate'          => ['required', 'date', 'gte:startDate'],
            'endTime'          => ['required', 'regex:/^(([01]\d|2[0-3]):(00|30)|24:00)$/', $this->endTimeRule()],
            'participants'     => ['required', 'integer', 'min:1', 'max:100'],
            'activity'         => ['required', 'in:' . implode(',', BookingActivity::activeNames())],
            'needsKey'         => ['required', 'in:none,kiosk,styrelserum'],
            'eventType'        => ['required', 'in:group,open'],
            'eventTypeReason'  => ['nullable', 'string', 'max:1000'],
            'resourceIds'      => ['required', 'array', 'min:1'],
            'resourceIds.*'    => ['integer', 'exists:booking_resources,id'],
            'description'      => ['nullable', 'string', 'max:2000'],
            'notes'            => ['nullable', 'string', 'max:2000'],
        ], [
            'eventName.required'       => 'Ange arrangemangets namn.',
            'responsibleName.required' => 'Ange namn på ansvarig.',
            'responsibleEmail.required' => 'Ange e-postadress.',
            'responsibleEmail.email'   => 'Ange en giltig e-postadress.',
            'startDate.required'       => 'Ange startdatum.',
            'endDate.gte'              => 'Slutdatum måste vara samma dag eller senare än startdatum.',
            'participants.min'         => 'Ange antal deltagare.',
            'participants.max'         => 'Max 100 deltagare.',
            'activity.required'        => 'Välj en aktivitet.',
            'resourceIds.required'     => 'Välj minst en lokal/resurs.',
            'resourceIds.min'          => 'Välj minst en lokal/resurs.',
        ]);

        $maxMonths = (int) SystemSetting::get('venue_max_months_ahead', 11);
        $maxDate   = today()->addMonths($maxMonths)->format('Y-m-d');
        if ($this->startDate > $maxDate) {
            $this->addError('startDate', "Startdatum kan max vara {$maxMonths} månader framåt.");
            return;
        }

        $opensFrom = SystemSetting::get('booking_opens_from');
        if ($opensFrom && $this->startDate < $opensFrom) {
            $label = Carbon::parse($opensFrom)->locale('sv')->isoFormat('D MMMM YYYY');
            $this->addError('startDate', "Ansökningar kan tidigast avse datum från {$label}.");
            return;
        }

        $startsAt = Carbon::parse("{$this->startDate} {$this->startTime}");
        $endsAt   = $this->endTime === '24:00'
            ? Carbon::parse("{$this->endDate} 00:00")->addDay()
            : Carbon::parse("{$this->endDate} {$this->endTime}");

        if ($endsAt <= $startsAt) {
            $this->addError('endTime', 'Sluttiden måste vara efter starttiden.');
            return;
        }

        $conflicts = [];
        foreach ($this->resourceIds as $resourceId) {
            $resource = BookingResource::find($resourceId);
            if ($resource && ! $resource->isAvailable($startsAt, $endsAt)) {
                $conflicts[] = $resource->name;
            }
        }

        if (! empty($conflicts)) {
            $this->conflictMessage = 'Följande resurser är inte lediga den valda tiden: ' . implode(', ', $conflicts) . '.';
            return;
        }

        $this->conflictMessage = null;

        $application = VenueApplication::create([
            'user_id'           => Auth::id(),
            'event_name'        => $this->eventName,
            'description'       => $this->description ?: null,
            'responsible_name'  => $this->responsibleName,
            'responsible_phone' => $this->responsiblePhone ?: null,
            'responsible_email' => $this->responsibleEmail,
            'starts_at'         => $startsAt,
            'ends_at'           => $endsAt,
            'participants'      => $this->participants,
            'needs_overnight'   => $this->needsOvernight,
            'needs_key'         => $this->needsKey,
            'has_alcohol'       => $this->hasAlcohol,
            'event_type'        => $this->eventType,
            'event_type_reason' => $this->eventType === 'group' ? ($this->eventTypeReason ?: null) : null,
            'resource_ids'      => array_map('intval', $this->resourceIds),
            'activity'          => $this->activity,
            'notes'             => $this->notes ?: null,
            'status'            => 'pending',
        ]);

        AuditLog::record('venue_application_submitted', 'venue_applications',
            "{$this->eventName} {$startsAt->format('Y-m-d')}–{$endsAt->format('Y-m-d')}", $application);

        // Notify all admins
        $adminEmails = User::role('admin')->whereNotNull('email')->pluck('email');
        foreach ($adminEmails as $email) {
            Mail::to($email)->queue(new VenueApplicationReceived($application));
        }

        $this->success = true;
    }

    public function render()
    {
        $opensFrom = SystemSetting::get('booking_opens_from');
        $minDate   = ($opensFrom && $opensFrom > today()->format('Y-m-d')) ? $opensFrom : today()->format('Y-m-d');

        return view('livewire.booking.venue-application-form', [
            'resources'  => BookingResource::where('active', true)->orderBy('name')->get(),
            'activities' => BookingActivity::activeNames(),
            'minDate'    => $minDate,
            'opensFrom'  => $opensFrom,
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
}
