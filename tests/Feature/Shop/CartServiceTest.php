<?php

use App\Models\Shop\Cart;
use App\Models\Shop\Product;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;

uses(RefreshDatabase::class);

function cartService(): CartService
{
    return app(CartService::class);
}

// ── get / getOrCreate ─────────────────────────────────────────────────────────

test('get returns null when no session token exists', function () {
    expect(cartService()->get())->toBeNull();
});

test('getOrCreate creates a new cart and stores session token', function () {
    $cart = cartService()->getOrCreate();

    expect($cart)->toBeInstanceOf(Cart::class)
        ->and(Session::has('cart_session_token'))->toBeTrue()
        ->and($cart->expires_at->isFuture())->toBeTrue();
});

test('getOrCreate returns existing cart on second call', function () {
    $cart1 = cartService()->getOrCreate();
    $cart2 = cartService()->getOrCreate();

    expect($cart1->id)->toBe($cart2->id);
});

test('get returns null for expired cart', function () {
    $cart = Cart::factory()->expired()->create();
    Session::put('cart_session_token', $cart->session_token);

    expect(cartService()->get())->toBeNull();
});

// ── addProduct ────────────────────────────────────────────────────────────────

test('addProduct creates a cart item', function () {
    $product = Product::factory()->published()->create(['price_ore' => 19900]);
    $variant = $product->defaultVariant();

    $item = cartService()->addProduct($variant, 1);

    expect($item->quantity)->toBe(1)
        ->and($item->unit_price_ore)->toBe(19900)
        ->and($item->cartable_id)->toBe($variant->id);
});

test('addProduct increments quantity when same variant already in cart', function () {
    $product = Product::factory()->published()->create();
    $variant = $product->defaultVariant();

    cartService()->addProduct($variant, 1);
    $item = cartService()->addProduct($variant, 2);

    expect($item->quantity)->toBe(3);
    expect(cartService()->get()->items()->count())->toBe(1);
});

test('addProduct uses product price_ore as unit_price_ore', function () {
    $product = Product::factory()->published()->create(['price_ore' => 20000]);
    $variant = $product->defaultVariant();

    $item = cartService()->addProduct($variant, 1);

    expect($item->unit_price_ore)->toBe(20000);
});

// ── updateQuantity ────────────────────────────────────────────────────────────

test('updateQuantity changes item quantity', function () {
    $product = Product::factory()->published()->create();
    $variant = $product->defaultVariant();
    $item    = cartService()->addProduct($variant, 1);

    cartService()->updateQuantity($item->id, 5);

    expect($item->fresh()->quantity)->toBe(5);
});

test('updateQuantity below 1 removes the item', function () {
    $product = Product::factory()->published()->create();
    $variant = $product->defaultVariant();
    $item    = cartService()->addProduct($variant, 2);

    cartService()->updateQuantity($item->id, 0);

    expect(cartService()->get()->items()->count())->toBe(0);
});

// ── remove ────────────────────────────────────────────────────────────────────

test('remove deletes specific item', function () {
    $p1 = Product::factory()->published()->create();
    $p2 = Product::factory()->published()->create();
    $i1 = cartService()->addProduct($p1->defaultVariant(), 1);
    cartService()->addProduct($p2->defaultVariant(), 1);

    cartService()->remove($i1->id);

    expect(cartService()->get()->items()->count())->toBe(1);
});

// ── clear / destroy ───────────────────────────────────────────────────────────

test('clear empties cart items but keeps the cart', function () {
    $product = Product::factory()->published()->create();
    cartService()->addProduct($product->defaultVariant(), 2);

    cartService()->clear();

    $cart = cartService()->get();
    expect($cart)->not->toBeNull()
        ->and($cart->isEmpty())->toBeTrue();
});

test('destroy deletes cart and forgets session token', function () {
    $product = Product::factory()->published()->create();
    cartService()->addProduct($product->defaultVariant(), 1);

    cartService()->destroy();

    expect(cartService()->get())->toBeNull()
        ->and(Session::has('cart_session_token'))->toBeFalse();
});

// ── validateStock ─────────────────────────────────────────────────────────────

test('validateStock returns empty when all items are in stock', function () {
    $product = Product::factory()->published()->withStock(10)->create();
    cartService()->addProduct($product->defaultVariant(), 3);

    expect(cartService()->validateStock())->toBeEmpty();
});

test('validateStock returns error for out-of-stock item', function () {
    $product = Product::factory()->published()->withStock(2)->create();
    $variant = $product->defaultVariant();
    cartService()->addProduct($variant, 5); // more than stock

    $errors = cartService()->validateStock();

    expect($errors)->not->toBeEmpty();
});

test('validateStock ignores non-stock-tracked products', function () {
    $product = Product::factory()->published()->create(['track_stock' => false]);
    cartService()->addProduct($product->defaultVariant(), 100);

    expect(cartService()->validateStock())->toBeEmpty();
});

// ── totalOre / itemCount ──────────────────────────────────────────────────────

test('totalOre sums all line totals', function () {
    $p1 = Product::factory()->published()->create(['price_ore' => 10000]);
    $p2 = Product::factory()->published()->create(['price_ore' => 5000]);

    cartService()->addProduct($p1->defaultVariant(), 2);
    cartService()->addProduct($p2->defaultVariant(), 3);

    expect(cartService()->totalOre())->toBe(35000);
});

test('itemCount sums quantities', function () {
    $p1 = Product::factory()->published()->create();
    $p2 = Product::factory()->published()->create();

    cartService()->addProduct($p1->defaultVariant(), 3);
    cartService()->addProduct($p2->defaultVariant(), 2);

    expect(cartService()->itemCount())->toBe(5);
});

test('totalOre returns zero when no cart exists', function () {
    expect(cartService()->totalOre())->toBe(0);
});

test('itemCount returns zero when no cart exists', function () {
    expect(cartService()->itemCount())->toBe(0);
});

// ── mergeIntoUser ─────────────────────────────────────────────────────────────

test('mergeIntoUser moves guest cart items to user cart', function () {
    $product = Product::factory()->published()->create();
    cartService()->addProduct($product->defaultVariant(), 2);

    $user = User::factory()->create();
    $this->actingAs($user);
    app()->forgetInstance(CartService::class);

    app(CartService::class)->mergeIntoUser();

    $userCart = Cart::where('user_id', $user->id)->first();
    expect($userCart)->not->toBeNull()
        ->and($userCart->items()->sum('quantity'))->toBe(2);
});
