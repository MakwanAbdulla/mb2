<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VenueApplicationResource;
use App\Models\Booking\VenueApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class VenueApplicationController extends Controller
{
    // GET /api/v1/venue-applications  — own applications
    public function index(Request $request): ResourceCollection
    {
        $user = $request->user();

        $apps = VenueApplication::query()
            ->when(
                ! $user->hasAnyPermission(['bookings.applications', 'bookings.manage']),
                fn ($q) => $q->where('user_id', $user->id)
            )
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('starts_at')
            ->paginate($request->integer('per_page', 25));

        return VenueApplicationResource::collection($apps);
    }

    // GET /api/v1/venue-applications/{application}
    public function show(Request $request, VenueApplication $venueApplication): VenueApplicationResource
    {
        $user = $request->user();
        abort_unless(
            $venueApplication->user_id === $user->id
            || $user->hasAnyPermission(['bookings.applications', 'bookings.manage']),
            403
        );

        return new VenueApplicationResource($venueApplication);
    }

    // POST /api/v1/admin/venue-applications/{application}/approve
    public function approve(Request $request, VenueApplication $venueApplication): VenueApplicationResource
    {
        abort_unless($request->user()->hasAnyPermission(['bookings.applications', 'bookings.manage']), 403);
        abort_unless($venueApplication->status === 'pending', 422, 'Application is not pending.');

        $data = $request->validate([
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        $venueApplication->approve([], $data['admin_notes'] ?? null);

        return new VenueApplicationResource($venueApplication->fresh());
    }

    // POST /api/v1/admin/venue-applications/{application}/reject
    public function reject(Request $request, VenueApplication $venueApplication): VenueApplicationResource
    {
        abort_unless($request->user()->hasAnyPermission(['bookings.applications', 'bookings.manage']), 403);
        abort_unless($venueApplication->status === 'pending', 422, 'Application is not pending.');

        $data = $request->validate([
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        $venueApplication->reject($data['admin_notes'] ?? null);

        return new VenueApplicationResource($venueApplication->fresh());
    }
}
