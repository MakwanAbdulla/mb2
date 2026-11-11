<?php

use App\Models\Membership\Member;
use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\OrganizationalRoleMember;
use App\Models\Organization\OrganizationalRolePeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('members.view');
    $this->createPermission('members.manage');
});

function orgRolesUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('members.view');
    return $user;
}

// ── GET /api/v1/org-roles ─────────────────────────────────────────────────────

test('org roles list requires auth', function () {
    $this->getJson('/api/v1/org-roles')->assertUnauthorized();
});

test('org roles list requires members permission', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/org-roles')->assertForbidden();
});

test('org roles list returns roles with periods', function () {
    $role = OrganizationalRole::factory()->create(['name' => 'Styrelsen']);
    OrganizationalRolePeriod::factory()->create(['organizational_role_id' => $role->id]);

    Sanctum::actingAs(orgRolesUser());

    // The "Ekonomi" org role is seeded by a migration (2026_05_27_000001), so the
    // list also contains that system role alongside the one this test creates.
    $response = $this->getJson('/api/v1/org-roles')->assertOk();
    $entry    = collect($response->json('data'))->firstWhere('name', 'Styrelsen');
    expect($entry)->not->toBeNull();
    expect($entry['periods'])->toHaveCount(1);
});

test('org roles list includes member count per period', function () {
    $role   = OrganizationalRole::factory()->create();
    $period = OrganizationalRolePeriod::factory()->create(['organizational_role_id' => $role->id]);
    $member = Member::factory()->create();

    OrganizationalRoleMember::factory()->create([
        'organizational_role_period_id' => $period->id,
        'member_id'                     => $member->id,
    ]);

    Sanctum::actingAs(orgRolesUser());

    $response = $this->getJson('/api/v1/org-roles')->assertOk();
    $entry    = collect($response->json('data'))->firstWhere('name', $role->name);
    expect($entry['periods'][0]['member_count'])->toBe(1);
});

// ── GET /api/v1/org-roles/{orgRole} ──────────────────────────────────────────

test('org role show returns periods with members', function () {
    $role   = OrganizationalRole::factory()->create(['name' => 'Revisorer']);
    $period = OrganizationalRolePeriod::factory()->create(['organizational_role_id' => $role->id]);
    $member = Member::factory()->create(['first_name' => 'Kalle', 'last_name' => 'Svensson']);

    OrganizationalRoleMember::factory()->create([
        'organizational_role_period_id' => $period->id,
        'member_id'                     => $member->id,
    ]);

    Sanctum::actingAs(orgRolesUser());

    $response = $this->getJson("/api/v1/org-roles/{$role->id}")->assertOk();
    expect($response->json('data.name'))->toBe('Revisorer');
    expect($response->json('data.periods.0.members'))->toHaveCount(1);
    expect($response->json('data.periods.0.members.0.full_name'))->toBe('Kalle Svensson');
});

test('org role show returns 404 for unknown role', function () {
    Sanctum::actingAs(orgRolesUser());
    $this->getJson('/api/v1/org-roles/99999')->assertNotFound();
});
