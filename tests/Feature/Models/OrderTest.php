<?php

use App\Events\OrderPaid;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('payment reference is auto-generated on create', function () {
    $order = Order::factory()->create();

    expect($order->fresh()->payment_reference)->toMatch('/^ARM-\d{5}$/');
});

test('payment reference zero-pads the id to 5 digits', function () {
    $order = Order::factory()->create();

    $expected = 'ARM-' . str_pad($order->id, 5, '0', STR_PAD_LEFT);
    expect($order->fresh()->payment_reference)->toBe($expected);
});

test('ocr number is auto-generated on create', function () {
    $order = Order::factory()->create();

    expect($order->fresh()->ocr_number)->not->toBeEmpty()
        ->and(is_numeric($order->fresh()->ocr_number))->toBeTrue();
});

test('ocr number passes luhn check', function () {
    $order = Order::factory()->create();
    $ocr = $order->fresh()->ocr_number;

    // Verify Luhn check digit
    $digits = str_split($ocr);
    $sum = 0;
    $double = false;
    foreach (array_reverse($digits) as $digit) {
        $n = (int) $digit;
        if ($double) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
        $double = ! $double;
    }

    expect($sum % 10)->toBe(0);
});

test('generateOcr is deterministic', function () {
    expect(Order::generateOcr(42, 2026))->toBe(Order::generateOcr(42, 2026));
});

test('isPending returns true for pending status', function () {
    $order = Order::factory()->create(['status' => Order::STATUS_PENDING]);

    expect($order->isPending())->toBeTrue()
        ->and($order->isPaid())->toBeFalse();
});

test('isPaid returns true for paid status', function () {
    $order = Order::factory()->paid()->create();

    expect($order->isPaid())->toBeTrue()
        ->and($order->isPending())->toBeFalse();
});

test('markPaid sets status and payment method and fires event', function () {
    Event::fake([OrderPaid::class]);

    $order = Order::factory()->create();
    $order->markPaid(Order::METHOD_STRIPE);

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID)
        ->and($order->fresh()->payment_method)->toBe(Order::METHOD_STRIPE)
        ->and($order->fresh()->paid_at)->not->toBeNull();

    Event::assertDispatched(OrderPaid::class, fn ($e) => $e->order->id === $order->id);
});

test('order belongs to user', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);

    expect($order->user)->toBeInstanceOf(User::class)
        ->and($order->user->id)->toBe($user->id);
});

test('order has many lines', function () {
    $order = Order::factory()->create();
    OrderLine::factory()->count(3)->create(['order_id' => $order->id]);

    expect($order->lines)->toHaveCount(3);
});

test('order status constants are defined', function () {
    expect(Order::STATUS_PENDING)->toBe('pending')
        ->and(Order::STATUS_PAID)->toBe('paid')
        ->and(Order::STATUS_REFUNDED)->toBe('refunded')
        ->and(Order::STATUS_CANCELLED)->toBe('cancelled');
});

test('order payment method constants are defined', function () {
    expect(Order::METHOD_STRIPE)->toBe('stripe')
        ->and(Order::METHOD_BANKGIRO)->toBe('bankgiro')
        ->and(Order::METHOD_MANUAL)->toBe('manual')
        ->and(Order::METHOD_FREE)->toBe('free');
});
