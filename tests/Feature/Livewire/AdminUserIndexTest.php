<?php

use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('users.manage-roles');
    Role::firstOrCreate(['name' => 'website-editor', 'guard_name' => 'web']);
});

function adminWithRolesPermission(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('users.manage-roles');

    return $user;
}

test('mount is forbidden without users.manage-roles', function () {
    $user = User::factory()->create();

    Volt::actingAs($user)->test('admin.users.index')->assertForbidden();
});

test('only lists users without a member record', function () {
    $withMember = User::factory()->create();
    Member::factory()->create(['user_id' => $withMember->id, 'email' => $withMember->email]);

    $withoutMember = User::factory()->create();

    $component = Volt::actingAs(adminWithRolesPermission())->test('admin.users.index');

    $ids = $component->get('users')->pluck('id');

    expect($ids)->toContain($withoutMember->id)
        ->and($ids)->not->toContain($withMember->id);
});

test('selecting a user loads their current roles', function () {
    $user = User::factory()->create();
    $user->assignRole('website-editor');

    Volt::actingAs(adminWithRolesPermission())->test('admin.users.index')
        ->call('selectUser', $user->id)
        ->assertSet('assignedRoles', ['website-editor']);
});

test('saveRoles syncs roles onto the selected user', function () {
    $user = User::factory()->create();

    Volt::actingAs(adminWithRolesPermission())->test('admin.users.index')
        ->call('selectUser', $user->id)
        ->set('assignedRoles', ['website-editor'])
        ->call('saveRoles')
        ->assertHasNoErrors();

    expect($user->fresh()->hasRole('website-editor'))->toBeTrue();
});

test('admin cannot remove their own admin role from this page', function () {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = adminWithRolesPermission();
    $admin->assignRole('admin');

    Volt::actingAs($admin)->test('admin.users.index')
        ->call('selectUser', $admin->id)
        ->set('assignedRoles', [])
        ->call('saveRoles')
        ->assertHasErrors('assignedRoles');

    expect($admin->fresh()->hasRole('admin'))->toBeTrue();
});
