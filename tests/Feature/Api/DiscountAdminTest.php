<?php

use App\Models\Shop\Discount;
use App\Models\Shop\DiscountCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('discounts.view');
    $this->createPermission('discounts.manage');
});

function discountsAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('discounts.manage');
    return $user;
}

function makeDiscount(bool $active = true): Discount
{
    return Discount::factory()->create(['is_active' => $active]);
}

// ── GET /api/v1/admin/discounts ───────────────────────────────────────────────

test('admin discounts list requires auth', function () {
    $this->getJson('/api/v1/admin/discounts')->assertUnauthorized();
});

test('admin discounts list requires shop.manage permission', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/admin/discounts')->assertForbidden();
});

test('admin can list all discounts', function () {
    makeDiscount(true);
    makeDiscount(false);
    Sanctum::actingAs(discountsAdminUser());

    $this->getJson('/api/v1/admin/discounts?include_inactive=1')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('admin can filter discounts by active status', function () {
    makeDiscount(true);
    makeDiscount(false);
    Sanctum::actingAs(discountsAdminUser());

    $this->getJson('/api/v1/admin/discounts')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ── POST /api/v1/admin/discounts ──────────────────────────────────────────────

test('admin can create discount', function () {
    Sanctum::actingAs(discountsAdminUser());

    $this->postJson('/api/v1/admin/discounts', [
        'name'        => '10% rabatt',
        'type'        => 'percentage',
        'value'       => 1000,
        'applies_to'  => 'all',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', '10% rabatt')
        ->assertJsonPath('data.type', 'percentage');
});

test('create discount requires shop.manage permission', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/admin/discounts', [
        'name' => 'Test', 'type' => 'fixed', 'value' => 500, 'applies_to' => 'all',
    ])->assertForbidden();
});

// ── GET /api/v1/admin/discounts/{discount} ────────────────────────────────────

test('admin can get discount detail', function () {
    $discount = makeDiscount();
    Sanctum::actingAs(discountsAdminUser());

    $this->getJson("/api/v1/admin/discounts/{$discount->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $discount->id);
});

// ── PATCH /api/v1/admin/discounts/{discount} ──────────────────────────────────

test('admin can update discount', function () {
    $discount = makeDiscount();
    Sanctum::actingAs(discountsAdminUser());

    $this->patchJson("/api/v1/admin/discounts/{$discount->id}", [
        'is_active' => false,
    ])->assertOk()->assertJsonPath('data.is_active', false);
});

// ── DELETE /api/v1/admin/discounts/{discount} ─────────────────────────────────

test('admin can delete discount', function () {
    $discount = makeDiscount();
    Sanctum::actingAs(discountsAdminUser());

    $this->deleteJson("/api/v1/admin/discounts/{$discount->id}")->assertOk();

    expect(Discount::find($discount->id))->toBeNull();
});

// ── POST /api/v1/admin/discounts/{discount}/codes ─────────────────────────────

test('admin can add promo code to discount', function () {
    $discount = makeDiscount();
    Sanctum::actingAs(discountsAdminUser());

    $this->postJson("/api/v1/admin/discounts/{$discount->id}/codes", [
        'code'          => 'SUMMER25',
        'is_single_use' => false,
    ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'SUMMER25');
});

// ── DELETE /api/v1/admin/discounts/{discount}/codes/{code} ────────────────────

test('admin can delete promo code', function () {
    $discount = makeDiscount();
    $code     = DiscountCode::factory()->create(['discount_id' => $discount->id]);
    Sanctum::actingAs(discountsAdminUser());

    $this->deleteJson("/api/v1/admin/discounts/{$discount->id}/codes/{$code->id}")
        ->assertOk();

    expect(DiscountCode::find($code->id))->toBeNull();
});

test('cannot delete code belonging to different discount', function () {
    $discount1 = makeDiscount();
    $discount2 = makeDiscount();
    $code      = DiscountCode::factory()->create(['discount_id' => $discount2->id]);
    Sanctum::actingAs(discountsAdminUser());

    $this->deleteJson("/api/v1/admin/discounts/{$discount1->id}/codes/{$code->id}")
        ->assertNotFound();
});
