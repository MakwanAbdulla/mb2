<?php

namespace App\Livewire\Booking;

use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Booking\VenueApplication;
use App\Services\BookingOverviewService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class MyBookings extends Component
{
    use WithPagination;

    public string $filter             = 'upcoming';
    public string $search             = '';
    public ?int   $venueApplicationId = null;

    public function mount(): void
    {
        $this->authorizeBooking();
    }

    public function cancel(int $bookingId): void
    {
        $this->authorizeBooking();

        $booking = Booking::where('user_id', Auth::id())->findOrFail($bookingId);

        if ($booking->starts_at->isPast()) return;

        $application = VenueApplication::where('status', 'approved')
            ->get(['id', 'booking_ids'])
            ->first(fn ($a) => is_array($a->booking_ids) && in_array($bookingId, $a->booking_ids));

        if ($application) {
            $this->venueApplicationId = $application->id;
            return;
        }

        $booking->update(['status' => 'cancelled']);
        $booking->load('resource');
        AuditLog::record('booking_cancelled', 'bookings',
            "{$booking->resource->name} {$booking->starts_at->format('Y-m-d H:i')} — {$booking->title}", $booking);

        $this->dispatch('booking-cancelled');
    }

    public function cancelVenueApplication(): void
    {
        $this->authorizeBooking();

        if (! $this->venueApplicationId) return;

        $application = VenueApplication::where('user_id', Auth::id())
            ->where('status', 'approved')
            ->findOrFail($this->venueApplicationId);

        if (is_array($application->booking_ids)) {
            Booking::whereIn('id', $application->booking_ids)
                ->where('starts_at', '>', now())
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->update(['status' => 'cancelled']);
            app(BookingOverviewService::class)->queueRefresh();
        }

        $application->update(['status' => 'cancelled']);
        AuditLog::record('venue_application_cancelled', 'venue_applications',
            "Ansökan #{$application->id} — {$application->event_name}", $application);

        $this->venueApplicationId = null;
        $this->dispatch('booking-cancelled');
    }

    public function dismissCancelBlock(): void { $this->venueApplicationId = null; }

    public function toggleReminders(): void
    {
        $user = Auth::user();
        $user->update(['reminder_emails' => ! $user->reminder_emails]);
    }

    private function authorizeBooking(): void
    {
        abort_unless(Auth::user()?->canBook(), 403, 'Lokalbokning kräver medlemskap.');
    }

    public function updatedFilter(): void { $this->resetPage(); }
    public function updatedSearch(): void { $this->resetPage(); }

    public function render()
    {
        $query = Booking::with('resource.category')
            ->forUser(Auth::id())
            ->orderBy('starts_at', $this->filter === 'past' ? 'desc' : 'asc');

        if ($this->filter === 'upcoming') {
            $query->where('starts_at', '>=', now())->whereNotIn('status', ['cancelled', 'rejected']);
        } elseif ($this->filter === 'past') {
            $query->where('starts_at', '<', now());
        }

        if ($this->search !== '') {
            $search = '%' . $this->search . '%';
            $query->where(fn ($q) => $q->where('title', 'like', $search)
                ->orWhereHas('resource', fn ($r) => $r->where('name', 'like', $search)));
        }

        return view('livewire.booking.my-bookings', [
            'bookings' => $query->paginate(10),
        ]);
    }
}
