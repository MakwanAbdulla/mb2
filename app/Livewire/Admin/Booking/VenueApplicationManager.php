<?php

namespace App\Livewire\Admin\Booking;

use App\Models\Booking\VenueApplication;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class VenueApplicationManager extends Component
{
    use WithPagination;

    public string $filter = 'pending';

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('bookings.applications'), 403);
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = VenueApplication::with('user')->latest();

        if ($this->filter !== 'all') {
            $query->where('status', $this->filter);
        }

        return view('livewire.admin.booking.venue-application-manager', [
            'applications' => $query->paginate(15),
            'pendingCount' => VenueApplication::where('status', 'pending')->count(),
        ]);
    }
}
