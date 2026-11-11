<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('roles.manage');
});

function rolesAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('roles.manage');
    return $user;
}

// ── GET /api/v1/roles ─────────────────────────────────────────────────────────

test('roles list requires auth', function () {
    $this->getJson('/api/v1/roles')->assertUnauthorized();
});

test('roles list requires roles.manage permission', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/roles')->assertForbidden();
});

test('admin can list roles', function () {
    Role::create(['name' => 'kassör', 'guard_name' => 'web']);
    Sanctum::actingAs(rolesAdminUser());

    $this->getJson('/api/v1/roles')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

// ── POST /api/v1/roles ────────────────────────────────────────────────────────

test('admin can create role', function () {
    Sanctum::actingAs(rolesAdminUser());

    $this->postJson('/api/v1/roles', ['name' => 'kassör'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'kassör');
});

test('admin can create role with permissions', function () {
    $this->createPermission('members.view');
    Sanctum::actingAs(rolesAdminUser());

    $this->postJson('/api/v1/roles', [
        'name'        => 'läsare',
        'permissions' => ['members.view'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'läsare');

    expect(Role::findByName('läsare', 'web')->hasPermissionTo('members.view'))->toBeTrue();
});

test('cannot create duplicate role name', function () {
    Role::create(['name' => 'kassör', 'guard_name' => 'web']);
    Sanctum::actingAs(rolesAdminUser());

    $this->postJson('/api/v1/roles', ['name' => 'kassör'])->assertStatus(422);
});

// ── GET /api/v1/roles/{role} ──────────────────────────────────────────────────

test('admin can show role', function () {
    $role = Role::create(['name' => 'kassör', 'guard_name' => 'web']);
    Sanctum::actingAs(rolesAdminUser());

    $this->getJson("/api/v1/roles/{$role->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'kassör');
});

// ── PATCH /api/v1/roles/{role} ────────────────────────────────────────────────

test('admin can rename role', function () {
    $role = Role::create(['name' => 'gammalt', 'guard_name' => 'web']);
    Sanctum::actingAs(rolesAdminUser());

    $this->patchJson("/api/v1/roles/{$role->id}", ['name' => 'nytt'])
        ->assertOk()
        ->assertJsonPath('data.name', 'nytt');
});

test('admin can update role permissions', function () {
    $this->createPermission('members.view');
    $role = Role::create(['name' => 'testroll', 'guard_name' => 'web']);
    Sanctum::actingAs(rolesAdminUser());

    $this->patchJson("/api/v1/roles/{$role->id}", [
        'permissions' => ['members.view'],
    ])->assertOk();

    expect($role->fresh()->hasPermissionTo('members.view'))->toBeTrue();
});

// ── DELETE /api/v1/roles/{role} ───────────────────────────────────────────────

test('admin can delete role', function () {
    $role = Role::create(['name' => 'tmproll', 'guard_name' => 'web']);
    Sanctum::actingAs(rolesAdminUser());

    $this->deleteJson("/api/v1/roles/{$role->id}")->assertOk();

    expect(Role::find($role->id))->toBeNull();
});

// ── PUT /api/v1/users/{user}/roles ────────────────────────────────────────────

test('admin can sync user roles', function () {
    $role   = Role::create(['name' => 'kassör', 'guard_name' => 'web']);
    $target = User::factory()->create();
    Sanctum::actingAs(rolesAdminUser());

    $this->putJson("/api/v1/users/{$target->id}/roles", [
        'roles' => ['kassör'],
    ])
        ->assertOk()
        ->assertJsonPath('roles.0', 'kassör');
});

test('sync user roles removes old roles', function () {
    $roleA  = Role::create(['name' => 'kassör',   'guard_name' => 'web']);
    $roleB  = Role::create(['name' => 'sekreterare', 'guard_name' => 'web']);
    $target = User::factory()->create();
    $target->assignRole($roleA);
    Sanctum::actingAs(rolesAdminUser());

    $this->putJson("/api/v1/users/{$target->id}/roles", [
        'roles' => ['sekreterare'],
    ])->assertOk();

    expect($target->fresh()->hasRole('kassör'))->toBeFalse();
    expect($target->fresh()->hasRole('sekreterare'))->toBeTrue();
});

test('sync user roles requires roles.manage permission', function () {
    $target = User::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    $this->putJson("/api/v1/users/{$target->id}/roles", ['roles' => []])->assertForbidden();
});
