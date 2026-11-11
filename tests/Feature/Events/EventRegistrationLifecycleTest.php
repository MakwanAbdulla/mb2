<?php

use App\Models\Events\Event;
use App\Models\Events\EventPriceTier;
use App\Models\Events\EventRegistration;
use App\Models\Events\EventRegistrationOccurrence;
use App\Models\Shop\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── cancel() ──────────────────────────────────────────────────────────────────

test('cancel() sets status to cancelled and records cancelled_at', function () {
    $reg = EventRegistration::factory()->confirmed()->create();

    $reg->cancel();

    $fresh = $reg->fresh();
    expect($fresh->status)->toBe(EventRegistration::STATUS_CANCELLED);
    expect($fresh->cancelled_at)->not->toBeNull();
});

test('cancel() on pending_payment registration cancels it', function () {
    $reg = EventRegistration::factory()->pendingPayment()->create();

    $reg->cancel();

    expect($reg->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);
});

test('cancel() + promoteNextWaitlisted — cancelled confirmed spot promotes next in queue', function () {
    $event = Event::factory()->published()->withWaitlist()->admissionOnPayment()->create();
    $tier  = EventPriceTier::factory()->withPrice(5000)->create(['event_id' => $event->id]);
    $order = Order::factory()->create(['total_ore' => 5000, 'status' => 'pending']);

    $confirmed  = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'price_tier_id' => $tier->id]);
    $waitlisted = EventRegistration::factory()->waitlisted()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'order_id'      => $order->id,
    ]);

    $confirmed->cancel();
    $confirmed->promoteNextWaitlisted();

    expect($waitlisted->fresh()->status)->toBe(EventRegistration::STATUS_PENDING_PAYMENT);
});

test('cancel() + promoteNextWaitlisted — automatic admission confirms waitlisted directly', function () {
    $event = Event::factory()->published()->withWaitlist()->admissionAutomatic()->create();
    $tier  = EventPriceTier::factory()->create(['event_id' => $event->id]);

    $confirmed  = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'price_tier_id' => $tier->id]);
    $waitlisted = EventRegistration::factory()->waitlisted()->create(['event_id' => $event->id, 'price_tier_id' => $tier->id]);

    $confirmed->cancel();
    $confirmed->promoteNextWaitlisted();

    expect($waitlisted->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test('cancel() when no waitlist enabled does not change other registrations', function () {
    $event = Event::factory()->published()->create(['waitlist_mode' => Event::WAITLIST_NONE]);
    $tier  = EventPriceTier::factory()->create(['event_id' => $event->id]);

    $confirmed  = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'price_tier_id' => $tier->id]);
    $waitlisted = EventRegistration::factory()->waitlisted()->create(['event_id' => $event->id, 'price_tier_id' => $tier->id]);

    $confirmed->cancel();
    $result = $confirmed->promoteNextWaitlisted();

    expect($result)->toBeNull();
    expect($waitlisted->fresh()->status)->toBe(EventRegistration::STATUS_WAITLISTED);
});

// ── transferTo() ──────────────────────────────────────────────────────────────

test('transferTo() creates confirmed registration for recipient', function () {
    $sender    = User::factory()->create();
    $recipient = User::factory()->create();
    $approver  = User::factory()->create();

    $event = Event::factory()->published()->create(['allow_ticket_transfer' => true]);
    $tier  = EventPriceTier::factory()->create(['event_id' => $event->id]);
    $order = Order::factory()->create(['status' => 'paid', 'user_id' => $sender->id]);

    $original = EventRegistration::factory()->confirmed()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'order_id'      => $order->id,
        'user_id'       => $sender->id,
    ]);

    $newReg = $original->transferTo($recipient, $approver);

    expect($newReg->status)->toBe(EventRegistration::STATUS_CONFIRMED);
    expect($newReg->user_id)->toBe($recipient->id);
    expect($newReg->transferred_from_id)->toBe($original->id);
    expect($newReg->transfer_approved_by_id)->toBe($approver->id);
    expect($original->fresh()->status)->toBe(EventRegistration::STATUS_TRANSFERRED);
});

test('transferTo() copies occurrence attendance placeholders to new registration', function () {
    $sender    = User::factory()->create();
    $recipient = User::factory()->create();
    $approver  = User::factory()->create();

    $event      = Event::factory()->published()->create();
    $tier       = EventPriceTier::factory()->create(['event_id' => $event->id]);
    $occurrence = \App\Models\Events\EventOccurrence::factory()->create(['event_id' => $event->id]);
    $order      = Order::factory()->create(['status' => 'paid', 'user_id' => $sender->id]);

    $original = EventRegistration::factory()->confirmed()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'order_id'      => $order->id,
        'user_id'       => $sender->id,
    ]);

    // Simulate existing attendance record for the original registration
    EventRegistrationOccurrence::create([
        'registration_id' => $original->id,
        'occurrence_id'   => $occurrence->id,
        'attended_at'     => null,
    ]);

    $newReg = $original->transferTo($recipient, $approver);

    $copied = EventRegistrationOccurrence::where('registration_id', $newReg->id)
        ->where('occurrence_id', $occurrence->id)
        ->first();

    expect($copied)->not->toBeNull();
    expect($copied->attended_at)->toBeNull(); // attendance not copied, only the placeholder
});

test('transferTo() does not copy attended_at from original', function () {
    $sender    = User::factory()->create();
    $recipient = User::factory()->create();
    $approver  = User::factory()->create();

    $event      = Event::factory()->published()->create();
    $tier       = EventPriceTier::factory()->create(['event_id' => $event->id]);
    $occurrence = \App\Models\Events\EventOccurrence::factory()->create(['event_id' => $event->id]);
    $order      = Order::factory()->create(['status' => 'paid', 'user_id' => $sender->id]);

    $original = EventRegistration::factory()->confirmed()->create([
        'event_id' => $event->id, 'price_tier_id' => $tier->id,
        'order_id' => $order->id, 'user_id' => $sender->id,
    ]);

    // Original was checked in
    EventRegistrationOccurrence::create([
        'registration_id' => $original->id,
        'occurrence_id'   => $occurrence->id,
        'attended_at'     => now(),
        'checked_in_by_id' => $approver->id,
    ]);

    $newReg = $original->transferTo($recipient, $approver);

    $copied = EventRegistrationOccurrence::where('registration_id', $newReg->id)->first();
    expect($copied->attended_at)->toBeNull();
    expect($copied->checked_in_by_id)->toBeNull();
});
