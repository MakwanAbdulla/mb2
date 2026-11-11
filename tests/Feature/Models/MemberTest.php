<?php

use App\Models\Membership\FamilyGroup;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\Organization\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('member can be created with factory', function () {
    $member = Member::factory()->create();

    expect($member)->toBeInstanceOf(Member::class)
        ->and($member->first_name)->not->toBeEmpty()
        ->and($member->last_name)->not->toBeEmpty()
        ->and($member->membership_type)->toBe(Member::TYPE_SINGLE)
        ->and($member->status)->toBe(Member::STATUS_PENDING);
});

test('member full name attribute concatenates first and last name', function () {
    $member = Member::factory()->create(['first_name' => 'Anna', 'last_name' => 'Svensson']);

    expect($member->full_name)->toBe('Anna Svensson');
});

test('member isActive returns true only for active status', function () {
    $active = Member::factory()->active()->create();
    $pending = Member::factory()->create();

    expect($active->isActive())->toBeTrue()
        ->and($pending->isActive())->toBeFalse();
});

test('member status constants are defined', function () {
    expect(Member::STATUS_PENDING)->toBe('pending')
        ->and(Member::STATUS_ACTIVE)->toBe('active')
        ->and(Member::STATUS_INACTIVE)->toBe('inactive')
        ->and(Member::STATUS_EXPIRED)->toBe('expired')
        ->and(Member::STATUS_DECEASED)->toBe('deceased');
});

test('member type constants are defined', function () {
    expect(Member::TYPE_SINGLE)->toBe('single')
        ->and(Member::TYPE_FAMILY)->toBe('family')
        ->and(Member::TYPE_STUDENT)->toBe('student')
        ->and(Member::TYPE_HONORARY)->toBe('honorary');
});

test('member belongs to user', function () {
    $user = User::factory()->create();
    $member = Member::factory()->create(['user_id' => $user->id]);

    expect($member->user)->toBeInstanceOf(User::class)
        ->and($member->user->id)->toBe($user->id);
});

test('member belongs to family group', function () {
    $familyGroup = FamilyGroup::factory()->create();
    $member = Member::factory()->create(['family_group_id' => $familyGroup->id]);

    expect($member->familyGroup)->toBeInstanceOf(FamilyGroup::class)
        ->and($member->familyGroup->id)->toBe($familyGroup->id);
});

test('member has many membership periods', function () {
    $member = Member::factory()->create();
    MembershipPeriod::factory()->active()->create(['member_id' => $member->id, 'year' => now()->year]);

    expect($member->membershipPeriods)->toHaveCount(1);
});

test('member activePeriod returns current year active period', function () {
    $member = Member::factory()->create();
    $period = MembershipPeriod::factory()->active()->create([
        'member_id' => $member->id,
        'year' => now()->year,
    ]);

    expect($member->activePeriod())->not->toBeNull()
        ->and($member->activePeriod()->id)->toBe($period->id);
});

test('member activePeriod returns null when no active period exists', function () {
    $member = Member::factory()->create();

    expect($member->activePeriod())->toBeNull();
});

test('member belongs to sections via section_memberships', function () {
    $member = Member::factory()->create();
    $section = Section::factory()->create();

    $member->sections()->attach($section, ['joined_at' => now()->toDateString()]);

    expect($member->sections)->toHaveCount(1)
        ->and($member->sections->first()->id)->toBe($section->id);
});

test('member is soft deleted', function () {
    $member = Member::factory()->create();
    $id = $member->id;

    $member->delete();

    expect(Member::find($id))->toBeNull()
        ->and(Member::withTrashed()->find($id))->not->toBeNull();
});

// ── membership_number auto-assignment ────────────────────────────────────────

test('membership_number is auto-assigned sequentially', function () {
    $first = Member::factory()->create(['membership_number' => null]);
    $second = Member::factory()->create(['membership_number' => null]);

    expect((int) $second->membership_number)->toBe((int) $first->membership_number + 1);
});

test('membership_number assignment is numeric, not lexicographic, across the 9-to-10 boundary', function () {
    // MAX() on a varchar column compares as text ('9' > '10'), so without a
    // numeric CAST inside the aggregate, the 10th member would collide with
    // the 9th's number. Regression test for that exact bug.
    for ($i = 1; $i <= 11; $i++) {
        $member = Member::factory()->create(['membership_number' => null]);
    }

    expect((int) $member->membership_number)->toBe(11);
});

test('membership_number respects an explicitly provided value', function () {
    $member = Member::factory()->create(['membership_number' => '1215731']);

    expect($member->membership_number)->toBe('1215731');
});
