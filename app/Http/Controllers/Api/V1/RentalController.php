<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RentalResource;
use App\Models\AuditLog;
use App\Models\Rental\Rental;
use App\Models\Rental\RentalItemType;
use App\Models\Rental\RentalTypePrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class RentalController extends Controller
{
    public function index(): ResourceCollection
    {
        $member = auth()->user()->member;

        if (! $member) {
            return RentalResource::collection(collect());
        }

        $rentals = Rental::with('item.type')
            ->where('member_id', $member->id)
            ->whereNull('ended_at')
            ->orderBy('started_at')
            ->get();

        return RentalResource::collection($rentals);
    }

    public function adminIndex(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasAnyPermission(['rentals.view', 'rentals.manage']), 403);

        $rentals = Rental::with('item.type', 'member')
            ->when(! $request->boolean('include_ended'), fn ($q) => $q->whereNull('ended_at'))
            ->when($request->input('member_id'), fn ($q, $v) => $q->where('member_id', $v))
            ->orderBy('started_at')
            ->paginate($request->integer('per_page', 50));

        return RentalResource::collection($rentals);
    }

    public function adminStore(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('rentals.manage'), 403);

        $data = $request->validate([
            'member_id'        => 'required|exists:members,id',
            'rental_item_id'   => 'required|exists:rental_items,id',
            'price_per_year'   => 'required|integer|min:0',
            'started_at'       => 'required|date',
            'notes'            => 'nullable|string|max:1000',
        ]);

        $rental = Rental::create($data);

        return (new RentalResource($rental->load('item.type', 'member')))->response()->setStatusCode(201);
    }

    public function adminUpdate(Request $request, Rental $rental): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('rentals.manage'), 403);

        $data = $request->validate([
            'price_per_year' => 'sometimes|integer|min:0',
            'ended_at'       => 'nullable|date',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $rental->update($data);

        return response()->json(['data' => $rental->fresh('item.type')]);
    }

    // GET /api/v1/admin/rental-item-types
    public function adminItemTypes(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('rentals.manage'), 403);

        $year  = $request->integer('year', now()->year);
        $types = RentalItemType::with(['yearPrices' => fn ($q) => $q->where('year', $year)])
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($t) => [
                'id'                 => $t->id,
                'name'               => $t->name,
                'description'        => $t->description,
                'price_per_year'     => $t->price_per_year,
                'setup_fee_ore'      => $t->setup_fee_ore,
                'is_active'          => $t->is_active,
                'is_admin_controlled'=> $t->is_admin_controlled,
                'sort_order'         => $t->sort_order,
                'current_year_price' => $t->yearPrices->firstWhere('year', $year)?->price_ore ?? $t->price_per_year,
            ]);

        return response()->json(['data' => $types, 'meta' => ['year' => $year]]);
    }

    // POST /api/v1/admin/rental-item-types
    public function storeItemType(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('rentals.manage'), 403);

        $data = $request->validate([
            'name'                => 'required|string|max:255',
            'description'         => 'nullable|string|max:2000',
            'price_per_year'      => 'required|integer|min:0',
            'setup_fee_ore'       => 'nullable|integer|min:0',
            'is_active'           => 'boolean',
            'is_admin_controlled' => 'boolean',
            'sort_order'          => 'integer|min:0',
        ]);

        $type = RentalItemType::create($data);

        AuditLog::record('rental_item_type_created', 'rentals', $type->name, $type);

        return response()->json(['data' => $type], 201);
    }

    // PATCH /api/v1/admin/rental-item-types/{type}
    public function updateItemType(Request $request, RentalItemType $type): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('rentals.manage'), 403);

        $data = $request->validate([
            'name'                => 'sometimes|string|max:255',
            'description'         => 'nullable|string|max:2000',
            'price_per_year'      => 'sometimes|integer|min:0',
            'setup_fee_ore'       => 'nullable|integer|min:0',
            'is_active'           => 'boolean',
            'is_admin_controlled' => 'boolean',
            'sort_order'          => 'integer|min:0',
        ]);

        $type->update($data);

        AuditLog::record('rental_item_type_updated', 'rentals', $type->name, $type);

        return response()->json(['data' => $type->fresh()]);
    }

    // GET /api/v1/admin/rental-item-types/{type}/prices
    public function indexPrices(Request $request, RentalItemType $type): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('rentals.manage'), 403);

        $prices = $type->yearPrices()
            ->when($request->input('year'), fn ($q, $y) => $q->where('year', $y))
            ->orderByDesc('year')
            ->get(['id', 'year', 'price_ore']);

        return response()->json(['data' => $prices]);
    }

    // POST /api/v1/admin/rental-item-types/{type}/prices
    public function storePrice(Request $request, RentalItemType $type): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('rentals.manage'), 403);

        $data = $request->validate([
            'year'      => 'required|integer|min:2020|max:2100',
            'price_ore' => 'required|integer|min:0',
        ]);

        $price = $type->yearPrices()->updateOrCreate(
            ['year' => $data['year']],
            ['price_ore' => $data['price_ore']]
        );

        AuditLog::record('rental_price_set', 'rentals', "{$type->name} {$data['year']}", $price);

        return response()->json(['data' => $price], 201);
    }

    // PATCH /api/v1/admin/rental-item-types/{type}/prices/{price}
    public function updatePrice(Request $request, RentalItemType $type, RentalTypePrice $price): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('rentals.manage'), 403);
        abort_unless($price->rental_item_type_id === $type->id, 404);

        $data = $request->validate([
            'year'      => 'sometimes|integer|min:2020|max:2100',
            'price_ore' => 'sometimes|integer|min:0',
        ]);

        $price->update($data);

        AuditLog::record('rental_price_set', 'rentals', "{$type->name} {$price->year}", $price);

        return response()->json(['data' => $price->fresh()]);
    }
}
