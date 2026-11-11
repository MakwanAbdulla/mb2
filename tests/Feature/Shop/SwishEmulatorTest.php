<?php

use App\Models\Shop\Order;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function withSwishEmulator(callable $callback): void
{
    SystemSetting::set('payment.swish_emulator_enabled', '1');

    $callback();
}

test('checkout redirects to the emulator when the emulator setting is enabled', function () {
    withSwishEmulator(function () {
        $user = User::factory()->create();
        $order = Order::factory()->create(['status' => Order::STATUS_PENDING, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('payment.swish.checkout'), ['order_id' => $order->id]);

        $response->assertRedirect(route('payment.swish.emulator'));
        expect($order->fresh()->swish_payment_id)->not->toBeNull();
    });
});

test('emulator routes 404 when the emulator is not enabled', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['status' => Order::STATUS_PENDING, 'user_id' => $user->id]);

    $this->actingAs($user)->withSession(['pending_order_id' => $order->id]);

    $this->get(route('payment.swish.emulator'))->assertNotFound();
    $this->post(route('payment.swish.emulator.approve'))->assertNotFound();
    $this->post(route('payment.swish.emulator.decline'))->assertNotFound();
});

test('emulator page renders order details when enabled', function () {
    withSwishEmulator(function () {
        $user = User::factory()->create();
        $order = Order::factory()->create(['status' => Order::STATUS_PENDING, 'user_id' => $user->id]);

        $this->actingAs($user)->withSession(['pending_order_id' => $order->id]);

        $this->get(route('payment.swish.emulator'))
            ->assertOk()
            ->assertSee($order->payment_reference);
    });
});

test('emulator approve marks the order as paid via the real OrderPaid flow', function () {
    withSwishEmulator(function () {
        $user = User::factory()->create();
        $order = Order::factory()->create(['status' => Order::STATUS_PENDING, 'user_id' => $user->id]);

        $this->actingAs($user)->withSession(['pending_order_id' => $order->id]);

        $response = $this->post(route('payment.swish.emulator.approve'));

        $response->assertRedirect();
        expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
        expect($order->fresh()->payment_method)->toBe(Order::METHOD_SWISH);
    });
});

test('emulator decline marks the order as failed via the real OrderPaymentFailed flow', function () {
    withSwishEmulator(function () {
        Mail::fake();

        $user = User::factory()->create();
        $order = Order::factory()->create(['status' => Order::STATUS_PENDING, 'user_id' => $user->id]);

        $this->actingAs($user)->withSession(['pending_order_id' => $order->id]);

        $response = $this->post(route('payment.swish.emulator.decline'));

        $response->assertRedirect(route('membership.payment'));
        expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);
    });
});
