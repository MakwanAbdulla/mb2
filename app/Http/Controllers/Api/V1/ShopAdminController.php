<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ProductResource;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Str;

class ShopAdminController extends Controller
{
    // ── Products ──────────────────────────────────────────────────────────────

    public function productIndex(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasAnyPermission(['shop.view', 'shop.manage']), 403);

        $products = Product::withTrashed()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->with('variants')
            ->orderBy('sort_order')
            ->paginate($request->integer('per_page', 50));

        return ProductResource::collection($products);
    }

    public function productStore(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('shop.manage'), 403);

        $data = $request->validate([
            'name'                   => 'required|string|max:255',
            'slug'                   => 'nullable|string|max:255|unique:products,slug',
            'description'            => 'nullable|string',
            'type'                   => 'required|in:physical,digital',
            'status'                 => 'in:draft,published,archived',
            'price_ore'              => 'required|integer|min:0',
            'member_price_ore'       => 'nullable|integer|min:0',
            'requires_membership'    => 'boolean',
            'show_in_event_checkout' => 'boolean',
            'track_stock'            => 'boolean',
            'sort_order'             => 'integer',
        ]);

        $data['slug']   = $data['slug'] ?? Str::slug($data['name']);
        $data['status'] = $data['status'] ?? Product::STATUS_DRAFT;

        $product = Product::create($data);

        return (new ProductResource($product->load('variants')))->response()->setStatusCode(201);
    }

    public function productUpdate(Request $request, Product $product): ProductResource
    {
        abort_unless($request->user()->hasPermissionTo('shop.manage'), 403);

        $data = $request->validate([
            'name'                   => 'sometimes|string|max:255',
            'description'            => 'nullable|string',
            'status'                 => 'sometimes|in:draft,published,archived',
            'price_ore'              => 'sometimes|integer|min:0',
            'member_price_ore'       => 'nullable|integer|min:0',
            'requires_membership'    => 'boolean',
            'show_in_event_checkout' => 'boolean',
            'track_stock'            => 'boolean',
            'sort_order'             => 'integer',
        ]);

        $product->update($data);

        return new ProductResource($product->load('variants'));
    }

    public function productDestroy(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('shop.manage'), 403);

        $product->delete();

        return response()->json(['message' => 'Product archived.']);
    }

    // ── Variants ──────────────────────────────────────────────────────────────

    public function variantStore(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('shop.manage'), 403);

        $data = $request->validate([
            'option_value' => 'required|string|max:100',
            'sku'          => 'nullable|string|max:100',
            'stock'        => 'nullable|integer|min:0',
            'sort_order'   => 'integer',
        ]);

        $variant = $product->variants()->create($data);

        return response()->json(['data' => $variant], 201);
    }

    public function variantUpdate(Request $request, Product $product, ProductVariant $variant): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('shop.manage'), 403);
        abort_unless($variant->product_id === $product->id, 404);

        $data = $request->validate([
            'option_value'       => 'sometimes|string|max:100',
            'sku'                => 'nullable|string|max:100',
            'stock'              => 'nullable|integer|min:0',
            'sort_order'         => 'integer',
        ]);

        $variant->update($data);

        return response()->json(['data' => $variant]);
    }

    public function variantDestroy(Request $request, Product $product, ProductVariant $variant): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('shop.manage'), 403);
        abort_unless($variant->product_id === $product->id, 404);

        $variant->delete();

        return response()->json(['message' => 'Variant deleted.']);
    }

    // ── Orders ────────────────────────────────────────────────────────────────

    public function orderIndex(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasAnyPermission(['shop.view', 'shop.manage']), 403);

        $orders = Order::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->with('user', 'lines')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return OrderResource::collection($orders);
    }

    public function orderShow(Request $request, Order $order): OrderResource
    {
        abort_unless($request->user()->hasAnyPermission(['shop.view', 'shop.manage']), 403);

        $order->load('user', 'lines');

        return new OrderResource($order);
    }

    public function orderUpdate(Request $request, Order $order): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('shop.manage'), 403);

        $data = $request->validate([
            'status' => 'required|in:pending,paid,refunded,cancelled',
            'notes'  => 'nullable|string|max:2000',
        ]);

        $order->update($data);

        return response()->json(['message' => 'Order updated.', 'status' => $order->status]);
    }
}
