<?php

use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function addProductToApiCart(int $priceOre = 10000): void
{
    $product = Product::factory()->published()->create(['price_ore' => $priceOre]);
    $variant = $product->defaultVariant();
    app(CartService::class)->addProduct($variant, 1);
}

// ── POST /api/v1/shop/checkout ────────────────────────────────────────────────

test('returns 422 when cart is empty', function () {
    $this->postJson('/api/v1/shop/checkout', [
        'payment_method' => 'bankgiro',
        'guest_name'     => 'Anna',
        'guest_email'    => 'anna@example.com',
    ])->assertStatus(422)
      ->assertJsonPath('message', 'Cart is empty.');
});

test('guest can checkout via bankgiro', function () {
    \App\Models\SystemSetting::set('payment.bankgiro_number', '123-4567');
    addProductToApiCart(25000);

    $this->postJson('/api/v1/shop/checkout', [
        'payment_method' => 'bankgiro',
        'guest_name'     => 'Anna Svensson',
        'guest_email'    => 'anna@example.com',
    ])->assertOk()
      ->assertJsonStructure([
          'order_id',
          'payment_method',
          'bankgiro',
          'ocr_number',
          'amount_ore',
          'amount_formatted',
      ])
      ->assertJsonPath('payment_method', 'bankgiro')
      ->assertJsonPath('amount_ore', 25000);
});

test('authenticated user can checkout via bankgiro without guest fields', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    \App\Models\SystemSetting::set('payment.bankgiro_number', '123-4567');
    addProductToApiCart(15000);

    $this->postJson('/api/v1/shop/checkout', [
        'payment_method' => 'bankgiro',
    ])->assertOk()
      ->assertJsonPath('payment_method', 'bankgiro')
      ->assertJsonPath('amount_ore', 15000);

    $order = Order::latest()->first();
    expect($order->user_id)->toBe($user->id)
        ->and($order->guest_name)->toBeNull();
});

test('guest checkout requires name and email', function () {
    addProductToApiCart();

    $this->postJson('/api/v1/shop/checkout', [
        'payment_method' => 'bankgiro',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['guest_name', 'guest_email']);
});

test('invalid payment_method returns validation error', function () {
    addProductToApiCart();

    $this->postJson('/api/v1/shop/checkout', [
        'payment_method' => 'cash',
        'guest_name'     => 'Test',
        'guest_email'    => 'test@example.com',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['payment_method']);
});

test('checkout clears cart after order creation', function () {
    \App\Models\SystemSetting::set('payment.bankgiro_number', '123-4567');
    addProductToApiCart();

    $this->postJson('/api/v1/shop/checkout', [
        'payment_method' => 'bankgiro',
        'guest_name'     => 'Test',
        'guest_email'    => 'test@example.com',
    ])->assertOk();

    expect(app(CartService::class)->get())->toBeNull();
});

test('returns 422 when stock is insufficient', function () {
    $product = Product::factory()->published()->create([
        'price_ore'   => 10000,
        'track_stock' => true,
    ]);
    $variant = $product->defaultVariant();
    $variant->update(['stock' => 0]);

    app(CartService::class)->addProduct($variant, 1);

    $this->postJson('/api/v1/shop/checkout', [
        'payment_method' => 'bankgiro',
        'guest_name'     => 'Test',
        'guest_email'    => 'test@example.com',
    ])->assertStatus(422)
      ->assertJsonPath('message', 'Stock unavailable.');
});

test('invalid discount code returns 422', function () {
    addProductToApiCart();

    $this->postJson('/api/v1/shop/checkout', [
        'payment_method' => 'bankgiro',
        'guest_name'     => 'Test',
        'guest_email'    => 'test@example.com',
        'discount_code'  => 'INVALID999',
    ])->assertStatus(422)
      ->assertJsonPath('message', 'Invalid or expired discount code.');
});
