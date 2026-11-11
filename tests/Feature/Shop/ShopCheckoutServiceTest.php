<?php

use App\Models\Shop\DiscountCode;
use App\Models\Shop\DiscountUse;
use App\Models\Shop\Order;
use App\Models\Shop\OrderFulfillment;
use App\Models\Shop\Product;
use App\Models\User;
use App\Services\CartService;
use App\Services\ShopCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function checkoutService(): ShopCheckoutService
{
    return app(ShopCheckoutService::class);
}

function cartWithProduct(int $priceOre = 10000, int $qty = 1)
{
    $product = Product::factory()->published()->create(['price_ore' => $priceOre]);
    $variant = $product->defaultVariant();
    app(CartService::class)->addProduct($variant, $qty);
    return app(CartService::class)->getOrCreate();
}

// ── Order creation ─────────────────────────────────────────────────────────────

test('creates order from cart for authenticated user', function () {
    $user = User::factory()->create();
    $cart = cartWithProduct(20000);

    $order = checkoutService()->createOrderFromCart(
        cart:       $cart,
        userId:     $user->id,
        guestName:  null,
        guestEmail: null,
    );

    expect($order)->toBeInstanceOf(Order::class)
        ->and($order->user_id)->toBe($user->id)
        ->and($order->total_ore)->toBe(20000)
        ->and($order->status)->toBe(Order::STATUS_PENDING)
        ->and($order->lines)->toHaveCount(1);
});

test('creates order from cart for guest', function () {
    $cart = cartWithProduct(15000);

    $order = checkoutService()->createOrderFromCart(
        cart:       $cart,
        userId:     null,
        guestName:  'Anna Svensson',
        guestEmail: 'anna@example.com',
    );

    expect($order->user_id)->toBeNull()
        ->and($order->guest_name)->toBe('Anna Svensson')
        ->and($order->guest_email)->toBe('anna@example.com')
        ->and($order->total_ore)->toBe(15000);
});

test('creates fulfillment for physical product', function () {
    $cart = cartWithProduct(10000);

    $order = checkoutService()->createOrderFromCart(
        cart:       $cart,
        userId:     null,
        guestName:  'Test',
        guestEmail: 'test@example.com',
    );

    expect(OrderFulfillment::where('order_id', $order->id)->count())->toBe(1);
});

test('does not create fulfillment for digital product', function () {
    $product = Product::factory()->digital()->published()->create(['price_ore' => 5000]);
    $variant = $product->defaultVariant();
    app(CartService::class)->addProduct($variant, 1);
    $cart = app(CartService::class)->get();

    $order = checkoutService()->createOrderFromCart(
        cart:       $cart,
        userId:     null,
        guestName:  'Test',
        guestEmail: 'test@example.com',
    );

    expect(OrderFulfillment::where('order_id', $order->id)->count())->toBe(0);
});

test('applies discount code and records use', function () {
    $user = User::factory()->create();
    $cart = cartWithProduct(10000);

    $discount = \App\Models\Shop\Discount::factory()->create([
        'type'       => 'fixed',
        'value'      => 2000,
        'applies_to' => 'shop',
    ]);
    $code = DiscountCode::factory()->create(['discount_id' => $discount->id]);

    $order = checkoutService()->createOrderFromCart(
        cart:         $cart,
        userId:       $user->id,
        guestName:    null,
        guestEmail:   null,
        discountCode: $code->fresh(['discount']),
    );

    expect($order->total_ore)->toBe(8000)
        ->and($order->discount_ore)->toBe(2000)
        ->and(DiscountUse::where('discountable_id', $order->id)->count())->toBe(1);
});

test('total cannot go below zero with oversized discount', function () {
    $user = User::factory()->create();
    $cart = cartWithProduct(5000);

    $discount = \App\Models\Shop\Discount::factory()->create([
        'type'       => 'fixed',
        'value'      => 99999,
        'applies_to' => 'shop',
    ]);
    $code = DiscountCode::factory()->create(['discount_id' => $discount->id]);

    $order = checkoutService()->createOrderFromCart(
        cart:         $cart,
        userId:       $user->id,
        guestName:    null,
        guestEmail:   null,
        discountCode: $code->fresh(['discount']),
    );

    expect($order->total_ore)->toBe(0);
});

test('decrements stock when track_stock is enabled', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->published()->create([
        'price_ore'   => 10000,
        'track_stock' => true,
    ]);
    $variant = $product->defaultVariant();
    $variant->update(['stock' => 5]);

    app(CartService::class)->addProduct($variant, 2);
    $cart = app(CartService::class)->getOrCreate();

    checkoutService()->createOrderFromCart(
        cart:       $cart,
        userId:     null,
        guestName:  'Test',
        guestEmail: 'test@example.com',
    );

    expect($variant->fresh()->stock)->toBe(3);
});

// ── OrderFulfillment status flow ───────────────────────────────────────────────

test('fulfillment helper methods reflect pickup statuses', function () {
    $f = new OrderFulfillment(['status' => 'ready_for_pickup']);
    expect($f->isReadyForPickup())->toBeTrue()
        ->and($f->isPickedUp())->toBeFalse()
        ->and($f->isComplete())->toBeFalse();

    $f->status = 'picked_up';
    expect($f->isPickedUp())->toBeTrue()
        ->and($f->isComplete())->toBeTrue();
});

test('fulfillment isComplete returns true for delivered and picked_up', function () {
    foreach (['delivered', 'picked_up'] as $status) {
        $f = new OrderFulfillment(['status' => $status]);
        expect($f->isComplete())->toBeTrue();
    }

    foreach (['pending', 'processing', 'shipped', 'ready_for_pickup', 'cancelled'] as $status) {
        $f = new OrderFulfillment(['status' => $status]);
        expect($f->isComplete())->toBeFalse();
    }
});
