<?php

use App\Livewire\Admin\Booking\BookingManager;
use App\Livewire\Admin\Booking\RecurringBookingIndex;
use App\Livewire\Booking\MyBookings;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\Booking\RecurringBooking;
use App\Models\Booking\VenueApplication;
use App\Models\Membership\Member;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\BookingOverviewService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function bovResource(string $name): BookingResource
{
    return BookingResource::create([
        'name' => $name,
        'slug' => str($name)->slug(),
        'active' => true,
        'color' => '#000000',
    ]);
}

function bovBooking(BookingResource $resource, CarbonInterface $start, int $hours = 3, string $status = 'confirmed', string $title = 'Spelkväll'): Booking
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

function bovEnableWebhook(string $url = 'https://discord.test/webhook'): void
{
    SystemSetting::set('discord.webhook.bookings', $url);
}

function bovEmbedLength(array $embed): int
{
    return mb_strlen($embed['title'])
        + mb_strlen($embed['footer']['text'])
        + collect($embed['fields'])->sum(fn (array $field) => mb_strlen($field['name']) + mb_strlen($field['value']));
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->withoutDefer();
    Http::fake([
        'discord.test/down*' => Http::failedConnection(),
        'discord.test/webhook/messages/deleted*' => Http::response([], 404),
        'discord.test/webhook/messages/limited*' => Http::response([], 429),
        'discord.test/webhook/messages/*' => Http::response(),
        'discord.test/webhook*' => Http::response(['id' => '111']),
    ]);
});

test('embed has one field per day with bookings and fully free rooms', function () {
    $booked = bovResource('Vita rummet');
    bovResource('Röda rummet');
    bovBooking($booked, now()->addDay()->setTime(18, 0));

    $embed = app(BookingOverviewService::class)->buildEmbed();

    expect($embed['fields'])->toHaveCount(7)
        ->and($embed['fields'][0]['value'])->toContain('Inga bokningar')
        ->and($embed['fields'][1]['name'])->toEndWith(now()->addDay()->format('d/m'))
        ->and($embed['fields'][1]['value'])->toContain('18:00–21:00 Vita rummet: Spelkväll')
        ->and($embed['fields'][1]['value'])->toContain('Helt lediga: Röda rummet');
});

test('a booking spanning several days is shown on every day it covers', function () {
    bovBooking(bovResource('Vita rummet'), now()->addDay()->setTime(18, 0), hours: 48, title: 'LAN');

    $fields = app(BookingOverviewService::class)->buildEmbed()['fields'];

    expect($fields[1]['value'])->toContain('18:00–24:00 Vita rummet: LAN')
        ->and($fields[2]['value'])->toContain('00:00–24:00 Vita rummet: LAN')
        ->and($fields[2]['value'])->toContain('Helt lediga: inga')
        ->and($fields[3]['value'])->toContain('00:00–18:00 Vita rummet: LAN');
});

test('embed stays below the Discord limit for a fully booked week', function () {
    $resource = bovResource('Vita rummet');

    for ($day = 0; $day < 7; $day++) {
        for ($i = 0; $i < 15; $i++) {
            bovBooking($resource, now()->addDays($day)->setTime(8, 0), title: str_repeat('Lång titel ', 10));
        }
    }

    $embed = app(BookingOverviewService::class)->buildEmbed();

    expect(bovEmbedLength($embed))->toBeLessThan(6000)
        ->and($embed['fields'][0]['value'])->toContain('bokningar till');
});

test('publish posts a new message and stores its id', function () {
    bovEnableWebhook();

    expect(app(BookingOverviewService::class)->publish())->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://discord.test/webhook?wait=true');
    expect(SystemSetting::get('discord.bookings_overview.message_id'))->toBe('111');
});

test('publish edits the stored message instead of posting a new one', function () {
    bovEnableWebhook();
    SystemSetting::set('discord.bookings_overview.message_id', '555');

    app(BookingOverviewService::class)->publish();

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->url() === 'https://discord.test/webhook/messages/555');
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

test('publish posts a new message when the stored one was deleted', function () {
    bovEnableWebhook();
    SystemSetting::set('discord.bookings_overview.message_id', 'deleted');

    app(BookingOverviewService::class)->publish();

    expect(SystemSetting::get('discord.bookings_overview.message_id'))->toBe('111');
});

test('publish does not post a duplicate when Discord is rate limiting', function () {
    bovEnableWebhook();
    SystemSetting::set('discord.bookings_overview.message_id', 'limited');

    expect(app(BookingOverviewService::class)->publish())->toBeFalse();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    expect(SystemSetting::get('discord.bookings_overview.message_id'))->toBe('limited');
});

test('a thread_id in the webhook url is kept when posting and editing', function () {
    bovEnableWebhook('https://discord.test/webhook?thread_id=99');
    $overview = app(BookingOverviewService::class);

    $overview->publish();
    $overview->publish();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://discord.test/webhook?thread_id=99&wait=true');
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->url() === 'https://discord.test/webhook/messages/111?thread_id=99');
});

