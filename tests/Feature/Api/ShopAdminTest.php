<?php

use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('shop.view');
    $this->createPermission('shop.manage');
});

function shopAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('shop.manage');
    return $user;
}

// ── GET /api/v1/admin/shop/products ──────────────────────────────────────────

test('admin products list requires auth', function () {
    $this->getJson('/api/v1/admin/shop/products')->assertUnauthorized();
});

test('admin products list requires shop permission', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/admin/shop/products')->assertForbidden();
});

test('admin products list returns inactive products too', function () {
    Product::factory()->published()->create(['name' => 'Aktiv']);
    Product::factory()->create(['name' => 'Inaktiv', 'status' => 'draft']);

    Sanctum::actingAs(shopAdminUser());

    $this->getJson('/api/v1/admin/shop/products')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── POST /api/v1/admin/shop/products ─────────────────────────────────────────

test('admin can create product', function () {
    Sanctum::actingAs(shopAdminUser());

    $this->postJson('/api/v1/admin/shop/products', [
        'name'      => 'Ny produkt',
        'type'      => 'physical',
        'price_ore' => 9900,
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Ny produkt');
});

test('create product auto-generates slug', function () {
    Sanctum::actingAs(shopAdminUser());

    $response = $this->postJson('/api/v1/admin/shop/products', [
        'name'      => 'Arm Patch',
        'type'      => 'physical',
        'price_ore' => 4900,
    ])->assertCreated();

    expect($response->json('data.slug'))->toBe('arm-patch');
});

// ── PATCH /api/v1/admin/shop/products/{product} ───────────────────────────────

test('admin can update product', function () {
    $product = Product::factory()->create(['name' => 'Gammalt namn']);
    Sanctum::actingAs(shopAdminUser());

    $this->patchJson("/api/v1/admin/shop/products/{$product->id}", [
        'name' => 'Nytt namn',
    ])->assertOk()->assertJsonPath('data.name', 'Nytt namn');
});

// ── DELETE /api/v1/admin/shop/products/{product} ──────────────────────────────

test('admin can soft-delete product', function () {
    $product = Product::factory()->create();
    Sanctum::actingAs(shopAdminUser());

    $this->deleteJson("/api/v1/admin/shop/products/{$product->id}")
        ->assertOk();

    expect(Product::find($product->id))->toBeNull();
    expect(Product::withTrashed()->find($product->id))->not->toBeNull();
});

// ── POST /api/v1/admin/shop/products/{product}/variants ───────────────────────

test('admin can add variant to product', function () {
    $product = Product::factory()->create();
    Sanctum::actingAs(shopAdminUser());

    $this->postJson("/api/v1/admin/shop/products/{$product->id}/variants", [
        'option_value' => 'Stor',
        'stock'        => 10,
    ])
        ->assertCreated()
        ->assertJsonPath('data.option_value', 'Stor');
});

// ── PATCH /api/v1/admin/shop/products/{product}/variants/{variant} ────────────

test('admin can update variant', function () {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
    Sanctum::actingAs(shopAdminUser());

    $this->patchJson("/api/v1/admin/shop/products/{$product->id}/variants/{$variant->id}", [
        'option_value' => 'XL',
    ])->assertOk()->assertJsonPath('data.option_value', 'XL');
});

test('cannot update variant belonging to different product', function () {
    $product1 = Product::factory()->create();
    $product2 = Product::factory()->create();
    $variant  = ProductVariant::factory()->create(['product_id' => $product2->id]);
    Sanctum::actingAs(shopAdminUser());

    $this->patchJson("/api/v1/admin/shop/products/{$product1->id}/variants/{$variant->id}", [
        'price' => 1,
    ])->assertNotFound();
});

// ── DELETE /api/v1/admin/shop/products/{product}/variants/{variant} ───────────

test('admin can delete variant', function () {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
    Sanctum::actingAs(shopAdminUser());

    $this->deleteJson("/api/v1/admin/shop/products/{$product->id}/variants/{$variant->id}")
        ->assertOk();

    expect(ProductVariant::find($variant->id))->toBeNull();
});

// ── GET /api/v1/admin/shop/orders ─────────────────────────────────────────────

test('admin can list all orders', function () {
    Order::factory()->count(3)->create();
    Sanctum::actingAs(shopAdminUser());

    $this->getJson('/api/v1/admin/shop/orders')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('admin orders list can filter by status', function () {
    Order::factory()->create(['status' => 'pending']);
    Order::factory()->create(['status' => 'paid']);
    Sanctum::actingAs(shopAdminUser());

    $this->getJson('/api/v1/admin/shop/orders?status=paid')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ── PATCH /api/v1/admin/shop/orders/{order} ───────────────────────────────────

test('admin can update order status', function () {
    $order = Order::factory()->create(['status' => 'paid']);
    Sanctum::actingAs(shopAdminUser());

    $this->patchJson("/api/v1/admin/shop/orders/{$order->id}", [
        'status' => 'paid',
    ])->assertOk()->assertJsonPath('status', 'paid');
});
