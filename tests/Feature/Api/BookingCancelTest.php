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

function makeBookingResource(): BookingResource
{
    return BookingResource::create([
        'name'              => 'Stora salen',
        'slug'              => 'stora-salen',
        'capacity'          => 20,
        'requires_approval' => false,
        'active'            => true,
    ]);
}

function makeBooking(int $userId, string $status = 'approved'): Booking
{
    $resource = makeBookingResource();
    return Booking::create([
        'resource_id'    => $resource->id,
        'user_id'        => $userId,
        'title'          => 'Testbokning',
        'activity'       => 'Spelkväll',
        'booking_type'   => 'event',
        'starts_at'      => now()->addDay(),
        'ends_at'        => now()->addDay()->addHours(2),
        'status'         => $status,
    ]);
}

// ── DELETE /api/v1/bookings/{booking} ─────────────────────────────────────────

test('booking cancel requires auth', function () {
    $user    = User::factory()->create();
    $booking = makeBooking($user->id);
    $this->deleteJson("/api/v1/bookings/{$booking->id}")->assertUnauthorized();
});

test('user can cancel their own booking', function () {
    $user    = User::factory()->create();
    Member::factory()->active()->create(['user_id' => $user->id]);
    $booking = makeBooking($user->id);
    Sanctum::actingAs($user);

    $this->deleteJson("/api/v1/bookings/{$booking->id}")->assertOk();

    expect(Booking::find($booking->id)->status)->toBe('cancelled');
});

test('user cannot cancel another user\'s booking', function () {
    $owner   = User::factory()->create();
    $other   = User::factory()->create();
    $booking = makeBooking($owner->id);
    Sanctum::actingAs($other);

    $this->deleteJson("/api/v1/bookings/{$booking->id}")->assertForbidden();
});

test('admin with bookings.manage can cancel any booking', function () {
    $owner   = User::factory()->create();
    $admin   = User::factory()->create();
    $booking = makeBooking($owner->id);

    $admin->givePermissionTo('bookings.manage');
    Sanctum::actingAs($admin);

    $this->deleteJson("/api/v1/bookings/{$booking->id}")->assertOk();
});

test('cancelling non-existent booking returns 404', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->deleteJson('/api/v1/bookings/99999')->assertNotFound();
});
