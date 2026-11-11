<?php

namespace App\Livewire\Admin\Booking;

use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Services\BookingOverviewService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class BookingManager extends Component
{
    use WithPagination;

    public string $filter   = 'upcoming';
    public array  $selected = [];

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('bookings.manage'), 403);
    }

    public function updatedFilter(): void { $this->selected = []; $this->resetPage(); }

    public function bulkApprove(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('bookings.manage'), 403);
        $count = Booking::whereIn('id', $this->selected)->where('status', 'pending')->update(['status' => 'confirmed']);
        app(BookingOverviewService::class)->queueRefresh();
        AuditLog::record('booking_bulk_approved', 'bookings', "{$count} bokningar godkända");
        $this->selected = [];
    }

    public function bulkReject(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('bookings.manage'), 403);
        $count = Booking::whereIn('id', $this->selected)->where('status', 'pending')->update(['status' => 'rejected']);
        app(BookingOverviewService::class)->queueRefresh();
        AuditLog::record('booking_bulk_rejected', 'bookings', "{$count} bokningar nekade");
        $this->selected = [];
    }

    public function bulkCancel(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('bookings.manage'), 403);
        $count = Booking::whereIn('id', $this->selected)
            ->where('starts_at', '>', now())
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->update(['status' => 'cancelled']);
        app(BookingOverviewService::class)->queueRefresh();
        AuditLog::record('booking_bulk_cancelled', 'bookings', "{$count} bokningar avbokade");
        $this->selected = [];
    }

    public function delete(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('bookings.manage'), 403);
        $booking = Booking::with('resource')->findOrFail($id);
        if ($booking->ends_at->isPast()) return;
        $subject = ($booking->resource?->name ?? '–') . ' ' . $booking->starts_at->format('Y-m-d');
        if ($booking->starts_at->isPast()) {
            $booking->update(['ends_at' => now()]);
            AuditLog::record('booking_deleted', 'bookings', $subject . ' (avkortad)', $booking);
            return;
        }
        $booking->delete();
        AuditLog::record('booking_deleted', 'bookings', $subject);
    }

    public function approve(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('bookings.manage'), 403);
        $booking = Booking::with('resource')->findOrFail($id);
        $booking->update(['status' => 'confirmed']);
        AuditLog::record(
            'booking_approved', 'bookings',
            ($booking->resource?->name ?? '–') . ' ' . $booking->starts_at->format('Y-m-d'),
            $booking,
        );
    }

    public function reject(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('bookings.manage'), 403);
        $booking = Booking::with('resource')->findOrFail($id);
        $booking->update(['status' => 'rejected']);
        AuditLog::record(
            'booking_rejected', 'bookings',
            ($booking->resource?->name ?? '–') . ' ' . $booking->starts_at->format('Y-m-d'),
            $booking,
        );
    }

    public function render()
    {
        $query = Booking::with(['user', 'resource', 'section'])->orderBy('starts_at', $this->filter === 'past' ? 'desc' : 'asc');

        match ($this->filter) {
            'upcoming' => $query->where('starts_at', '>=', now())->whereNotIn('status', ['cancelled', 'rejected']),
            'past'     => $query->where('starts_at', '<', now()),
            'pending'  => $query->where('status', 'pending'),
            default    => null,
        };

        return view('livewire.admin.booking.booking-manager', [
            'bookings' => $query->paginate(25),
        ]);
    }
}
