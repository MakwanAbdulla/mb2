<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\AuditLog;
use App\Models\Booking\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Auth;

class BookingController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        $user    = Auth::user();
        $isStaff = $user->hasAnyPermission(['bookings.view', 'bookings.manage']);

        abort_unless($isStaff || $user->canBook(), 403, 'Booking requires membership.');

        $bookings = Booking::query()
            ->when(! $isStaff, fn ($q) => $q->where('user_id', $user->id))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('from'), fn ($q, $v) => $q->where('starts_at', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->where('ends_at', '<=', $v))
            ->with('resource', 'user')
            ->orderBy('starts_at')
            ->paginate($request->integer('per_page', 25));

        return BookingResource::collection($bookings);
    }

    public function show(Booking $booking): BookingResource
    {
        $user = Auth::user();

        abort_unless(
            ($booking->user_id === $user->id && $user->canBook())
            || $user->hasAnyPermission(['bookings.view', 'bookings.manage']),
            403
        );

        $booking->load('resource', 'user');

        return new BookingResource($booking);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->canBook(), 403, 'Booking requires membership.');

        $data = $request->validate([
            'title'        => 'required|string|max:255',
            'activity'     => 'required|string|max:255',
            'booking_type' => 'required|string|max:50',
            'resource_id'  => 'required|exists:booking_resources,id',
            'starts_at'    => 'required|date|after:now',
            'ends_at'      => 'required|date|after:starts_at',
            'notes'        => 'nullable|string|max:2000',
        ]);

        $booking = Booking::create([
            ...$data,
            'user_id' => Auth::id(),
            'status'  => 'pending',
        ]);

        AuditLog::record('booking_created', 'bookings', $booking->title, $booking);

        return (new BookingResource($booking))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, Booking $booking): JsonResponse
    {
        $user = Auth::user();
        abort_unless(
            ($booking->user_id === $user->id && $user->canBook())
            || $user->hasPermissionTo('bookings.manage'),
            403
        );
        abort_unless(in_array($booking->status, ['pending', 'approved']), 422, 'Booking cannot be cancelled.');

        $booking->update(['status' => 'cancelled']);

        AuditLog::record('booking_cancelled', 'bookings', $booking->title, $booking);

        return response()->json(['message' => 'Booking cancelled.']);
    }

    public function update(Request $request, Booking $booking): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user->hasPermissionTo('bookings.manage'), 403);

        $data = $request->validate([
            'status'           => 'required|in:approved,rejected,cancelled',
            'rejection_reason' => 'nullable|string|max:500',
        ]);

        $booking->update($data);

        AuditLog::record('booking_updated', 'bookings', $booking->title, $booking);

        return response()->json(['message' => 'Booking updated.', 'status' => $booking->status]);
    }
}
