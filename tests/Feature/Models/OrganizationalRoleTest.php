<?php

use App\Models\Membership\Member;
use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\OrganizationalRoleMember;
use App\Models\Organization\OrganizationalRolePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('organizational role can be created with factory', function () {
    $role = OrganizationalRole::factory()->create();

    expect($role)->toBeInstanceOf(OrganizationalRole::class)
        ->and($role->cross_period_access)->toBeFalse();
});

test('organizational role withCrossPeriodAccess factory state', function () {
    $role = OrganizationalRole::factory()->withCrossPeriodAccess()->create();

    expect($role->cross_period_access)->toBeTrue();
});

test('organizational role has many periods', function () {
    $role = OrganizationalRole::factory()->create();
    OrganizationalRolePeriod::factory()->count(2)->create(['organizational_role_id' => $role->id]);

    expect($role->periods)->toHaveCount(2);
});

test('organizational role activePeriod returns active period', function () {
    $role = OrganizationalRole::factory()->create();
    $activePeriod = OrganizationalRolePeriod::factory()->create([
        'organizational_role_id' => $role->id,
        'status' => OrganizationalRolePeriod::STATUS_ACTIVE,
    ]);
    OrganizationalRolePeriod::factory()->closed()->create([
        'organizational_role_id' => $role->id,
    ]);

    expect($role->activePeriod())->not->toBeNull()
        ->and($role->activePeriod()->id)->toBe($activePeriod->id);
});

test('organizational role activePeriod returns null when no active period', function () {
    $role = OrganizationalRole::factory()->create();
    OrganizationalRolePeriod::factory()->closed()->create([
        'organizational_role_id' => $role->id,
    ]);

    expect($role->activePeriod())->toBeNull();
});

test('organizational role period status constants are defined', function () {
    expect(OrganizationalRolePeriod::STATUS_FORMING)->toBe('forming')
        ->and(OrganizationalRolePeriod::STATUS_ACTIVE)->toBe('active')
        ->and(OrganizationalRolePeriod::STATUS_REPORTING)->toBe('reporting')
        ->and(OrganizationalRolePeriod::STATUS_CLOSED)->toBe('closed');
});

test('organizational role period type constants are defined', function () {
    expect(OrganizationalRolePeriod::TYPE_CALENDAR_YEAR)->toBe('calendar_year')
        ->and(OrganizationalRolePeriod::TYPE_CUSTOM)->toBe('custom');
});

test('organizational role period belongs to organizational role', function () {
    $role = OrganizationalRole::factory()->create();
    $period = OrganizationalRolePeriod::factory()->create(['organizational_role_id' => $role->id]);

    expect($period->organizationalRole)->toBeInstanceOf(OrganizationalRole::class)
        ->and($period->organizationalRole->id)->toBe($role->id);
});

test('organizational role period has many members', function () {
    $period = OrganizationalRolePeriod::factory()->create();
    OrganizationalRoleMember::factory()->count(3)->create([
        'organizational_role_period_id' => $period->id,
    ]);

    expect($period->members)->toHaveCount(3);
});

test('organizational role period currentMembers returns only current transition role', function () {
    $period = OrganizationalRolePeriod::factory()->create();
    OrganizationalRoleMember::factory()->create([
        'organizational_role_period_id' => $period->id,
        'transition_role' => OrganizationalRoleMember::TRANSITION_CURRENT,
    ]);
    OrganizationalRoleMember::factory()->create([
        'organizational_role_period_id' => $period->id,
        'transition_role' => OrganizationalRoleMember::TRANSITION_INCOMING,
    ]);

    expect($period->currentMembers)->toHaveCount(1);
});

test('organizational role member transition constants are defined', function () {
    expect(OrganizationalRoleMember::TRANSITION_INCOMING)->toBe('incoming')
        ->and(OrganizationalRoleMember::TRANSITION_CURRENT)->toBe('current')
        ->and(OrganizationalRoleMember::TRANSITION_OUTGOING)->toBe('outgoing');
});

test('organizational role period computedStatus returns active when today is in range', function () {
    $year = now()->year;
    $period = OrganizationalRolePeriod::factory()->create([
        'active_start' => "{$year}-01-01",
        'active_end' => "{$year}-12-31",
        'status' => OrganizationalRolePeriod::STATUS_ACTIVE,
    ]);

    expect($period->computedStatus())->toBe(OrganizationalRolePeriod::STATUS_ACTIVE);
});

test('organizational role period computedStatus returns closed for past period', function () {
    $year = now()->year - 2;
    $period = OrganizationalRolePeriod::factory()->create([
        'year' => $year,
        'active_start' => "{$year}-01-01",
        'active_end' => "{$year}-12-31",
        'status' => OrganizationalRolePeriod::STATUS_CLOSED,
    ]);

    expect($period->computedStatus())->toBe(OrganizationalRolePeriod::STATUS_CLOSED);
});
