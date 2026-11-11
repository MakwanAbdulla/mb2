<?php

namespace App\Livewire\Admin\Booking;

use App\Mail\VenueApplicationDecision;
use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\Booking\VenueApplication;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

class VenueApplicationReview extends Component
{
    public VenueApplication $application;

    public string  $adminNotes   = '';
    public ?string $errorMessage = null;

    public function mount(VenueApplication $application): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('bookings.applications'), 403);

        $this->application = $application;
        $this->adminNotes  = $application->admin_notes ?? '';
    }

    public function approve(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('bookings.applications'), 403);
        abort_unless($this->application->status === 'pending', 403);

        $startsAt = $this->application->starts_at;
        $endsAt   = $this->application->ends_at;

        $conflicts = [];
        foreach ($this->application->resource_ids as $resourceId) {
            $resource = BookingResource::find($resourceId);
            if (! $resource) {
                continue;
            }
            if (! $resource->isAvailable($startsAt, $endsAt)) {
                $conflicts[] = $resource->name;
            }
        }

        if (! empty($conflicts)) {
            $this->errorMessage = 'Följande resurser är inte längre lediga: ' . implode(', ', $conflicts) . '.';
            return;
        }

        $this->errorMessage = null;
        $bookingIds = [];

        foreach ($this->application->resource_ids as $resourceId) {
            $resource = BookingResource::find($resourceId);
            if (! $resource) {
                continue;
            }

            $booking = Booking::create([
                'source_type'        => VenueApplication::class,
                'source_id'          => $this->application->id,
                'resource_id'        => $resource->id,
                'user_id'            => $this->application->user_id,
                'title'              => $this->application->event_name,
                'activity'           => $this->application->activity,
                'booking_type'       => $this->application->event_type,
                'participation_info' => $this->application->event_type === 'open'
                    ? $this->application->event_type_reason
                    : null,
                'notes'              => $this->application->notes,
                'starts_at'          => $startsAt,
                'ends_at'            => $endsAt,
                'status'             => 'confirmed',
                'exempt_from_limit'  => true,
            ]);

            $bookingIds[] = $booking->id;
        }

        $this->application->approve($bookingIds, $this->adminNotes ?: null);

        $this->application->refresh();

        AuditLog::record(
            'venue_application_approved',
            'venue_applications',
            "{$this->application->event_name} — {$this->application->responsible_name}",
            $this->application,
            array_filter(['admin_notes' => $this->adminNotes ?: null]) ?: null,
        );

        Mail::to($this->application->responsible_email)->queue(new VenueApplicationDecision($this->application));

        $this->redirect(route('booking.admin.venue-applications.index'));
    }

    public function reject(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('bookings.applications'), 403);
        abort_unless($this->application->status === 'pending', 403);

        $this->application->reject($this->adminNotes ?: null);

        $this->application->refresh();

        AuditLog::record(
            'venue_application_rejected',
            'venue_applications',
            "{$this->application->event_name} — {$this->application->responsible_name}",
            $this->application,
            array_filter(['admin_notes' => $this->adminNotes ?: null]) ?: null,
        );

        Mail::to($this->application->responsible_email)->queue(new VenueApplicationDecision($this->application));

        $this->redirect(route('booking.admin.venue-applications.index'));
    }

    public function render()
    {
        return view('livewire.admin.booking.venue-application-review', [
            'resources' => BookingResource::whereIn('id', $this->application->resource_ids)->get()->keyBy('id'),
        ]);
    }
}
