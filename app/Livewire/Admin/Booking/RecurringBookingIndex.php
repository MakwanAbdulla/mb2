<?php

namespace App\Livewire\Admin\Booking;

use App\Models\AuditLog;
use App\Models\Booking\RecurringBooking;
use App\Services\BookingOverviewService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class RecurringBookingIndex extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('bookings.manage'), 403);
    }

    public function delete(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('bookings.manage'), 403);

        $recurring = RecurringBooking::findOrFail($id);
        $title     = $recurring->title;
        $recurring->bookings()->delete();
        app(BookingOverviewService::class)->queueRefresh();
        $recurring->delete();
        AuditLog::record('recurring_booking_deleted', 'recurring_bookings', $title);
    }

    public function render()
    {
        $series = RecurringBooking::with('resource')
            ->withCount(['bookings as future_count' => fn ($q) => $q->where('starts_at', '>=', now())])
            ->orderBy('resource_id')
            ->orderBy('title')
            ->get();

        return view('livewire.admin.booking.recurring-booking-index', [
            'series' => $series,
        ]);
    }
}
