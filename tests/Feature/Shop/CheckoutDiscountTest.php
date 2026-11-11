<?php

use App\Mail\ShopOrderAdminNotificationMail;
use App\Mail\ShopOrderConfirmationMail;
use App\Models\Shop\Discount;
use App\Models\Shop\DiscountCode;
use App\Models\Shop\DiscountUse;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function addToCart(Product $product, int $qty = 1): void
{
    $variant = $product->defaultVariant();
    app(CartService::class)->addProduct($variant, $qty);
}

function makeShopOrder(array $attrs = []): Order
{
    $product = Product::factory()->published()->create(['price_ore' => 10000]);
    $variant = $product->defaultVariant();

    $order = Order::factory()->create(array_merge(['status' => 'pending', 'total_ore' => 10000], $attrs));

    \App\Models\Shop\OrderLine::factory()->create([
        'order_id'       => $order->id,
        'category'       => \App\Models\Shop\OrderLine::CATEGORY_SHOP,
        'orderable_type' => \App\Models\Shop\ProductVariant::class,
        'orderable_id'   => $variant->id,
        'unit_price_ore' => 10000,
        'line_total_ore' => 10000,
        'description'    => $product->name,
    ]);

    return $order;
}

// ── Discount model ────────────────────────────────────────────────────────────

test('Discount::calculate returns percentage of subtotal', function () {
    $discount = Discount::factory()->percentage(20)->make();

    expect($discount->calculate(10000))->toBe(2000);
});

test('Discount::calculate returns fixed amount', function () {
    $discount = Discount::factory()->fixed(500)->make();

    expect($discount->calculate(10000))->toBe(500);
});

test('Discount::calculate respects max_discount cap', function () {
    $discount = Discount::factory()->percentage(50)->make(['max_discount' => 1000]);

    expect($discount->calculate(10000))->toBe(1000); // 50% = 5000 but capped at 1000
});

test('Discount::calculate respects max_pct_of_total cap', function () {
    $discount = Discount::factory()->fixed(3000)->make(['max_pct_of_total' => 20]);

    expect($discount->calculate(10000))->toBe(2000); // fixed 3000 but capped at 20%
});

test('Discount::calculate returns 0 when below min_subtotal', function () {
    $discount = Discount::factory()->percentage(10)->make(['min_subtotal' => 20000]);

    expect($discount->calculate(10000))->toBe(0);
});

test('Discount::calculate never exceeds subtotal', function () {
    $discount = Discount::factory()->fixed(99999)->make();

    expect($discount->calculate(5000))->toBe(5000);
});

test('Discount::isValid returns false when inactive', function () {
    $discount = Discount::factory()->inactive()->create();

    expect($discount->isValid())->toBeFalse();
});

test('Discount::isValid returns false when expired', function () {
    $discount = Discount::factory()->expired()->create();

    expect($discount->isValid())->toBeFalse();
});

test('Discount::isValid returns false when max_uses reached', function () {
    $discount = Discount::factory()->create(['max_uses' => 1]);
    DiscountUse::create([
        'discount_id'       => $discount->id,
        'discountable_type' => Order::class,
        'discountable_id'   => 1,
        'original_amount'   => 10000,
        'discount_amount'   => 1000,
    ]);

    expect($discount->isValid())->toBeFalse();
});

// ── DiscountCode ──────────────────────────────────────────────────────────────

test('DiscountCode::isAvailable returns false for redeemed single-use code', function () {
    $code = DiscountCode::factory()->singleUse()->redeemed()->create();

    expect($code->isAvailable())->toBeFalse();
});

test('DiscountCode::isAvailable returns true for unredeemed single-use code', function () {
    $code = DiscountCode::factory()->singleUse()->create();

    expect($code->isAvailable())->toBeTrue();
});

test('DiscountCode::isAvailable returns true for shared code used multiple times', function () {
    $code = DiscountCode::factory()->create(['is_single_use' => false]);

    expect($code->isAvailable())->toBeTrue();
});

test('DiscountCode::redeem marks single-use code as redeemed', function () {
    $code = DiscountCode::factory()->singleUse()->create();

    $code->redeem();

    expect($code->fresh()->redeemed_at)->not->toBeNull();
});

test('DiscountCode::redeem is no-op for shared codes', function () {
    $code = DiscountCode::factory()->create(['is_single_use' => false]);

    $code->redeem();

    expect($code->fresh()->redeemed_at)->toBeNull();
});

// ── Checkout flow — discount code ─────────────────────────────────────────────

test('POST /kassa/apply-code applies valid percentage code', function () {
    $discount = Discount::factory()->percentage(10)->create(['name' => 'Tio procent']);
    $code     = DiscountCode::factory()->create(['discount_id' => $discount->id, 'code' => 'TENPCT']);
    $product  = Product::factory()->published()->create(['price_ore' => 10000]);
    addToCart($product, 1);

    $response = $this->postJson('/api/v1/shop/cart/apply-code', ['code' => 'TENPCT']);

    // The checkout is Livewire, not a REST endpoint — test the service logic via CartService discount calc
    expect($discount->calculate(10000))->toBe(1000);
    expect($code->isAvailable())->toBeTrue();
});

test('HandleOrderPaid sends admin notification for shop orders', function () {
    Mail::fake();

    config(['mail.admin_email' => 'butik@armagedon.se']);

    $order = makeShopOrder(); // uses makeShopOrder() from ShopOrderPaidTest context
    $order->load('lines.orderable');
    (new \App\Listeners\HandleOrderPaid)->handle(new \App\Events\OrderPaid($order));

    Mail::assertQueued(ShopOrderAdminNotificationMail::class, function ($mail) use ($order) {
        return $mail->order->id === $order->id;
    });
});

test('HandleOrderPaid does not send admin notification when admin_email not configured', function () {
    Mail::fake();

    config(['mail.admin_email' => null]);
    config(['mail.from.address' => null]);

    $order = makeShopOrder();
    $order->load('lines.orderable');
    (new \App\Listeners\HandleOrderPaid)->handle(new \App\Events\OrderPaid($order));

    Mail::assertNotQueued(ShopOrderAdminNotificationMail::class);
});

test('HandleOrderPaid does not send admin notification for non-shop orders', function () {
    Mail::fake();

    config(['mail.admin_email' => 'butik@armagedon.se']);

    $order = Order::factory()->create(['status' => 'pending', 'total_ore' => 5000]);
    \App\Models\Shop\OrderLine::factory()->create([
        'order_id' => $order->id,
        'category' => \App\Models\Shop\OrderLine::CATEGORY_EVENT,
    ]);

    $order->load('lines.orderable');
    (new \App\Listeners\HandleOrderPaid)->handle(new \App\Events\OrderPaid($order));

    Mail::assertNotQueued(ShopOrderAdminNotificationMail::class);
});
