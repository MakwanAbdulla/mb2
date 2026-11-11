<?php

namespace App\Services;

use App\Models\Shop\Cart;
use App\Models\Shop\CartItem;
use App\Models\Events\EventPriceTier;
use App\Models\Shop\ProductVariant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class CartService
{
    private const SESSION_KEY   = 'cart_session_token';
    private const TTL_HOURS     = 24;

    // ── Cart resolution ────────────────────────────────────────────────────────

    public function get(): ?Cart
    {
        $token = Session::get(self::SESSION_KEY);

        if (! $token) {
            return null;
        }

        return Cart::with('items.cartable')
            ->where('session_token', $token)
            ->where('expires_at', '>', now())
            ->first();
    }

    public function getOrCreate(): Cart
    {
        $cart = $this->get();

        if ($cart) {
            return $cart;
        }

        $token = Str::uuid()->toString();
        Session::put(self::SESSION_KEY, $token);

        return Cart::create([
            'user_id'       => Auth::id(),
            'session_token' => $token,
            'expires_at'    => now()->addHours(self::TTL_HOURS),
        ]);
    }

    /**
     * Merge a guest cart into the authenticated user's cart.
     * Called after login.
     */
    public function mergeIntoUser(): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        $token = Session::get(self::SESSION_KEY);

        if (! $token) {
            return;
        }

        $guestCart = Cart::where('session_token', $token)
            ->where('expires_at', '>', now())
            ->whereNull('user_id')
            ->with('items')
            ->first();

        if (! $guestCart || $guestCart->isEmpty()) {
            return;
        }

        // Find or create user's own cart
        $userCart = Cart::where('user_id', $user->id)
            ->where('expires_at', '>', now())
            ->first();

        if (! $userCart) {
            $guestCart->update(['user_id' => $user->id]);
            return;
        }

        // Move items from guest cart to user cart
        foreach ($guestCart->items as $item) {
            $existing = $userCart->items()
                ->where('cartable_type', $item->cartable_type)
                ->where('cartable_id', $item->cartable_id)
                ->first();

            if ($existing) {
                $existing->increment('quantity', $item->quantity);
            } else {
                $item->update(['cart_id' => $userCart->id]);
            }
        }

        $guestCart->delete();
        Session::put(self::SESSION_KEY, $userCart->session_token);
    }

    // ── Add items ──────────────────────────────────────────────────────────────

    public function addProduct(ProductVariant $variant, int $quantity = 1): CartItem
    {
        $cart = $this->getOrCreate();

        $existing = $cart->items()
            ->where('cartable_type', ProductVariant::class)
            ->where('cartable_id', $variant->id)
            ->first();

        if ($existing) {
            $existing->increment('quantity', $quantity);
            return $existing->fresh();
        }

        $user     = Auth::user();
        $isMember = $user && $user->member?->isActive();

        return $cart->items()->create([
            'cartable_type'  => ProductVariant::class,
            'cartable_id'    => $variant->id,
            'quantity'       => $quantity,
            'unit_price_ore' => $variant->product->priceFor($isMember),
        ]);
    }

    public function addEventTicket(
        EventPriceTier $tier,
        array $occurrenceIds = [],
        array $addonSelections = [],
        ?string $attendeeName = null,
        ?string $attendeeEmail = null,
    ): CartItem {
        $cart = $this->getOrCreate();

        return $cart->items()->create([
            'cartable_type'  => EventPriceTier::class,
            'cartable_id'    => $tier->id,
            'quantity'       => 1,
            'unit_price_ore' => $tier->price_ore,
            'meta'           => [
                'event_id'         => $tier->event_id,
                'occurrence_ids'   => $occurrenceIds,
                'addon_selections' => $addonSelections,
                'attendee_name'    => $attendeeName,
                'attendee_email'   => $attendeeEmail,
            ],
        ]);
    }

    // ── Update / remove ────────────────────────────────────────────────────────

    public function updateQuantity(int $itemId, int $quantity): void
    {
        $cart = $this->get();

        if (! $cart) {
            return;
        }

        $item = $cart->items()->findOrFail($itemId);

        if ($quantity < 1) {
            $item->delete();
            return;
        }

        $item->update(['quantity' => $quantity]);
    }

    public function remove(int $itemId): void
    {
        $cart = $this->get();

        if (! $cart) {
            return;
        }

        $cart->items()->where('id', $itemId)->delete();
    }

    public function clear(): void
    {
        $cart = $this->get();
        $cart?->items()->delete();
    }

    public function destroy(): void
    {
        $cart = $this->get();
        $cart?->delete();
        Session::forget(self::SESSION_KEY);
    }

    // ── Stock validation ───────────────────────────────────────────────────────

    /**
     * Validate all product items have sufficient stock.
     * Returns array of error messages keyed by cart_item_id.
     */
    public function validateStock(): array
    {
        $errors = [];
        $cart   = $this->get();

        if (! $cart) {
            return $errors;
        }

        foreach ($cart->items as $item) {
            if (! $item->isProduct()) {
                continue;
            }

            /** @var ProductVariant $variant */
            $variant = $item->cartable;

            if (! $variant || ! $variant->isInStockFor($item->quantity)) {
                $errors[$item->id] = "'{$item->displayLabel()}' saknar tillräckligt lager.";
            }
        }

        return $errors;
    }

    // ── Summary ────────────────────────────────────────────────────────────────

    public function itemCount(): int
    {
        return $this->get()?->items()->sum('quantity') ?? 0;
    }

    public function totalOre(): int
    {
        $cart = $this->get();

        if (! $cart) {
            return 0;
        }

        return $cart->items->sum(fn (CartItem $item) => $item->lineTotalOre());
    }
}
