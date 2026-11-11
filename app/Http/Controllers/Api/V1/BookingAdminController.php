<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\BookingResourceResource;
use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource as ResourceModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Str;

class BookingAdminController extends Controller
{
    // GET /api/v1/admin/bookings  — full admin view with all bookings
    public function index(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasPermissionTo('bookings.manage'), 403);

        $bookings = Booking::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('resource_id'), fn ($q, $v) => $q->where('resource_id', $v))
            ->when($request->input('from'), fn ($q, $v) => $q->where('starts_at', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->where('ends_at', '<=', $v))
            ->with(['resource', 'user'])
            ->orderBy('starts_at')
            ->paginate($request->integer('per_page', 50));

        return BookingResource::collection($bookings);
    }

    // PATCH /api/v1/admin/bookings/{booking}  — admin update (status, notes, etc.)
    public function update(Request $request, Booking $booking): BookingResource
    {
        abort_unless($request->user()->hasPermissionTo('bookings.manage'), 403);

        $data = $request->validate([
            'status'             => 'sometimes|in:pending,confirmed,cancelled,rejected',
            'title'              => 'sometimes|string|max:255',
            'description'        => 'nullable|string|max:2000',
            'starts_at'          => 'sometimes|date',
            'ends_at'            => 'sometimes|date|after:starts_at',
            'admin_notes'        => 'nullable|string|max:2000',
            'exempt_from_limit'  => 'boolean',
        ]);

        $booking->update($data);

        AuditLog::record('booking_admin_updated', 'bookings', $booking->title, $booking);

        return new BookingResource($booking->load(['resource', 'user']));
    }

    // GET /api/v1/admin/booking-resources  — full admin resource list
    public function resources(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasPermissionTo('bookings.manage'), 403);

        $resources = ResourceModel::with('category')
            ->orderBy('name')
            ->get();

        return BookingResourceResource::collection($resources);
    }

    // POST /api/v1/admin/booking-resources
    public function storeResource(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('bookings.manage'), 403);

        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'description'       => 'nullable|string|max:1000',
            'location'          => 'nullable|string|max:255',
            'category_id'       => 'nullable|exists:booking_categories,id',
            'capacity'          => 'required|integer|min:1',
            'min_duration'      => 'integer|min:15',
            'max_duration'      => 'nullable|integer|min:15',
            'requires_approval' => 'boolean',
            'active'            => 'boolean',
            'color'             => 'nullable|string|max:20',
            'icon'              => 'nullable|string|max:100',
        ]);

        $data['slug'] = Str::slug($data['name']);

        $resource = ResourceModel::create($data);

        AuditLog::record('booking_resource_created', 'bookings', $resource->name, $resource);

        return (new BookingResourceResource($resource))->response()->setStatusCode(201);
    }

    // PATCH /api/v1/admin/booking-resources/{resource}
    public function updateResource(Request $request, ResourceModel $bookingResource): BookingResourceResource
    {
        abort_unless($request->user()->hasPermissionTo('bookings.manage'), 403);

        $data = $request->validate([
            'name'              => 'sometimes|string|max:255',
            'description'       => 'nullable|string|max:1000',
            'location'          => 'nullable|string|max:255',
            'category_id'       => 'nullable|exists:booking_categories,id',
            'capacity'          => 'sometimes|integer|min:1',
            'min_duration'      => 'integer|min:15',
            'max_duration'      => 'nullable|integer|min:15',
            'requires_approval' => 'boolean',
            'active'            => 'boolean',
            'color'             => 'nullable|string|max:20',
            'icon'              => 'nullable|string|max:100',
        ]);

        $bookingResource->update($data);

        AuditLog::record('booking_resource_updated', 'bookings', $bookingResource->name, $bookingResource);

        return new BookingResourceResource($bookingResource->load('category'));
    }

    // DELETE /api/v1/admin/booking-resources/{resource}
    public function destroyResource(Request $request, ResourceModel $bookingResource): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('bookings.manage'), 403);

        $name = $bookingResource->name;
        $bookingResource->delete();

        AuditLog::record('booking_resource_deleted', 'bookings', $name, null);

        return response()->json(null, 204);
    }
}
