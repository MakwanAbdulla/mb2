<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecurringBookingResource;
use App\Models\AuditLog;
use App\Models\Booking\RecurringBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class RecurringBookingController extends Controller
{
    // GET /api/v1/recurring-bookings  — own; bookings.manage sees all
    public function index(Request $request): ResourceCollection
    {
        $user = $request->user();

        $recurring = RecurringBooking::query()
            ->when(
                ! $user->hasPermissionTo('bookings.manage'),
                fn ($q) => $q->where('user_id', $user->id)
            )
            ->when($request->input('resource_id'), fn ($q, $v) => $q->where('resource_id', $v))
            ->with(['resource'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return RecurringBookingResource::collection($recurring);
    }

    // GET /api/v1/recurring-bookings/{recurring}
    public function show(Request $request, RecurringBooking $recurringBooking): RecurringBookingResource
    {
        $user = $request->user();
        abort_unless(
            $recurringBooking->user_id === $user->id
            || $user->hasPermissionTo('bookings.manage'),
            403
        );

        return new RecurringBookingResource($recurringBooking->load('resource'));
    }

    // DELETE /api/v1/recurring-bookings/{recurring}
    public function destroy(Request $request, RecurringBooking $recurringBooking): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $recurringBooking->user_id === $user->id
            || $user->hasPermissionTo('bookings.manage'),
            403
        );

        $title = $recurringBooking->title;
        $recurringBooking->delete();

        AuditLog::record('recurring_booking_deleted', 'bookings', $title, null);

        return response()->json(null, 204);
    }
}
