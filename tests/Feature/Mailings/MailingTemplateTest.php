<?php

use App\Models\Content\MailingTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'mailings.manage', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'events.manage-all', 'guard_name' => 'web']);
});

test('template with no permission is available to any mailings.manage user', function () {
    $user     = User::factory()->create();
    $user->givePermissionTo('mailings.manage');
    $template = MailingTemplate::factory()->create(['permission_required' => null]);

    expect($template->isAvailableTo($user))->toBeTrue();
});

test('template with permission_required is available only if user has that extra permission', function () {
    $userWith    = User::factory()->create();
    $userWithout = User::factory()->create();

    $userWith->givePermissionTo(['mailings.manage', 'events.manage-all']);
    $userWithout->givePermissionTo('mailings.manage');

    $template = MailingTemplate::factory()->withPermission('events.manage-all')->create();

    expect($template->isAvailableTo($userWith))->toBeTrue()
        ->and($template->isAvailableTo($userWithout))->toBeFalse();
});

test('template is not available to user without mailings.manage', function () {
    $user     = User::factory()->create();
    $template = MailingTemplate::factory()->create(['permission_required' => null]);

    expect($template->isAvailableTo($user))->toBeFalse();
});

test('template mailings relationship counts correctly', function () {
    $template = MailingTemplate::factory()->create();

    expect($template->mailings()->count())->toBe(0);
});
