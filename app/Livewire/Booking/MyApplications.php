<?php

namespace App\Livewire\Booking;

use App\Models\Booking\BookingResource;
use App\Models\Booking\VenueApplication;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class MyApplications extends Component
{
    use WithPagination;

    public string $filter = 'active';

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = VenueApplication::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc');

        if ($this->filter === 'active') {
            $query->whereIn('status', ['pending', 'approved']);
        }

        $applications = $query->paginate(10);

        $allResourceIds = $applications->flatMap(fn ($a) => $a->resource_ids ?? [])->unique()->values();
        $resourceNames  = BookingResource::whereIn('id', $allResourceIds)->pluck('name', 'id');

        return view('livewire.booking.my-applications', [
            'applications'  => $applications,
            'resourceNames' => $resourceNames,
        ]);
    }
}
