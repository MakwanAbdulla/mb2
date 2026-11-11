<?php

use App\Models\Events\Event;
use App\Models\Events\EventPriceTier;
use App\Models\Events\EventRegistration;
use App\Models\Shop\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── approve() ─────────────────────────────────────────────────────────────────

test('approve confirms free registration', function () {
    $event = Event::factory()->published()->admissionManual()->create();
    $tier  = EventPriceTier::factory()->free()->create(['event_id' => $event->id]);
    $order = Order::factory()->create(['total_ore' => 0, 'status' => 'paid']);

    $reg = EventRegistration::factory()->pendingApproval()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'order_id'      => $order->id,
    ]);

    $reg->load('order');
    $reg->approve();

    expect($reg->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test('approve sends paid registration to pending_payment', function () {
    $event = Event::factory()->published()->admissionManual()->create();
    $tier  = EventPriceTier::factory()->withPrice(10000)->create(['event_id' => $event->id]);
    $order = Order::factory()->create(['total_ore' => 10000, 'status' => 'pending']);

    $reg = EventRegistration::factory()->pendingApproval()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'order_id'      => $order->id,
    ]);

    $reg->load('order');
    $reg->approve();

    expect($reg->fresh()->status)->toBe(EventRegistration::STATUS_PENDING_PAYMENT);
});

test('approve is idempotent on already-paid order', function () {
    $event = Event::factory()->published()->admissionManual()->create();
    $tier  = EventPriceTier::factory()->withPrice(10000)->create(['event_id' => $event->id]);
    $order = Order::factory()->create(['total_ore' => 10000, 'status' => 'paid', 'paid_at' => now()]);

    $reg = EventRegistration::factory()->pendingApproval()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'order_id'      => $order->id,
    ]);

    $reg->load('order');
    $reg->approve();

    expect($reg->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

// ── reject() ──────────────────────────────────────────────────────────────────

test('reject cancels a pending_approval registration', function () {
    $reg = EventRegistration::factory()->pendingApproval()->create();

    $reg->reject();

    $fresh = $reg->fresh();
    expect($fresh->status)->toBe(EventRegistration::STATUS_CANCELLED);
    expect($fresh->cancelled_at)->not->toBeNull();
});

test('reject cancels a waitlisted registration', function () {
    $reg = EventRegistration::factory()->waitlisted()->create();

    $reg->reject();

    expect($reg->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);
});

// ── promoteNextWaitlisted() ───────────────────────────────────────────────────

test('promoteNextWaitlisted returns null when waitlist is disabled', function () {
    $event = Event::factory()->published()->create(['waitlist_mode' => Event::WAITLIST_NONE]);
    $tier  = EventPriceTier::factory()->create(['event_id' => $event->id]);

    $confirmed = EventRegistration::factory()->confirmed()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
    ]);

    expect($confirmed->promoteNextWaitlisted())->toBeNull();
});

test('promoteNextWaitlisted returns null when no waitlisted registrations', function () {
    $event = Event::factory()->published()->withWaitlist()->create();
    $tier  = EventPriceTier::factory()->create(['event_id' => $event->id]);

    $confirmed = EventRegistration::factory()->confirmed()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
    ]);

    expect($confirmed->promoteNextWaitlisted())->toBeNull();
});

test('promoteNextWaitlisted promotes to pending_payment for on_payment event', function () {
    $event = Event::factory()->published()->withWaitlist()->admissionOnPayment()->create();
    $tier  = EventPriceTier::factory()->withPrice(5000)->create(['event_id' => $event->id]);

    $order = Order::factory()->create(['total_ore' => 5000, 'status' => 'pending']);

    $confirmed = EventRegistration::factory()->confirmed()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
    ]);
    $waitlisted = EventRegistration::factory()->waitlisted()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'order_id'      => $order->id,
    ]);

    $promoted = $confirmed->promoteNextWaitlisted();

    expect($promoted)->not->toBeNull();
    expect($promoted->id)->toBe($waitlisted->id);
    expect($waitlisted->fresh()->status)->toBe(EventRegistration::STATUS_PENDING_PAYMENT);
});

test('promoteNextWaitlisted promotes to confirmed for automatic event', function () {
    $event = Event::factory()->published()->withWaitlist()->admissionAutomatic()->create();
    $tier  = EventPriceTier::factory()->withPrice(5000)->create(['event_id' => $event->id]);

    $confirmed  = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'price_tier_id' => $tier->id]);
    $waitlisted = EventRegistration::factory()->waitlisted()->create(['event_id' => $event->id, 'price_tier_id' => $tier->id]);

    $confirmed->promoteNextWaitlisted();

    expect($waitlisted->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test('promoteNextWaitlisted promotes to pending_approval for manual event', function () {
    $event = Event::factory()->published()->withWaitlist()->admissionManual()->create();
    $tier  = EventPriceTier::factory()->create(['event_id' => $event->id]);

    $confirmed  = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'price_tier_id' => $tier->id]);
    $waitlisted = EventRegistration::factory()->waitlisted()->create(['event_id' => $event->id, 'price_tier_id' => $tier->id]);

    $confirmed->promoteNextWaitlisted();

    expect($waitlisted->fresh()->status)->toBe(EventRegistration::STATUS_PENDING_APPROVAL);
});

test('promoteNextWaitlisted only promotes from same tier', function () {
    $event  = Event::factory()->published()->withWaitlist()->admissionOnPayment()->create();
    $tierA  = EventPriceTier::factory()->withPrice(5000)->create(['event_id' => $event->id]);
    $tierB  = EventPriceTier::factory()->withPrice(5000)->create(['event_id' => $event->id]);

    $order = Order::factory()->create(['total_ore' => 5000, 'status' => 'pending']);

    $confirmedA  = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'price_tier_id' => $tierA->id]);
    $waitlistedB = EventRegistration::factory()->waitlisted()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tierB->id,
        'order_id'      => $order->id,
    ]);

    // Cancelling in tier A should not promote the tier B waitlist
    $result = $confirmedA->promoteNextWaitlisted();
    expect($result)->toBeNull();
    expect($waitlistedB->fresh()->status)->toBe(EventRegistration::STATUS_WAITLISTED);
});

test('promoteNextWaitlisted picks the oldest waitlisted first', function () {
    $event = Event::factory()->published()->withWaitlist()->admissionOnPayment()->create();
    $tier  = EventPriceTier::factory()->withPrice(5000)->create(['event_id' => $event->id]);

    $confirmed   = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'price_tier_id' => $tier->id]);

    $order1 = Order::factory()->create(['total_ore' => 5000, 'status' => 'pending']);
    $order2 = Order::factory()->create(['total_ore' => 5000, 'status' => 'pending']);

    $first  = EventRegistration::factory()->waitlisted()->create([
        'event_id' => $event->id, 'price_tier_id' => $tier->id,
        'order_id' => $order1->id, 'created_at' => now()->subMinutes(10),
    ]);
    $second = EventRegistration::factory()->waitlisted()->create([
        'event_id' => $event->id, 'price_tier_id' => $tier->id,
        'order_id' => $order2->id, 'created_at' => now()->subMinutes(5),
    ]);

    $promoted = $confirmed->promoteNextWaitlisted();
    expect($promoted->id)->toBe($first->id);
    expect($second->fresh()->status)->toBe(EventRegistration::STATUS_WAITLISTED);
});
