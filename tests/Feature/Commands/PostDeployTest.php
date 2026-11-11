<?php

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function freshRole(string $name): Role
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return Role::findByName($name)->load('permissions');
}

// ── permissions:add-missing ────────────────────────────────────────────────

test('add-missing sets up every permission and role on an empty database', function () {
    $this->artisan('permissions:add-missing')->assertSuccessful();

    expect(Permission::count())->toBe(count(PermissionSeeder::PERMISSIONS))
        ->and(Role::count())->toBe(count(PermissionSeeder::ROLES))
        ->and(freshRole('admin')->permissions)->toHaveCount(count(PermissionSeeder::PERMISSIONS))
        ->and(freshRole('revisor')->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(PermissionSeeder::permissionsFor('revisor'))->sort()->values()->all());
});

test('add-missing keeps role changes made in admin', function () {
    $this->seed(PermissionSeeder::class);

    // Changes an admin could make on /admin/roller or via the API
    Role::findByName('styrelse')->revokePermissionTo('payments.view');
    Role::findByName('styrelse')->givePermissionTo('settings.manage');

    $this->artisan('permissions:add-missing')->assertSuccessful();

    $styrelse = freshRole('styrelse');
    expect($styrelse->hasPermissionTo('payments.view'))->toBeFalse()
        ->and($styrelse->hasPermissionTo('settings.manage'))->toBeTrue();
});

test('add-missing gives a new permission to the roles that list it, and only to them', function () {
    $this->seed(PermissionSeeder::class);

    // Simulate an install from before wiki.edit was added
    Permission::findByName('wiki.edit')->delete();

    $this->artisan('permissions:add-missing')
        ->expectsOutputToContain('Ny behörighet: wiki.edit')
        ->assertSuccessful();

    expect(freshRole('styrelse')->hasPermissionTo('wiki.edit'))->toBeTrue()
        ->and(freshRole('sekreterare')->hasPermissionTo('wiki.edit'))->toBeTrue()
        ->and(freshRole('admin')->hasPermissionTo('wiki.edit'))->toBeTrue()
        ->and(freshRole('treasurer')->hasPermissionTo('wiki.edit'))->toBeFalse();
});

test('add-missing creates a missing system role with its full set', function () {
    $this->seed(PermissionSeeder::class);
    Role::findByName('revisor')->delete();

    $this->artisan('permissions:add-missing')->assertSuccessful();

    expect(freshRole('revisor')->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(PermissionSeeder::permissionsFor('revisor'))->sort()->values()->all());
});

test('add-missing does nothing when everything is up to date', function () {
    $this->seed(PermissionSeeder::class);

    $this->artisan('permissions:add-missing')
        ->expectsOutput('Behörigheterna är redan aktuella.')
        ->assertSuccessful();
});

// ── app:post-deploy ────────────────────────────────────────────────────────

function configureDiscord(): void
{
    config([
        'services.discord.client_id' => '111',
        'services.discord.bot_token' => 'bot-token',
        'services.discord.guild_id' => '222',
        'services.discord.public_key' => str_repeat('a', 64),
    ]);
}

test('post-deploy skips Discord when it is not configured', function () {
    config(['services.discord.client_id' => null, 'services.discord.bot_token' => null, 'services.discord.public_key' => null]);
    Http::preventStrayRequests();
    Http::fake(['api.github.com/*' => Http::response([], 500)]);

    $this->artisan('app:post-deploy')
        ->expectsOutputToContain('Hoppar över: DISCORD_CLIENT_ID, DISCORD_BOT_TOKEN eller guild-id saknas')
        ->expectsOutputToContain('Hoppar över: DISCORD_CLIENT_ID eller DISCORD_BOT_TOKEN saknas');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'discord.com'));
    expect(Permission::count())->toBe(count(PermissionSeeder::PERMISSIONS));
});

test('post-deploy skips slash commands without a public key but still registers metadata', function () {
    configureDiscord();
    config(['services.discord.public_key' => null]);
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/*' => Http::response([], 500),
        'discord.com/*' => Http::response([]),
    ]);

    $this->artisan('app:post-deploy')->expectsOutputToContain('Hoppar över: DISCORD_PUBLIC_KEY saknas');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/commands'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/role-connections/metadata'));
});

test('post-deploy runs every step even when one fails, and then fails', function () {
    configureDiscord();
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/*' => Http::response([], 500),
        'discord.com/*' => Http::response([]),
    ]);

    $this->artisan('app:post-deploy')
        ->expectsOutputToContain('Misslyckades: Bootstrap Icons')
        ->assertFailed();

    // The steps after the failing one still ran
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/applications/111/guilds/')
        && str_ends_with($request->url(), '/commands'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/role-connections/metadata'));
    expect(Permission::count())->toBe(count(PermissionSeeder::PERMISSIONS));
});

test('post-deploy reports a failing Discord registration', function () {
    configureDiscord();
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/*' => Http::response([], 500),
        'discord.com/*' => Http::response(['message' => 'nope'], 403),
    ]);

    $this->artisan('app:post-deploy')
        ->expectsOutputToContain('Misslyckades: Bootstrap Icons, Discord: slash-kommandon, Discord: Linked Roles-metadata')
        ->assertFailed();
});
