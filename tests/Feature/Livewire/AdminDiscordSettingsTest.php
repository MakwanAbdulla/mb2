<?php

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('settings.manage');
    Http::fake();
});

function discordSettingsAdmin(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('settings.manage');

    return $user;
}

test('booking webhook url is loaded from settings', function () {
    SystemSetting::set('discord.webhook.bookings', 'https://discord.test/bookings');

    Volt::actingAs(discordSettingsAdmin())->test('admin.settings.discord')
        ->assertSet('webhookBookings', 'https://discord.test/bookings');
});

test('booking webhook url is saved', function () {
    Volt::actingAs(discordSettingsAdmin())->test('admin.settings.discord')
        ->set('webhookBookings', 'https://discord.test/bookings')
        ->call('save')
        ->assertHasNoErrors();

    expect(SystemSetting::get('discord.webhook.bookings'))->toBe('https://discord.test/bookings');
});

test('booking webhook url must be a valid url', function () {
    Volt::actingAs(discordSettingsAdmin())->test('admin.settings.discord')
        ->set('webhookBookings', 'inte-en-url')
        ->call('save')
        ->assertHasErrors(['webhookBookings' => 'url']);
});

test('test button sends a message to the booking webhook', function () {
    Volt::actingAs(discordSettingsAdmin())->test('admin.settings.discord')
        ->set('webhookBookings', 'https://discord.test/bookings')
        ->call('testBookingWebhook');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://discord.test/bookings');
});

test('test button does nothing when booking webhook is empty', function () {
    Volt::actingAs(discordSettingsAdmin())->test('admin.settings.discord')
        ->set('webhookBookings', '')
        ->call('testBookingWebhook');

    Http::assertNothingSent();
});
