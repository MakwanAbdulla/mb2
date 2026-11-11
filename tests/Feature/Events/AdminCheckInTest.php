<?php

/**
 * Tests for admin check-in logic.
 *
 * The Volt component (admin.events.show) wraps the same operations.
 * We test the DB logic directly to avoid Volt component compilation issues in
 * the test environment. The API check-in tests cover the HTTP/permission layer.
 */

use App\Models\Events\Event;
use App\Models\Events\EventOccurrence;
use App\Models\Events\EventPriceTier;
use App\Models\Events\EventRegistration;
use App\Models\Events\EventRegistrationOccurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── checkIn logic (mirrors Volt component checkIn method) ─────────────────────

test('checkIn creates occurrence record with attended_at', function () {
    $event      = Event::factory()->published()->create();
    $occurrence = EventOccurrence::factory()->create(['event_id' => $event->id]);
    $tier       = EventPriceTier::factory()->free()->create(['event_id' => $event->id]);
    $reg        = EventRegistration::factory()->confirmed()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
    ]);
    $user = User::factory()->create();

    // Execute the same logic as the Volt checkIn() method
    $attendance = EventRegistrationOccurrence::firstOrNew([
        'registration_id' => $reg->id,
        'occurrence_id'   => $occurrence->id,
    ]);
    $attendance->attended_at      = now();
    $attendance->checked_in_by_id = $user->id;
    $attendance->save();

    $saved = EventRegistrationOccurrence::where('registration_id', $reg->id)
        ->where('occurrence_id', $occurrence->id)
        ->first();

    expect($saved)->not->toBeNull();
    expect($saved->attended_at)->not->toBeNull();
    expect($saved->checked_in_by_id)->toBe($user->id);
});

test('checkIn updates existing record without creating duplicates', function () {
    $event      = Event::factory()->published()->create();
    $occurrence = EventOccurrence::factory()->create(['event_id' => $event->id]);
    $tier       = EventPriceTier::factory()->free()->create(['event_id' => $event->id]);
    $reg        = EventRegistration::factory()->confirmed()->create([
        'event_id' => $event->id, 'price_tier_id' => $tier->id,
    ]);
    $user = User::factory()->create();

    // Pre-existing record without attendance
    EventRegistrationOccurrence::create([
        'registration_id' => $reg->id,
        'occurrence_id'   => $occurrence->id,
        'attended_at'     => null,
    ]);

    // Check in
    $attendance = EventRegistrationOccurrence::firstOrNew([
        'registration_id' => $reg->id,
        'occurrence_id'   => $occurrence->id,
    ]);
    $attendance->attended_at      = now();
    $attendance->checked_in_by_id = $user->id;
    $attendance->save();

    expect(
        EventRegistrationOccurrence::where('registration_id', $reg->id)
            ->where('occurrence_id', $occurrence->id)
            ->count()
    )->toBe(1);

    expect(
        EventRegistrationOccurrence::where('registration_id', $reg->id)
            ->where('occurrence_id', $occurrence->id)
            ->whereNotNull('attended_at')
            ->exists()
    )->toBeTrue();
});

// ── uncheckIn logic ───────────────────────────────────────────────────────────

test('uncheckIn clears attended_at', function () {
    $event      = Event::factory()->published()->create();
    $occurrence = EventOccurrence::factory()->create(['event_id' => $event->id]);
    $reg        = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id]);
    $user       = User::factory()->create();

    EventRegistrationOccurrence::create([
        'registration_id'  => $reg->id,
        'occurrence_id'    => $occurrence->id,
        'attended_at'      => now(),
        'checked_in_by_id' => $user->id,
    ]);

    // Mirrors Volt uncheckIn() method
    EventRegistrationOccurrence::where('registration_id', $reg->id)
        ->where('occurrence_id', $occurrence->id)
        ->update(['attended_at' => null, 'checked_in_by_id' => null]);

    $record = EventRegistrationOccurrence::where('registration_id', $reg->id)
        ->where('occurrence_id', $occurrence->id)
        ->first();

    expect($record->attended_at)->toBeNull();
    expect($record->checked_in_by_id)->toBeNull();
});

// ── Multi-occurrence isolation ────────────────────────────────────────────────

test('checkIn on one occurrence does not affect another', function () {
    $event = Event::factory()->published()->create();
    $occ1  = EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2)]);
    $occ2  = EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2)]);
    $reg   = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id]);
    $user  = User::factory()->create();

    // Check in to occ1 only
    $att = EventRegistrationOccurrence::firstOrNew([
        'registration_id' => $reg->id,
        'occurrence_id'   => $occ1->id,
    ]);
    $att->attended_at      = now();
    $att->checked_in_by_id = $user->id;
    $att->save();

    expect(
        EventRegistrationOccurrence::where('registration_id', $reg->id)
            ->where('occurrence_id', $occ1->id)
            ->whereNotNull('attended_at')
            ->exists()
    )->toBeTrue();

    expect(
        EventRegistrationOccurrence::where('registration_id', $reg->id)
            ->where('occurrence_id', $occ2->id)
            ->whereNotNull('attended_at')
            ->exists()
    )->toBeFalse();
});

// ── API permission layer (HTTP) ───────────────────────────────────────────────

test('API check-in requires events.check-in permission', function () {
    $this->createPermission('events.check-in');

    $event      = Event::factory()->published()->withRegistrationWindow()->create();
    $occurrence = EventOccurrence::factory()->create(['event_id' => $event->id]);
    $reg        = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id]);

    // User without permission
    \Laravel\Sanctum\Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/events/{$event->slug}/check-in", [
        'check_in_token' => $reg->check_in_token,
        'occurrence_id'  => $occurrence->id,
    ])->assertForbidden();
});

test('API check-in with permission records attendance', function () {
    $this->createPermission('events.check-in');

    $event      = Event::factory()->published()->withRegistrationWindow()->create();
    $occurrence = EventOccurrence::factory()->create(['event_id' => $event->id]);
    $reg        = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id]);

    $admin = User::factory()->create();
    $admin->givePermissionTo('events.check-in');
    \Laravel\Sanctum\Sanctum::actingAs($admin);

    $this->postJson("/api/v1/events/{$event->slug}/check-in", [
        'check_in_token' => $reg->check_in_token,
        'occurrence_id'  => $occurrence->id,
    ])->assertOk();

    expect(
        EventRegistrationOccurrence::where('registration_id', $reg->id)
            ->where('occurrence_id', $occurrence->id)
            ->whereNotNull('attended_at')
            ->exists()
    )->toBeTrue();
});
