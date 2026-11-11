<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

test('server info page requires settings.manage permission', function () {
    $this->createPermission('settings.manage');
    $user = User::factory()->create();

    Volt::actingAs($user)->test('admin.settings.server-info')
        ->assertForbidden();
});

test('server info page shows PHP version and extensions but never env secrets', function () {
    $this->createPermission('settings.manage');
    $user = User::factory()->create();
    $user->givePermissionTo('settings.manage');

    $component = Volt::actingAs($user)->test('admin.settings.server-info');

    $component->assertSee(PHP_VERSION)
        ->assertSee('memory_limit');

    $html = $component->html();

    expect($html)->not->toContain(config('database.connections.mysql.password') ?: 'unused-placeholder-never-matches')
        ->and($html)->not->toContain('DB_PASSWORD')
        ->and($html)->not->toContain('APP_KEY')
        ->and($html)->not->toContain(config('app.key'));
});
