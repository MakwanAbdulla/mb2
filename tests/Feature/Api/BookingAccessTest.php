<?php

use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('bookings.view');
    $this->createPermission('bookings.manage');
});

function baaMember(): User
{
    $user = User::factory()->create();
    Member::factory()->active()->create(['user_id' => $user->id]);

    return $user;
}

function baaResource(): BookingResource
{
    return BookingResource::firstOrCreate(['slug' => 'stora-salen'], [
        'name' => 'Stora salen',
        'capacity' => 20,
        'requires_approval' => false,
        'active' => true,
    ]);
}

function baaBooking(int $userId): Booking
{
    return Booking::create([
        'resource_id' => baaResource()->id,
        'user_id' => $userId,
        'title' => 'Testbokning',
        'activity' => 'Spelkväll',
        'booking_type' => 'group',
        'starts_at' => now()->addDay()->setTime(18, 0),
        'ends_at' => now()->addDay()->setTime(20, 0),
        'status' => 'approved',
    ]);
}

function baaPayload(): array
{
    return [
        'title' => 'Spelkväll',
        'activity' => 'Spelkväll',
        'booking_type' => 'group',
        'resource_id' => baaResource()->id,
        'starts_at' => now()->addDays(2)->setTime(18, 0)->toIso8601String(),
        'ends_at' => now()->addDays(2)->setTime(20, 0)->toIso8601String(),
    ];
}

// ── GET /api/v1/bookings ──────────────────────────────────────────────────────

test('non-member gets 403 on booking index', function () {
    $user = User::factory()->create();
    baaBooking($user->id);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookings')->assertForbidden();
});

test('member sees own bookings in index', function () {
    $user = baaMember();
    baaBooking($user->id);
    baaBooking(User::factory()->create()->id);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bookings')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('staff with bookings.view sees all bookings without membership', function () {
    $staff = User::factory()->create();
    $staff->givePermissionTo('bookings.view');
    baaBooking(User::factory()->create()->id);
    baaBooking(User::factory()->create()->id);
    Sanctum::actingAs($staff);

    $this->getJson('/api/v1/bookings')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── GET /api/v1/bookings/{booking} ────────────────────────────────────────────

test('non-member gets 403 on own booking', function () {
    $user = User::factory()->create();
    $booking = baaBooking($user->id);
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/bookings/{$booking->id}")->assertForbidden();
});

test('member can view own booking', function () {
    $user = baaMember();
    $booking = baaBooking($user->id);
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/bookings/{$booking->id}")->assertOk();
});

// ── POST /api/v1/bookings ─────────────────────────────────────────────────────

test('non-member cannot create a booking', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/bookings', baaPayload())->assertForbidden();

    expect(Booking::count())->toBe(0);
});

test('member can create a booking', function () {
    Sanctum::actingAs(baaMember());

    $this->postJson('/api/v1/bookings', baaPayload())->assertCreated();

    expect(Booking::count())->toBe(1);
});

// ── DELETE /api/v1/bookings/{booking} ─────────────────────────────────────────

test('non-member cannot cancel own booking', function () {
    $user = User::factory()->create();
    $booking = baaBooking($user->id);
    Sanctum::actingAs($user);

    $this->deleteJson("/api/v1/bookings/{$booking->id}")->assertForbidden();

    expect($booking->fresh()->status)->toBe('approved');
});

test('staff with bookings.manage can cancel without membership', function () {
    $staff = User::factory()->create();
    $staff->givePermissionTo('bookings.manage');
    $booking = baaBooking(User::factory()->create()->id);
    Sanctum::actingAs($staff);

    $this->deleteJson("/api/v1/bookings/{$booking->id}")->assertOk();
});
