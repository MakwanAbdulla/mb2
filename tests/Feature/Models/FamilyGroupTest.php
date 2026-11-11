<?php

use App\Models\Membership\FamilyGroup;
use App\Models\Membership\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('family group can be created with factory', function () {
    $group = FamilyGroup::factory()->create();

    expect($group)->toBeInstanceOf(FamilyGroup::class);
});

test('family group role constants are defined', function () {
    expect(FamilyGroup::ROLE_ADULT)->toBe('adult')
        ->and(FamilyGroup::ROLE_CHILD)->toBe('child');
});

test('family group administrator relation', function () {
    $admin = Member::factory()->create();
    $group = FamilyGroup::factory()->create(['administrator_member_id' => $admin->id]);

    expect($group->administrator)->toBeInstanceOf(Member::class)
        ->and($group->administrator->id)->toBe($admin->id);
});

test('family group has many members', function () {
    $group = FamilyGroup::factory()->create();
    Member::factory()->count(3)->create(['family_group_id' => $group->id]);

    expect($group->members)->toHaveCount(3);
});

test('family group adults returns only adult pivot members', function () {
    $group = FamilyGroup::factory()->create();
    $adult1 = Member::factory()->create(['family_group_id' => $group->id]);
    $adult2 = Member::factory()->create(['family_group_id' => $group->id]);
    $child = Member::factory()->create(['family_group_id' => $group->id]);

    $group->membersWithPivot()->attach($adult1, ['role' => FamilyGroup::ROLE_ADULT]);
    $group->membersWithPivot()->attach($adult2, ['role' => FamilyGroup::ROLE_ADULT]);
    $group->membersWithPivot()->attach($child, ['role' => FamilyGroup::ROLE_CHILD]);

    expect($group->adults)->toHaveCount(2)
        ->and($group->children)->toHaveCount(1);
});
