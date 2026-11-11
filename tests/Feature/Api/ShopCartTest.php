<?php

use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── GET /api/v1/shop/cart ─────────────────────────────────────────────────────

test('empty cart returns zero state', function () {
    $this->getJson('/api/v1/shop/cart')
        ->assertOk()
        ->assertJson([
            'items'           => [],
            'total_ore'       => 0,
            'item_count'      => 0,
        ]);
});

// ── POST /api/v1/shop/cart/items ──────────────────────────────────────────────

test('can add a product variant to cart', function () {
    $product = Product::factory()->published()->create(['price_ore' => 19900]);
    $variant = $product->defaultVariant();

    $this->postJson('/api/v1/shop/cart/items', [
        'variant_id' => $variant->id,
        'quantity'   => 1,
    ])
        ->assertStatus(201)
        ->assertJsonPath('data.quantity', 1)
        ->assertJsonPath('data.unit_price_ore', 19900);
});

test('adding same variant increments quantity', function () {
    $product = Product::factory()->published()->create(['price_ore' => 10000]);
    $variant = $product->defaultVariant();

    $this->postJson('/api/v1/shop/cart/items', ['variant_id' => $variant->id, 'quantity' => 1]);
    $this->postJson('/api/v1/shop/cart/items', ['variant_id' => $variant->id, 'quantity' => 2]);

    $this->getJson('/api/v1/shop/cart')
        ->assertOk()
        ->assertJsonPath('item_count', 3)
        ->assertJsonPath('total_ore', 30000);
});

test('cart returns 422 when variant does not exist', function () {
    $this->postJson('/api/v1/shop/cart/items', ['variant_id' => 9999, 'quantity' => 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('variant_id');
});

test('cart returns 422 when product is not published', function () {
    $product = Product::factory()->create(['status' => Product::STATUS_DRAFT]);
    $variant = $product->defaultVariant();

    $this->postJson('/api/v1/shop/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])
        ->assertUnprocessable();
});

test('cart returns 422 when stock is insufficient', function () {
    $product = Product::factory()->published()->withStock(2)->create();
    $variant = $product->defaultVariant();

    $this->postJson('/api/v1/shop/cart/items', ['variant_id' => $variant->id, 'quantity' => 5])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Insufficient stock.');
});

test('cart item resource has correct fields', function () {
    $product = Product::factory()->published()->create();
    $variant = $product->defaultVariant();

    $this->postJson('/api/v1/shop/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])
        ->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'id', 'cartable_type', 'cartable_id', 'cartable',
                'label', 'quantity', 'unit_price_ore', 'unit_price_formatted',
                'line_total_ore', 'line_total_formatted',
            ],
        ]);
});

test('line_total_ore is quantity times unit price', function () {
    $product = Product::factory()->published()->create(['price_ore' => 5000]);
    $variant = $product->defaultVariant();

    $response = $this->postJson('/api/v1/shop/cart/items', ['variant_id' => $variant->id, 'quantity' => 3]);
    $response->assertStatus(201)
        ->assertJsonPath('data.line_total_ore', 15000);
});

// ── PATCH /api/v1/shop/cart/items/{id} ───────────────────────────────────────

test('can update cart item quantity', function () {
    $product = Product::factory()->published()->create(['price_ore' => 10000]);
    $variant = $product->defaultVariant();

    $addResponse = $this->postJson('/api/v1/shop/cart/items', ['variant_id' => $variant->id, 'quantity' => 1]);
    $itemId      = $addResponse->json('data.id');

    $this->patchJson("/api/v1/shop/cart/items/{$itemId}", ['quantity' => 3])
        ->assertOk()
        ->assertJsonPath('total_ore', 30000)
        ->assertJsonPath('item_count', 3);
});

test('updating cart item to zero removes it', function () {
    $product = Product::factory()->published()->create();
    $variant = $product->defaultVariant();

    $addResponse = $this->postJson('/api/v1/shop/cart/items', ['variant_id' => $variant->id, 'quantity' => 2]);
    $itemId      = $addResponse->json('data.id');

    $this->patchJson("/api/v1/shop/cart/items/{$itemId}", ['quantity' => 0])
        ->assertOk()
        ->assertJsonPath('item_count', 0);
});

// ── DELETE /api/v1/shop/cart/items/{id} ──────────────────────────────────────

test('can remove a single cart item', function () {
    $product = Product::factory()->published()->create(['price_ore' => 10000]);
    $variant = $product->defaultVariant();

    $addResponse = $this->postJson('/api/v1/shop/cart/items', ['variant_id' => $variant->id, 'quantity' => 2]);
    $itemId      = $addResponse->json('data.id');

    $this->deleteJson("/api/v1/shop/cart/items/{$itemId}")
        ->assertOk()
        ->assertJsonPath('item_count', 0)
        ->assertJsonPath('total_ore', 0);
});

// ── DELETE /api/v1/shop/cart ──────────────────────────────────────────────────

test('can clear the entire cart', function () {
    $product = Product::factory()->published()->create();
    $variant = $product->defaultVariant();

    $this->postJson('/api/v1/shop/cart/items', ['variant_id' => $variant->id, 'quantity' => 2]);

    $this->deleteJson('/api/v1/shop/cart')
        ->assertOk()
        ->assertJsonPath('message', 'Cart cleared.');

    $this->getJson('/api/v1/shop/cart')
        ->assertOk()
        ->assertJsonPath('item_count', 0);
});

// ── Cart total correctness ────────────────────────────────────────────────────

test('cart total is sum of all line totals', function () {
    $p1 = Product::factory()->published()->create(['price_ore' => 10000]);
    $p2 = Product::factory()->published()->create(['price_ore' => 25000]);

    $this->postJson('/api/v1/shop/cart/items', ['variant_id' => $p1->defaultVariant()->id, 'quantity' => 2]);
    $this->postJson('/api/v1/shop/cart/items', ['variant_id' => $p2->defaultVariant()->id, 'quantity' => 1]);

    $this->getJson('/api/v1/shop/cart')
        ->assertOk()
        ->assertJsonPath('total_ore', 45000)
        ->assertJsonPath('item_count', 3);
});
