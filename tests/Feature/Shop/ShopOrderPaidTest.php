<?php

use App\Events\OrderPaid;
use App\Listeners\HandleOrderPaid;
use App\Mail\ShopOrderConfirmationMail;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use App\Models\Shop\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function shopOrder(array $attrs = []): Order
{
    $product = Product::factory()->published()->create(['price_ore' => 10000]);
    $variant = $product->defaultVariant();

    $order = Order::factory()->create(array_merge(['status' => 'pending', 'total_ore' => 10000], $attrs));

    OrderLine::factory()->create([
        'order_id'       => $order->id,
        'category'       => OrderLine::CATEGORY_SHOP,
        'orderable_type' => \App\Models\Shop\ProductVariant::class,
        'orderable_id'   => $variant->id,
        'unit_price_ore' => 10000,
        'line_total_ore' => 10000,
        'description'    => $product->name,
    ]);

    return $order;
}

test('HandleOrderPaid sends shop order confirmation when order has shop lines', function () {
    Mail::fake();

    $user  = User::factory()->create(['email' => 'buyer@example.com']);
    $order = shopOrder(['user_id' => $user->id]);

    $order->load('lines.orderable');
    (new HandleOrderPaid)->handle(new OrderPaid($order));

    Mail::assertQueued(ShopOrderConfirmationMail::class, function ($mail) use ($order) {
        return $mail->order->id === $order->id;
    });
});

test('HandleOrderPaid sends confirmation to guest email when no user', function () {
    Mail::fake();

    $order = shopOrder([
        'user_id'     => null,
        'guest_name'  => 'Guest Name',
        'guest_email' => 'guest@example.com',
    ]);

    $order->load('lines.orderable');
    (new HandleOrderPaid)->handle(new OrderPaid($order));

    Mail::assertQueued(ShopOrderConfirmationMail::class);
});

test('HandleOrderPaid does not send shop mail for non-shop orders', function () {
    Mail::fake();

    $order = Order::factory()->create(['status' => 'pending', 'total_ore' => 5000]);
    OrderLine::factory()->create([
        'order_id' => $order->id,
        'category' => OrderLine::CATEGORY_EVENT,
    ]);

    $order->load('lines.orderable');
    (new HandleOrderPaid)->handle(new OrderPaid($order));

    Mail::assertNotQueued(ShopOrderConfirmationMail::class);
});

test('HandleOrderPaid does not send shop mail when no email available', function () {
    Mail::fake();

    // Order with no user and no guest_email
    $order = shopOrder(['user_id' => null, 'guest_email' => null]);

    $order->load('lines.orderable');
    (new HandleOrderPaid)->handle(new OrderPaid($order));

    Mail::assertNotQueued(ShopOrderConfirmationMail::class);
});
