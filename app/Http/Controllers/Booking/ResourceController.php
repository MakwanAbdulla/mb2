<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingCategory;
use App\Models\Booking\BookingResource;
use Illuminate\Support\Collection;

class ResourceController extends Controller
{
    public function index()
    {
        $categories = BookingCategory::with(['resources' => function ($q) {
            $q->where('active', true)->orderBy('name');
        }])
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn ($c) => $c->resources->isNotEmpty());

        $uncategorized = BookingResource::where('active', true)
            ->whereNull('category_id')
            ->orderBy('name')
            ->get();

        $bookingData = $this->loadBookingData();

        return view('booking.resources.index', compact('categories', 'uncategorized', 'bookingData'));
    }

    private function loadBookingData(): Collection
    {
        $now         = now();
        $resourceIds = BookingResource::where('active', true)->pluck('id');

        $current = Booking::whereIn('resource_id', $resourceIds)
            ->whereIn('status', ['confirmed', 'pending'])
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->get()
            ->keyBy('resource_id');

        $upcoming = Booking::whereIn('resource_id', $resourceIds)
            ->whereIn('status', ['confirmed', 'pending'])
            ->where('starts_at', '>', $now)
            ->orderBy('starts_at')
            ->get()
            ->groupBy('resource_id')
            ->map(fn ($bookings) => $bookings->take(2)->values());

        return $resourceIds->mapWithKeys(fn ($id) => [
            $id => [
                'current'  => $current->get($id),
                'upcoming' => $upcoming->get($id, collect()),
            ],
        ]);
    }
}
