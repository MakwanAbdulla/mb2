<?php

use App\Mail\BookingReminder;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function brResource(): BookingResource
{
    return BookingResource::create([
        'name'      => 'Rummet',
        'slug'      => 'rummet',
        'is_active' => true,
        'color'     => '#000000',
    ]);
}

function brBooking(BookingResource $resource, \Carbon\CarbonInterface $start, array $userAttrs = []): Booking
{
    $user = User::factory()->create(array_merge([
        'reminder_emails' => true,
    ], $userAttrs));

    return Booking::create([
        'resource_id' => $resource->id,
        'user_id'     => $user->id,
        'title'       => 'Test bokning',
        'activity'    => 'Spel',
        'starts_at'   => $start,
        'ends_at'     => $start->copy()->addHours(2),
        'status'      => 'confirmed',
    ]);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test('sends reminder for confirmed booking starting tomorrow', function () {
    Mail::fake();

    $resource = brResource();
    $start    = now()->addDay()->setTime(18, 0);
    brBooking($resource, $start);

    Artisan::call('bookings:send-reminders');

    Mail::assertQueued(BookingReminder::class, 1);
});

test('does not send reminder for booking starting today', function () {
    Mail::fake();

    $resource = brResource();
    brBooking($resource, now()->setTime(18, 0));

    Artisan::call('bookings:send-reminders');

    Mail::assertNothingQueued();
});

test('does not send reminder for booking starting in two days', function () {
    Mail::fake();

    $resource = brResource();
    brBooking($resource, now()->addDays(2)->setTime(18, 0));

    Artisan::call('bookings:send-reminders');

    Mail::assertNothingQueued();
});

test('does not send reminder when user has reminder_emails = false', function () {
    Mail::fake();

    $resource = brResource();
    brBooking($resource, now()->addDay()->setTime(18, 0), ['reminder_emails' => false]);

    Artisan::call('bookings:send-reminders');

    Mail::assertNothingQueued();
});

test('does not send reminder for cancelled booking', function () {
    Mail::fake();

    $resource = brResource();
    $start    = now()->addDay()->setTime(18, 0);
    $booking  = brBooking($resource, $start);
    $booking->update(['status' => 'cancelled']);

    Artisan::call('bookings:send-reminders');

    Mail::assertNothingQueued();
});

test('sends reminders to multiple users with bookings tomorrow', function () {
    Mail::fake();

    $resource = brResource();
    $start    = now()->addDay()->setTime(19, 0);
    brBooking($resource, $start);
    brBooking($resource, $start);

    Artisan::call('bookings:send-reminders');

    Mail::assertQueued(BookingReminder::class, 2);
});
