<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingCategoryResource;
use App\Models\AuditLog;
use App\Models\Booking\BookingCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Str;

class BookingCategoryController extends Controller
{
    // GET /api/v1/booking-categories  — public
    public function index(): ResourceCollection
    {
        $categories = BookingCategory::withCount('resources')
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return BookingCategoryResource::collection($categories);
    }

    // GET /api/v1/admin/booking-categories  — all incl. inactive
    public function adminIndex(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasAnyPermission(['bookings.manage', 'bookings.categories']), 403);

        $categories = BookingCategory::withCount('resources')
            ->orderBy('name')
            ->get();

        return BookingCategoryResource::collection($categories);
    }

    // POST /api/v1/admin/booking-categories
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['bookings.manage', 'bookings.categories']), 403);

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'color'       => 'nullable|string|max:20',
            'active'      => 'boolean',
        ]);

        $data['slug'] = Str::slug($data['name']);

        $category = BookingCategory::create($data);

        AuditLog::record('booking_category_created', 'bookings', $category->name, $category);

        return (new BookingCategoryResource($category))->response()->setStatusCode(201);
    }

    // PATCH /api/v1/admin/booking-categories/{category}
    public function update(Request $request, BookingCategory $bookingCategory): BookingCategoryResource
    {
        abort_unless($request->user()->hasAnyPermission(['bookings.manage', 'bookings.categories']), 403);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'color'       => 'nullable|string|max:20',
            'active'      => 'boolean',
        ]);

        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $bookingCategory->update($data);

        AuditLog::record('booking_category_updated', 'bookings', $bookingCategory->name, $bookingCategory);

        return new BookingCategoryResource($bookingCategory);
    }

    // DELETE /api/v1/admin/booking-categories/{category}
    public function destroy(Request $request, BookingCategory $bookingCategory): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['bookings.manage', 'bookings.categories']), 403);
        abort_if($bookingCategory->resources()->exists(), 422, 'Cannot delete category with resources.');

        $name = $bookingCategory->name;
        $bookingCategory->delete();

        AuditLog::record('booking_category_deleted', 'bookings', $name, null);

        return response()->json(null, 204);
    }
}
