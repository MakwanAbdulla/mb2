<?php

use App\Models\Events\Event;
use App\Models\Events\EventOccurrence;
use App\Models\Events\EventPriceTier;
use App\Models\Events\EventRegistration;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function externalEvent(string $revenueType, array $extra = []): Event
{
    return Event::factory()->published()->create(array_merge([
        'external_revenue_type' => $revenueType,
        'external_organizer_name' => 'Test Arrangör',
    ], $extra));
}

// ── calculateExternalSettlement ───────────────────────────────────────────────

test('calculateExternalSettlement per_participant multiplies by confirmed count', function () {
    $event = externalEvent(Event::REVENUE_PER_PARTICIPANT, ['external_revenue_ore' => 5000]);
    $tier  = EventPriceTier::factory()->free()->create(['event_id' => $event->id]);

    EventRegistration::factory()->count(3)->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'status'        => EventRegistration::STATUS_CONFIRMED,
    ]);
    // One cancelled — should not count
    EventRegistration::factory()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'status'        => EventRegistration::STATUS_CANCELLED,
    ]);

    expect($event->calculateExternalSettlement())->toBe(15000); // 3 × 50 kr
});

test('calculateExternalSettlement per_event returns fixed amount', function () {
    $event = externalEvent(Event::REVENUE_PER_EVENT, ['external_revenue_ore' => 100000]);

    expect($event->calculateExternalSettlement())->toBe(100000);
});

test('calculateExternalSettlement per_occurrence counts non-cancelled occurrences', function () {
    $event = externalEvent(Event::REVENUE_PER_OCCURRENCE, ['external_revenue_ore' => 20000]);
    EventOccurrence::factory()->create(['event_id' => $event->id]);
    EventOccurrence::factory()->create(['event_id' => $event->id]);
    EventOccurrence::factory()->cancelled()->create(['event_id' => $event->id]);

    expect($event->calculateExternalSettlement())->toBe(40000); // 2 active × 200 kr
});

test('calculateExternalSettlement percentage of confirmed orders', function () {
    $event = externalEvent(Event::REVENUE_PERCENTAGE, ['external_revenue_pct' => 80]);
    $tier  = EventPriceTier::factory()->withPrice(10000)->create(['event_id' => $event->id]);

    $order = Order::factory()->create(['total_ore' => 10000, 'status' => 'paid']);
    EventRegistration::factory()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'order_id'      => $order->id,
        'status'        => EventRegistration::STATUS_CONFIRMED,
    ]);

    expect($event->calculateExternalSettlement())->toBe(8000); // 80% of 100 kr
});

test('calculateExternalSettlement returns 0 for REVENUE_NONE', function () {
    $event = externalEvent(Event::REVENUE_NONE);
    expect($event->calculateExternalSettlement())->toBe(0);
});

// ── settlement registration ───────────────────────────────────────────────────

test('registering settlement sets amount and timestamp', function () {
    $event = externalEvent(Event::REVENUE_PER_EVENT, ['external_revenue_ore' => 50000]);

    $event->update([
        'external_settlement_ore' => 48000,
        'external_settled_at'     => now(),
    ]);

    expect($event->fresh()->external_settlement_ore)->toBe(48000);
    expect($event->fresh()->external_settled_at)->not->toBeNull();
});

test('markCompleted sets status to completed', function () {
    $event = externalEvent(Event::REVENUE_PER_EVENT, ['external_revenue_ore' => 50000]);

    $event->update(['status' => Event::STATUS_COMPLETED]);

    expect($event->fresh()->status)->toBe(Event::STATUS_COMPLETED);
    expect($event->fresh()->isCompleted())->toBeTrue();
});
