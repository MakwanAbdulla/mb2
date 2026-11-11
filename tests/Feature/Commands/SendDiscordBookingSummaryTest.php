<?php

use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\Events\Event;
use App\Models\Events\EventOccurrence;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function dbsResource(string $name): BookingResource
{
    return BookingResource::create([
        'name' => $name,
        'slug' => str($name)->slug(),
        'active' => true,
        'color' => '#000000',
    ]);
}

function dbsBooking(BookingResource $resource, Carbon $start, string $status = 'confirmed', int $hours = 3, string $title = 'Spelkväll'): Booking
{
    return Booking::create([
        'resource_id' => $resource->id,
        'user_id' => User::factory()->create()->id,
        'title' => $title,
        'activity' => 'Brädspel',
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHours($hours),
        'status' => $status,
    ]);
}

function dbsDescription(Request $request): string
{
    return $request['embeds'][0]['description'];
}

function dbsEvent(array $attributes = []): Event
{
    return Event::factory()->create(array_merge([
        'title' => 'Turnering',
        'status' => Event::STATUS_PUBLISHED,
        'visibility' => Event::VISIBILITY_PUBLIC,
        'location_name' => 'Stora salen',
    ], $attributes));
}

function dbsOccurrence(Event $event, Carbon $start, array $attributes = []): EventOccurrence
{
    return EventOccurrence::factory()->create(array_merge([
        'event_id' => $event->id,
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHours(4),
        'cancelled_at' => null,
        'location_override' => null,
    ], $attributes));
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->withoutDefer();
    Http::fake([
        'https://discord.test/fail' => Http::response([], 404),
        '*' => Http::response(),
    ]);
    SystemSetting::set('discord.webhook.bookings', 'https://discord.test/webhook');
});

test('sends booked and fully free rooms for the given date', function () {
    $booked = dbsResource('Vita rummet');
    dbsResource('Röda rummet');
    dbsBooking($booked, Carbon::parse('2030-05-10 18:00'));

    Artisan::call('discord:booking-summary', ['--date' => '2030-05-10']);

    Http::assertSent(function (Request $request) {
        $description = dbsDescription($request);

        return $request->url() === 'https://discord.test/webhook'
            && str_contains($description, '18:00–21:00 Vita rummet: Spelkväll')
            && str_contains($description, 'Helt lediga: Röda rummet');
    });
});

test('title contains the date as d/m Y', function () {
    Artisan::call('discord:booking-summary', ['--date' => '2030-05-10']);

    Http::assertSent(fn (Request $request) => str_contains($request['embeds'][0]['title'], '10/05 2030'));
});

test('ignores bookings on other dates', function () {
    dbsBooking(dbsResource('Vita rummet'), Carbon::parse('2030-05-11 18:00'));

    Artisan::call('discord:booking-summary', ['--date' => '2030-05-10']);

    Http::assertSent(fn (Request $request) => str_contains(dbsDescription($request), 'Inga bokningar'));
});

test('ignores cancelled bookings', function () {
    dbsBooking(dbsResource('Vita rummet'), Carbon::parse('2030-05-10 18:00'), 'cancelled');

    Artisan::call('discord:booking-summary', ['--date' => '2030-05-10']);

    Http::assertSent(fn (Request $request) => str_contains(dbsDescription($request), 'Inga bokningar')
        && str_contains(dbsDescription($request), 'Helt lediga: Vita rummet'));
});

test('a booking spanning several days is shown on every day it covers', function () {
    dbsBooking(dbsResource('Vita rummet'), Carbon::parse('2030-05-09 18:00'), hours: 48, title: 'LAN');

    Artisan::call('discord:booking-summary', ['--date' => '2030-05-10']);

    Http::assertSent(fn (Request $request) => str_contains(dbsDescription($request), '00:00–24:00 Vita rummet: LAN')
        && str_contains(dbsDescription($request), 'Helt lediga: inga'));
});

test('description stays within the Discord limit on a busy day', function () {
    $resource = dbsResource('Vita rummet');

    for ($i = 0; $i < 60; $i++) {
        dbsBooking($resource, Carbon::parse('2030-05-10 08:00'), title: str_repeat('Lång titel ', 10));
    }

    Artisan::call('discord:booking-summary', ['--date' => '2030-05-10']);

    Http::assertSent(fn (Request $request) => mb_strlen(dbsDescription($request)) <= 4096
        && str_contains(dbsDescription($request), 'bokningar till'));
});

test('includes published events for the given date', function () {
    $event = dbsEvent();
    dbsOccurrence($event, Carbon::parse('2030-05-10 12:00'));

    Artisan::call('discord:booking-summary', ['--date' => '2030-05-10']);

    Http::assertSent(function (Request $request) use ($event) {
        $description = dbsDescription($request);

        return str_contains($description, "**Evenemang**\n12:00–16:00 [Turnering](")
            && str_contains($description, '/evenemang/'.$event->slug.') (Stora salen)');
    });
});

test('excludes draft, invite-only and cancelled events', function () {
    $start = Carbon::parse('2030-05-10 12:00');

    dbsOccurrence(dbsEvent(['status' => Event::STATUS_DRAFT]), $start);
    dbsOccurrence(dbsEvent(['visibility' => Event::VISIBILITY_INVITE_ONLY]), $start);
    dbsOccurrence(dbsEvent(), $start, ['cancelled_at' => now()]);

    Artisan::call('discord:booking-summary', ['--date' => '2030-05-10']);

    Http::assertSent(fn (Request $request) => ! str_contains(dbsDescription($request), '**Evenemang**'));
});

test('does not send anything when webhook is not configured', function () {
    SystemSetting::set('discord.webhook.bookings', '');

    $exitCode = Artisan::call('discord:booking-summary');

    expect($exitCode)->toBe(0);
    Http::assertNothingSent();
});

test('fails when discord rejects the message', function () {
    SystemSetting::set('discord.webhook.bookings', 'https://discord.test/fail');

    $exitCode = Artisan::call('discord:booking-summary', ['--date' => '2030-05-10']);

    expect($exitCode)->toBe(1);
});

test('is scheduled daily at 07:00', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toMatch('/0\s+7\s+\*\s+\*\s+\*\s+php artisan discord:booking-summary/');
});