test('creating a booking in the coming week refreshes the overview', function () {
    bovEnableWebhook();

    bovBooking(bovResource('Vita rummet'), now()->addDay()->setTime(18, 0));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://discord.test/webhook?wait=true');
});

test('bookings further ahead do not contact discord', function () {
    bovEnableWebhook();

    bovBooking(bovResource('Vita rummet'), now()->addDays(30)->setTime(18, 0));

    Http::assertNothingSent();
});

test('changes that do not affect the overview do not contact discord', function () {
    $booking = bovBooking(bovResource('Vita rummet'), now()->addDay()->setTime(18, 0));
    bovEnableWebhook();

    $booking->update(['notes' => 'Ta med extra stolar']);

    Http::assertNothingSent();
});

test('a discord outage does not prevent saving a booking', function () {
    bovEnableWebhook('https://discord.test/down');

    bovBooking(bovResource('Vita rummet'), now()->addDay()->setTime(18, 0));

    expect(Booking::count())->toBe(1);
});

test('bulk actions in admin refresh the overview once', function (string $action) {
    $this->createPermission('bookings.manage');
    $admin = User::factory()->create();
    $admin->givePermissionTo('bookings.manage');

    $resource = bovResource('Vita rummet');
    $first = bovBooking($resource, now()->addDay()->setTime(18, 0), status: 'pending');
    $second = bovBooking($resource, now()->addDays(2)->setTime(18, 0), status: 'pending');
    bovEnableWebhook();

    Livewire::actingAs($admin)->test(BookingManager::class)
        ->set('selected', [$first->id, $second->id])
        ->call($action);

    Http::assertSentCount(1);
})->with(['bulkApprove', 'bulkReject', 'bulkCancel']);

test('cancelling an approved venue application refreshes the overview once', function () {
    $user = User::factory()->create();
    Member::factory()->active()->create(['user_id' => $user->id]);
    $resource = bovResource('Vita rummet');
    $first = bovBooking($resource, now()->addDay()->setTime(18, 0));
    $second = bovBooking($resource, now()->addDays(2)->setTime(18, 0));

    $application = VenueApplication::create([
        'user_id' => $user->id,
        'event_name' => 'LAN',
        'responsible_name' => 'Maja Medlem',
        'responsible_email' => 'maja@example.com',
        'starts_at' => now()->addDay()->setTime(18, 0),
        'ends_at' => now()->addDays(2)->setTime(21, 0),
        'resource_ids' => [$resource->id],
        'status' => 'approved',
        'booking_ids' => [$first->id, $second->id],
    ]);
    bovEnableWebhook();

    Livewire::actingAs($user)->test(MyBookings::class)
        ->set('venueApplicationId', $application->id)
        ->call('cancelVenueApplication');

    expect($first->fresh()->status)->toBe('cancelled')
        ->and($second->fresh()->status)->toBe('cancelled');
    Http::assertSentCount(1);
});

test('deleting a recurring booking series refreshes the overview once', function () {
    $this->createPermission('bookings.manage');
    $admin = User::factory()->create();
    $admin->givePermissionTo('bookings.manage');

    $resource = bovResource('Vita rummet');
    $recurring = RecurringBooking::create([
        'resource_id' => $resource->id,
        'user_id' => $admin->id,
        'title' => 'Figurspelskväll',
        'activity' => 'Figurspel',
        'start_time' => '18:00',
        'end_time' => '22:00',
        'frequency_type' => 'weekly',
        'weekday' => now()->addDay()->dayOfWeekIso,
    ]);

    foreach ([1, 8] as $days) {
        $recurring->bookings()->create([
            'resource_id' => $resource->id,
            'user_id' => $admin->id,
            'title' => 'Figurspelskväll',
            'activity' => 'Figurspel',
            'starts_at' => now()->addDays($days)->setTime(18, 0),
            'ends_at' => now()->addDays($days)->setTime(22, 0),
            'status' => 'confirmed',
        ]);
    }
    bovEnableWebhook();

    Livewire::actingAs($admin)->test(RecurringBookingIndex::class)
        ->call('delete', $recurring->id);

    expect(Booking::count())->toBe(0);
    Http::assertSentCount(1);
});

test('command does nothing when no webhook is configured', function () {
    $exitCode = Artisan::call('discord:booking-overview');

    expect($exitCode)->toBe(0);
    Http::assertNothingSent();
});

test('command is scheduled daily at 00:05', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toMatch('/5\s+0\s+\*\s+\*\s+\*\s+php artisan discord:booking-overview/');
});
