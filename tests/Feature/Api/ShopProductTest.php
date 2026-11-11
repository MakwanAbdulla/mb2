<?php

use App\Models\Membership\Member;
use App\Models\Shop\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── GET /api/v1/shop/products ─────────────────────────────────────────────────

test('product list returns only published products', function () {
    Product::factory()->published()->create(['name' => 'Published']);
    Product::factory()->create(['name' => 'Draft', 'status' => Product::STATUS_DRAFT]);
    Product::factory()->archived()->create(['name' => 'Archived']);

    $this->getJson('/api/v1/shop/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Published');
});

test('product list is public for unauthenticated users', function () {
    Product::factory()->published()->count(3)->create();

    $this->getJson('/api/v1/shop/products')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('product list hides member-only products from guests', function () {
    Product::factory()->published()->create();
    Product::factory()->published()->membersOnly()->create();

    $this->getJson('/api/v1/shop/products')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('product list shows member-only products to active members', function () {
    Product::factory()->published()->count(2)->create();
    Product::factory()->published()->membersOnly()->create();

    $user   = User::factory()->create();
    $member = Member::factory()->active()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/shop/products')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('product list hides member-only products from non-members', function () {
    Product::factory()->published()->create();
    Product::factory()->published()->membersOnly()->create();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/shop/products')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('product list supports search filter', function () {
    Product::factory()->published()->create(['name' => 'Armagedon T-shirt']);
    Product::factory()->published()->create(['name' => 'Mugg']);

    $this->getJson('/api/v1/shop/products?search=shirt')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Armagedon T-shirt');
});

test('product list supports upsell filter', function () {
    Product::factory()->published()->showInEventCheckout()->create();
    Product::factory()->published()->create(); // not an upsell product

    $this->getJson('/api/v1/shop/products?upsell=1')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('product list includes correct resource fields', function () {
    Product::factory()->published()->create();

    $this->getJson('/api/v1/shop/products')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [[
                'id', 'name', 'slug', 'description', 'type', 'status',
                'price_ore', 'price_formatted', 'requires_membership',
                'show_in_event_checkout', 'track_stock', 'is_available',
                'has_variants', 'image_url', 'sort_order', 'variants',
                'created_at', 'updated_at',
            ]],
            'meta', 'links',
        ]);
});

test('product list paginates with per_page', function () {
    Product::factory()->published()->count(10)->create();

    $this->getJson('/api/v1/shop/products?per_page=3')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 10);
});

// ── GET /api/v1/shop/products/{slug} ─────────────────────────────────────────

test('product show returns published product', function () {
    $product = Product::factory()->published()->create();

    $this->getJson("/api/v1/shop/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.slug', $product->slug);
});

test('product show returns 404 for draft product', function () {
    $product = Product::factory()->create(['status' => Product::STATUS_DRAFT]);

    $this->getJson("/api/v1/shop/products/{$product->slug}")
        ->assertNotFound();
});

test('product show returns 403 for member-only product when guest', function () {
    $product = Product::factory()->published()->membersOnly()->create();

    $this->getJson("/api/v1/shop/products/{$product->slug}")
        ->assertForbidden();
});

test('product show returns 403 for member-only product when non-member', function () {
    $product = Product::factory()->published()->membersOnly()->create();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/v1/shop/products/{$product->slug}")
        ->assertForbidden();
});

test('product show returns member-only product to active member', function () {
    $product = Product::factory()->published()->membersOnly()->create();
    $user    = User::factory()->create();
    Member::factory()->active()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/shop/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.id', $product->id);
});

test('product show includes variants', function () {
    $product = Product::factory()->published()->withVariants('Storlek', ['S', 'M', 'L'])->create();

    $this->getJson("/api/v1/shop/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.has_variants', true)
        ->assertJsonCount(3, 'data.variants');
});

test('product show price_formatted uses swedish format', function () {
    $product = Product::factory()->published()->create(['price_ore' => 24900]);

    $this->getJson("/api/v1/shop/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.price_ore', 24900)
        ->assertJsonPath('data.price_formatted', '249,00 kr');
});
