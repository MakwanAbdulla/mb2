<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Shop\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShopProductController extends Controller
{
    /**
     * GET /api/v1/shop/products
     *
     * Returns published products. Member-only products are hidden unless the
     * caller has an active membership.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user     = $request->user();
        $isMember = $user?->member?->isActive() ?? false;

        $products = Product::with(['dimension', 'variants'])
            ->where('status', Product::STATUS_PUBLISHED)
            ->when(! $isMember, fn ($q) => $q->where('requires_membership', false))
            ->when($request->filled('search'), fn ($q) =>
                $q->where('name', 'like', '%' . $request->string('search') . '%')
            )
            ->when($request->boolean('upsell'), fn ($q) =>
                $q->where('show_in_event_checkout', true)
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return ProductResource::collection($products);
    }

    /**
     * GET /api/v1/shop/products/{slug}
     *
     * Returns a single published product.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $product = Product::with(['dimension', 'variants'])
            ->where('slug', $slug)
            ->where('status', Product::STATUS_PUBLISHED)
            ->firstOrFail();

        $user     = $request->user();
        $isMember = $user?->member?->isActive() ?? false;

        if ($product->requires_membership && ! $isMember) {
            return response()->json(['message' => 'Membership required to view this product.'], 403);
        }

        return (new ProductResource($product))->response();
    }
}
