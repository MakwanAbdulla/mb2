<?php

use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\Booking\RecurringBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function grResource(): BookingResource
{
    return BookingResource::create([
        'name'        => 'Testlokalen',
        'slug'        => 'testlokalen',
        'description' => null,
        'is_active'   => true,
        'color'       => '#000000',
    ]);
}

function grRecurring(BookingResource $resource, array $overrides = []): RecurringBooking
{
    $user = User::factory()->create();
    return RecurringBooking::create(array_merge([
        'resource_id'    => $resource->id,
        'user_id'        => $user->id,
        'title'          => 'Testveckobokn',
        'activity'       => 'Rollspel',
        'booking_type'   => 'group',
        'start_time'     => '18:00',
        'end_time'       => '21:00',
        'frequency_type' => 'weekly',
        'weekday'        => 1, // Monday
        'week_interval'  => 1,
        'ends_on'        => null,
    ], $overrides));
}

// ── Command ───────────────────────────────────────────────────────────────────

test('generates bookings for a weekly series up to one year ahead', function () {
    $resource  = grResource();
    $recurring = grRecurring($resource);

    Artisan::call('bookings:generate-recurring');

    $bookings = Booking::where('source_type', RecurringBooking::class)->where('source_id', $recurring->id)->get();
    expect($bookings)->not->toBeEmpty();

    // All should be within the next year
    $maxDate = now()->addYear();
    foreach ($bookings as $b) {
        expect($b->starts_at->lte($maxDate))->toBeTrue();
    }
});

test('does not generate bookings past ends_on', function () {
    $resource  = grResource();
    $endsOn    = now()->addWeeks(2)->toDateString();
    $recurring = grRecurring($resource, ['ends_on' => $endsOn]);

    Artisan::call('bookings:generate-recurring');

    Booking::where('source_type', RecurringBooking::class)->where('source_id', $recurring->id)->each(function ($b) use ($endsOn) {
        expect($b->starts_at->toDateString() <= $endsOn)->toBeTrue();
    });
});

test('does not generate duplicate bookings on re-run', function () {
    $resource  = grResource();
    $recurring = grRecurring($resource);

    Artisan::call('bookings:generate-recurring');
    $count1 = Booking::where('source_type', RecurringBooking::class)->where('source_id', $recurring->id)->count();

    Artisan::call('bookings:generate-recurring');
    $count2 = Booking::where('source_type', RecurringBooking::class)->where('source_id', $recurring->id)->count();

    expect($count2)->toBe($count1);
});

test('skips series that has already ended', function () {
    $resource  = grResource();
    $recurring = grRecurring($resource, ['ends_on' => now()->subDay()->toDateString()]);

    Artisan::call('bookings:generate-recurring');

    expect(Booking::where('source_type', RecurringBooking::class)->where('source_id', $recurring->id)->count())->toBe(0);
});

test('command returns SUCCESS exit code', function () {
    grResource(); // ensure no crash on empty series list
    $result = Artisan::call('bookings:generate-recurring');
    expect($result)->toBe(0);
});
