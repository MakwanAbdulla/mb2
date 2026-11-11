<?php

use App\Events\OrderPaid;
use App\Listeners\HandleOrderPaid;
use App\Mail\EventRegistrationMail;
use App\Models\Events\Event;
use App\Models\Events\EventPriceTier;
use App\Models\Events\EventRegistration;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Create an order with a single event order line.
 */
function eventOrder(array $orderAttributes = []): Order
{
    $order = Order::factory()->create(array_merge(['status' => 'pending'], $orderAttributes));

    OrderLine::factory()->create([
        'order_id'       => $order->id,
        'category'       => OrderLine::CATEGORY_EVENT,
        'unit_price_ore' => $order->total_ore,
        'line_total_ore' => $order->total_ore,
    ]);

    return $order;
}

// ── HandleOrderPaid: event branch ─────────────────────────────────────────────

test('HandleOrderPaid confirms pending_payment registration', function () {
    $event = Event::factory()->published()->admissionOnPayment()->create();
    $tier  = EventPriceTier::factory()->withPrice(10000)->create(['event_id' => $event->id]);
    $order = eventOrder(['total_ore' => 10000]);

    $registration = EventRegistration::factory()->pendingPayment()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'order_id'      => $order->id,
    ]);

    $order->load('lines.orderable');
    (new HandleOrderPaid)->handle(new OrderPaid($order));

    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test('HandleOrderPaid skips already-confirmed registration', function () {
    $event = Event::factory()->published()->admissionOnPayment()->create();
    $tier  = EventPriceTier::factory()->withPrice(10000)->create(['event_id' => $event->id]);
    $order = eventOrder(['total_ore' => 10000]);

    $registration = EventRegistration::factory()->confirmed()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'order_id'      => $order->id,
    ]);

    $order->load('lines.orderable');
    (new HandleOrderPaid)->handle(new OrderPaid($order));

    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test('HandleOrderPaid queues confirmation mail to registration email', function () {
    Mail::fake();

    $user  = User::factory()->create();
    $event = Event::factory()->published()->admissionOnPayment()->create();
    $tier  = EventPriceTier::factory()->withPrice(10000)->create(['event_id' => $event->id]);
    $order = eventOrder(['total_ore' => 10000, 'user_id' => $user->id]);

    EventRegistration::factory()->pendingPayment()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'order_id'      => $order->id,
        'user_id'       => $user->id,
    ]);

    $order->load('lines.orderable');
    (new HandleOrderPaid)->handle(new OrderPaid($order));

    Mail::assertQueued(EventRegistrationMail::class, fn ($mail) =>
        $mail->recipientEmail() === $user->email
    );
});

// ── markPaid() end-to-end ─────────────────────────────────────────────────────

test('markPaid(manual) confirms event registration end-to-end', function () {
    Mail::fake();

    $user  = User::factory()->create();
    $event = Event::factory()->published()->admissionOnPayment()->create();
    $tier  = EventPriceTier::factory()->withPrice(5000)->create(['event_id' => $event->id]);
    $order = eventOrder(['total_ore' => 5000, 'user_id' => $user->id]);

    $registration = EventRegistration::factory()->pendingPayment()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'order_id'      => $order->id,
        'user_id'       => $user->id,
    ]);

    $order->markPaid(Order::METHOD_MANUAL);

    expect($order->fresh())->status->toBe('paid');
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
    Mail::assertQueued(EventRegistrationMail::class);
});

test('markPaid(bankgiro) confirms event registration end-to-end', function () {
    Mail::fake();

    $user  = User::factory()->create();
    $event = Event::factory()->published()->admissionOnPayment()->create();
    $tier  = EventPriceTier::factory()->withPrice(20000)->create(['event_id' => $event->id]);
    $order = eventOrder(['total_ore' => 20000, 'user_id' => $user->id]);

    $registration = EventRegistration::factory()->pendingPayment()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'order_id'      => $order->id,
        'user_id'       => $user->id,
    ]);

    $order->markPaid(Order::METHOD_BANKGIRO);

    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
    Mail::assertQueued(EventRegistrationMail::class);
});

test('markPaid is idempotent — second call does not queue extra mail', function () {
    Mail::fake();

    $user  = User::factory()->create();
    $event = Event::factory()->published()->admissionOnPayment()->create();
    $tier  = EventPriceTier::factory()->withPrice(10000)->create(['event_id' => $event->id]);
    $order = eventOrder(['total_ore' => 10000, 'user_id' => $user->id]);

    EventRegistration::factory()->pendingPayment()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'order_id'      => $order->id,
        'user_id'       => $user->id,
    ]);

    $order->markPaid(Order::METHOD_MANUAL);
    $order->markPaid(Order::METHOD_MANUAL); // already paid — listener fires but skips

    Mail::assertQueued(EventRegistrationMail::class, 1);
});
