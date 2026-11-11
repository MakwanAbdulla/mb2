<?php

use App\Models\Shop\Discount;
use App\Models\Shop\DiscountCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function apiDiscount(array $attrs = []): Discount
{
    return Discount::create(array_merge([
        'name'       => 'Testrabatt',
        'type'       => Discount::TYPE_FIXED,
        'value'      => 100_00,
        'applies_to' => Discount::APPLIES_ALL,
        'is_active'  => true,
    ], $attrs));
}

function apiDiscountCode(Discount $discount, string $code = 'TEST10'): DiscountCode
{
    return DiscountCode::create([
        'discount_id'   => $discount->id,
        'code'          => $code,
        'is_single_use' => false,
    ]);
}

// ── POST /api/v1/discounts/validate ──────────────────────────────────────────

test('validate returns discount info for valid code', function () {
    $discount = apiDiscount(['value' => 100_00]);
    apiDiscountCode($discount, 'VALID10');

    $this->postJson('/api/v1/discounts/validate', [
        'code'     => 'VALID10',
        'subtotal' => 500_00,
    ])
    ->assertOk()
    ->assertJsonPath('code', 'VALID10')
    ->assertJsonPath('discount_ore', 100_00);
});

test('validate returns 422 for unknown code', function () {
    $this->postJson('/api/v1/discounts/validate', [
        'code'     => 'NOEXIST',
        'subtotal' => 500_00,
    ])->assertUnprocessable();
});

test('validate returns 422 for inactive discount', function () {
    $discount = apiDiscount(['is_active' => false]);
    apiDiscountCode($discount, 'INACTIVE');

    $this->postJson('/api/v1/discounts/validate', [
        'code'     => 'INACTIVE',
        'subtotal' => 500_00,
    ])->assertUnprocessable();
});

test('validate returns 422 for expired discount', function () {
    $discount = apiDiscount(['valid_until' => now()->subDay()->toDateString()]);
    apiDiscountCode($discount, 'EXPIRED');

    $this->postJson('/api/v1/discounts/validate', [
        'code'     => 'EXPIRED',
        'subtotal' => 500_00,
    ])->assertUnprocessable();
});

test('validate calculates percentage discount correctly', function () {
    $discount = apiDiscount(['type' => Discount::TYPE_PERCENTAGE, 'value' => 10]);
    apiDiscountCode($discount, 'PCT10');

    $this->postJson('/api/v1/discounts/validate', [
        'code'     => 'PCT10',
        'subtotal' => 1000_00,
    ])
    ->assertOk()
    ->assertJsonPath('discount_ore', 100_00);
});

test('validate respects max_discount cap', function () {
    $discount = apiDiscount([
        'type'         => Discount::TYPE_PERCENTAGE,
        'value'        => 50,
        'max_discount' => 50_00,
    ]);
    apiDiscountCode($discount, 'CAPPED');

    $this->postJson('/api/v1/discounts/validate', [
        'code'     => 'CAPPED',
        'subtotal' => 500_00,
    ])
    ->assertOk()
    ->assertJsonPath('discount_ore', 50_00);
});

test('validate rejects code when applies_to does not match', function () {
    $discount = apiDiscount(['applies_to' => Discount::APPLIES_MEMBERSHIP]);
    apiDiscountCode($discount, 'MEMBER');

    $this->postJson('/api/v1/discounts/validate', [
        'code'       => 'MEMBER',
        'subtotal'   => 500_00,
        'applies_to' => 'shop',
    ])->assertUnprocessable();
});

test('validate accepts code when applies_to matches', function () {
    $discount = apiDiscount(['applies_to' => Discount::APPLIES_SHOP]);
    apiDiscountCode($discount, 'SHOP10');

    $this->postJson('/api/v1/discounts/validate', [
        'code'       => 'SHOP10',
        'subtotal'   => 500_00,
        'applies_to' => 'shop',
    ])->assertOk();
});

test('validate returns formatted discount amount', function () {
    $discount = apiDiscount(['value' => 100_00]);
    apiDiscountCode($discount, 'FMT');

    $this->postJson('/api/v1/discounts/validate', [
        'code'     => 'FMT',
        'subtotal' => 500_00,
    ])
    ->assertOk()
    ->assertJsonPath('discount_formatted', '100,00 kr');
});

test('validate requires code and subtotal fields', function () {
    $this->postJson('/api/v1/discounts/validate', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code', 'subtotal']);
});
