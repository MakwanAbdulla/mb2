<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Shop\Product;
use App\Http\Controllers\Controller;
use App\Http\Resources\CartItemResource;
use App\Models\AuditLog;
use App\Models\Shop\ProductVariant;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopCartController extends Controller
{
    public function __construct(private CartService $cart) {}

    /**
     * GET /api/v1/shop/cart
     *
     * Returns the current cart contents and total.
     */
    public function show(): JsonResponse
    {
        $cart = $this->cart->get()?->load('items.cartable.product');

        if (! $cart || $cart->isEmpty()) {
            return response()->json([
                'items'           => [],
                'total_ore'       => 0,
                'total_formatted' => '0,00 kr',
                'item_count'      => 0,
            ]);
        }

        return response()->json([
            'items'           => CartItemResource::collection($cart->items)->resolve(),
            'total_ore'       => $cart->totalOre(),
            'total_formatted' => number_format($cart->totalOre() / 100, 2, ',', ' ') . ' kr',
            'item_count'      => $cart->items->sum('quantity'),
        ]);
    }

    /**
     * POST /api/v1/shop/cart/items
     *
     * Add a product variant to the cart.
     *
     * Body: { "variant_id": int, "quantity": int }
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'quantity'   => ['integer', 'min:1', 'max:100'],
        ]);

        $quantity = $data['quantity'] ?? 1;
        $variant  = ProductVariant::with('product')->findOrFail($data['variant_id']);

        if ($variant->product->status !== \App\Models\Shop\Product::STATUS_PUBLISHED) {
            return response()->json(['message' => 'Product is not available.'], 422);
        }

        if (! $variant->isInStockFor($quantity)) {
            return response()->json(['message' => 'Insufficient stock.'], 422);
        }

        $item = $this->cart->addProduct($variant, $quantity);
        $item->load('cartable.product');

        AuditLog::record('cart_item_added', 'shop', $variant->product->name, null);

        return (new CartItemResource($item))->response()->setStatusCode(201);
    }

    /**
     * PATCH /api/v1/shop/cart/items/{id}
     *
     * Update quantity for a cart item.
     *
     * Body: { "quantity": int }  — set to 0 to remove.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $this->cart->updateQuantity($id, $data['quantity']);

        return $this->show();
    }

    /**
     * DELETE /api/v1/shop/cart/items/{id}
     *
     * Remove a single item from the cart.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->cart->remove($id);

        return $this->show();
    }

    /**
     * DELETE /api/v1/shop/cart
     *
     * Clear all items from the cart (keep cart, empty it).
     */
    public function clear(): JsonResponse
    {
        $this->cart->clear();

        return response()->json(['message' => 'Cart cleared.']);
    }
}
