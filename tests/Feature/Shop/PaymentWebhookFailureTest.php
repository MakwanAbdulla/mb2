<?php

use App\Models\Shop\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ── Stripe ─────────────────────────────────────────────────────────────────

test('stripe webhook marks order as failed on checkout.session.expired', function () {
    Mail::fake();

    $order = Order::factory()->create([
        'status' => Order::STATUS_PENDING,
        'stripe_session_id' => 'cs_test_123',
    ]);

    $this->postJson(route('stripe.webhook'), [
        'type' => 'checkout.session.expired',
        'data' => ['object' => ['id' => 'cs_test_123']],
    ])->assertOk();

    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);
});

test('stripe webhook ignores expired session for unknown order', function () {
    $response = $this->postJson(route('stripe.webhook'), [
        'type' => 'checkout.session.expired',
        'data' => ['object' => ['id' => 'cs_test_does_not_exist']],
    ]);

    $response->assertOk();
});

test('stripe webhook does not fail an already-paid order', function () {
    $order = Order::factory()->create([
        'status' => Order::STATUS_PAID,
        'stripe_session_id' => 'cs_test_456',
    ]);

    $this->postJson(route('stripe.webhook'), [
        'type' => 'checkout.session.expired',
        'data' => ['object' => ['id' => 'cs_test_456']],
    ])->assertOk();

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
});

// ── Swish ──────────────────────────────────────────────────────────────────

test('swish callback marks order as failed on DECLINED', function () {
    Mail::fake();

    $swishId = (string) Str::uuid();
    $order = Order::factory()->create([
        'status' => Order::STATUS_PENDING,
        'swish_payment_id' => $swishId,
    ]);

    $this->postJson(route('swish.callback'), [
        'id' => $swishId,
        'status' => 'DECLINED',
    ])->assertOk();

    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);
});

test('swish callback marks order as failed on ERROR', function () {
    Mail::fake();

    $swishId = (string) Str::uuid();
    $order = Order::factory()->create([
        'status' => Order::STATUS_PENDING,
        'swish_payment_id' => $swishId,
    ]);

    $this->postJson(route('swish.callback'), [
        'id' => $swishId,
        'status' => 'ERROR',
    ])->assertOk();

    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);
});

test('swish callback does not touch order on a non-terminal status', function () {
    $swishId = (string) Str::uuid();
    $order = Order::factory()->create([
        'status' => Order::STATUS_PENDING,
        'swish_payment_id' => $swishId,
    ]);

    $this->postJson(route('swish.callback'), [
        'id' => $swishId,
        'status' => 'CREATED',
    ])->assertOk();

    expect($order->fresh()->status)->toBe(Order::STATUS_PENDING);
});
