<?php

use App\Models\Membership\Member;
use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\OrganizationalRoleMember;
use App\Models\Organization\OrganizationalRolePeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function syncSetup(): array
{
    $orgRole    = OrganizationalRole::create(['name' => 'Styrelsen', 'slug' => 'styrelsen', 'description' => '']);
    $spatieRole = Role::firstOrCreate(['name' => 'styrelse', 'guard_name' => 'web']);

    return [$orgRole, $spatieRole];
}

function syncUserInPeriod(OrganizationalRole $orgRole, string $status, array $periodAttrs = []): User
{
    $user   = User::factory()->create();
    $member = Member::factory()->create(['user_id' => $user->id]);
    $year   = now()->year;

    $period = OrganizationalRolePeriod::create(array_merge([
        'organizational_role_id' => $orgRole->id,
        'name'                   => (string) $year,
        'period_type'            => OrganizationalRolePeriod::TYPE_CALENDAR_YEAR,
        'year'                   => $year,
        'active_start'           => "{$year}-01-01",
        'active_end'             => "{$year}-12-31",
        'status'                 => $status,
    ], $periodAttrs));

    OrganizationalRoleMember::create([
        'organizational_role_period_id' => $period->id,
        'member_id'                     => $member->id,
        'title'                         => 'Ledamot',
    ]);

    return $user;
}

// ── Granting roles ────────────────────────────────────────────────────────────

test('grants Spatie role to user in active period', function () {
    [$orgRole] = syncSetup();

    $user = syncUserInPeriod($orgRole, OrganizationalRolePeriod::STATUS_ACTIVE);

    Artisan::call('org-roles:sync-permissions');

    expect($user->fresh()->hasRole('styrelse'))->toBeTrue();
});

test('grants Spatie role to user in forming period', function () {
    [$orgRole] = syncSetup();

    $user = syncUserInPeriod($orgRole, OrganizationalRolePeriod::STATUS_FORMING);

    Artisan::call('org-roles:sync-permissions');

    expect($user->fresh()->hasRole('styrelse'))->toBeTrue();
});

test('grants Spatie role to user in reporting period', function () {
    [$orgRole] = syncSetup();

    $user = syncUserInPeriod($orgRole, OrganizationalRolePeriod::STATUS_REPORTING);

    Artisan::call('org-roles:sync-permissions');

    expect($user->fresh()->hasRole('styrelse'))->toBeTrue();
});

// ── Grace period (closed but recent) ─────────────────────────────────────────

test('keeps role during 6-month grace period after mandate ends', function () {
    [$orgRole, $spatieRole] = syncSetup();

    $year = now()->year - 1;
    $user = syncUserInPeriod($orgRole, OrganizationalRolePeriod::STATUS_CLOSED, [
        'year'           => $year,
        'name'           => (string) $year,
        'active_start'   => "{$year}-01-01",
        'active_end'     => "{$year}-12-31",
        'reporting_end'  => now()->subMonths(3)->toDateString(), // 3 months ago — within grace
    ]);
    $user->assignRole('styrelse');

    Artisan::call('org-roles:sync-permissions');

    expect($user->fresh()->hasRole('styrelse'))->toBeTrue();
});

test('revokes role after grace period expires', function () {
    [$orgRole, $spatieRole] = syncSetup();

    $year = now()->year - 2;
    $user = syncUserInPeriod($orgRole, OrganizationalRolePeriod::STATUS_CLOSED, [
        'year'          => $year,
        'name'          => (string) $year,
        'active_start'  => "{$year}-01-01",
        'active_end'    => "{$year}-12-31",
        'reporting_end' => now()->subMonths(8)->toDateString(), // 8 months > 6 month grace
    ]);
    $user->assignRole('styrelse');

    Artisan::call('org-roles:sync-permissions');

    expect($user->fresh()->hasRole('styrelse'))->toBeFalse();
});

// ── Revoking roles ────────────────────────────────────────────────────────────

test('revokes role from user not in any active/recent period', function () {
    [$orgRole, $spatieRole] = syncSetup();

    // User has role but no org-role membership at all
    $user = User::factory()->create();
    $user->assignRole('styrelse');

    Artisan::call('org-roles:sync-permissions');

    expect($user->fresh()->hasRole('styrelse'))->toBeFalse();
});

// ── Dry-run ───────────────────────────────────────────────────────────────────

test('dry-run does not grant roles', function () {
    [$orgRole] = syncSetup();

    $user = syncUserInPeriod($orgRole, OrganizationalRolePeriod::STATUS_ACTIVE);

    Artisan::call('org-roles:sync-permissions', ['--dry-run' => true]);

    expect($user->fresh()->hasRole('styrelse'))->toBeFalse();
});

test('dry-run does not revoke roles', function () {
    [$orgRole, $spatieRole] = syncSetup();

    $user = User::factory()->create();
    $user->assignRole('styrelse');

    Artisan::call('org-roles:sync-permissions', ['--dry-run' => true]);

    expect($user->fresh()->hasRole('styrelse'))->toBeTrue();
});

// ── Missing Spatie role ───────────────────────────────────────────────────────

test('handles missing Spatie role gracefully without crashing', function () {
    // Create org role with slug 'styrelsen' but do NOT create the 'styrelse' Spatie role
    OrganizationalRole::create(['name' => 'Styrelsen', 'slug' => 'styrelsen', 'description' => '']);

    $result = Artisan::call('org-roles:sync-permissions');

    expect($result)->toBe(0);
});
