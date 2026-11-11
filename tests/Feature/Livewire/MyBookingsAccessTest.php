<?php

use App\Livewire\Booking\BookingEditUser;
use App\Livewire\Booking\MyBookings;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function mbaMember(): User
{
    $user = User::factory()->create();
    Member::factory()->active()->create(['user_id' => $user->id]);

    return $user;
}

function mbaFutureBooking(int $userId): Booking
{
    $resource = BookingResource::create([
        'name' => 'Stora salen',
        'slug' => 'stora-salen',
        'capacity' => 20,
        'requires_approval' => false,
        'active' => true,
    ]);

    return Booking::create([
        'resource_id' => $resource->id,
        'user_id' => $userId,
        'title' => 'Testbokning',
        'activity' => 'Spelkväll',
        'booking_type' => 'group',
        'starts_at' => now()->addDay()->setTime(18, 0),
        'ends_at' => now()->addDay()->setTime(20, 0),
        'status' => 'approved',
    ]);
}

// ── /mina-bokningar ───────────────────────────────────────────────────────────

test('non-member gets 403 on my bookings', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('booking.my'))
        ->assertForbidden();
});

test('member can open my bookings', function () {
    $this->actingAs(mbaMember())
        ->get(route('booking.my'))
        ->assertOk();
});

test('member can cancel own future booking', function () {
    $user = mbaMember();
    $booking = mbaFutureBooking($user->id);

    Livewire::actingAs($user)->test(MyBookings::class)
        ->call('cancel', $booking->id);

    expect($booking->fresh()->status)->toBe('cancelled');
});

test('non-member cannot mount my bookings component', function () {
    $user = User::factory()->create();
    mbaFutureBooking($user->id);

    Livewire::actingAs($user)->test(MyBookings::class)->assertForbidden();
});

// ── /mina-bokningar/{booking}/redigera ────────────────────────────────────────

test('non-member gets 403 when editing own future booking', function () {
    $user = User::factory()->create();
    $booking = mbaFutureBooking($user->id);

    $this->actingAs($user)
        ->get(route('booking.edit', $booking))
        ->assertForbidden();
});

test('member can open edit page for own future booking', function () {
    $user = mbaMember();
    $booking = mbaFutureBooking($user->id);

    $this->actingAs($user)
        ->get(route('booking.edit', $booking))
        ->assertOk();
});

test('non-member cannot mount booking edit component', function () {
    $user = User::factory()->create();
    $booking = mbaFutureBooking($user->id);

    Livewire::actingAs($user)
        ->test(BookingEditUser::class, ['booking' => $booking])
        ->assertForbidden();
});

// ── Dashboard ─────────────────────────────────────────────────────────────────

test('dashboard still opens for non-member with old bookings', function () {
    $user = User::factory()->create();
    mbaFutureBooking($user->id);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

// ── /boka ─────────────────────────────────────────────────────────────────────

test('non-member sees the booking form with the non-member notice', function () {
    mbaFutureBooking(User::factory()->create()->id);

    $this->actingAs(User::factory()->create())
        ->get(route('booking.create'))
        ->assertOk()
        ->assertSee('Ditt konto är inaktiverat för bokningar');
});

test('member does not see the non-member notice', function () {
    mbaFutureBooking(User::factory()->create()->id);

    $this->actingAs(mbaMember())
        ->get(route('booking.create'))
        ->assertOk()
        ->assertDontSee('Ditt konto är inaktiverat för bokningar');
});

test('admin without membership does not see the non-member notice', function () {
    mbaFutureBooking(User::factory()->create()->id);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('booking.create'))
        ->assertOk()
        ->assertDontSee('Ditt konto är inaktiverat för bokningar');
});
