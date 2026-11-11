<?php

use App\Models\Membership\Member;
use App\Models\Organization\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('section can be created with factory', function () {
    $section = Section::factory()->create();

    expect($section)->toBeInstanceOf(Section::class)
        ->and($section->is_active)->toBeTrue()
        ->and($section->parent_id)->toBeNull();
});

test('section isRoot returns true when no parent', function () {
    $root = Section::factory()->create();
    $child = Section::factory()->create(['parent_id' => $root->id]);

    expect($root->isRoot())->toBeTrue()
        ->and($child->isRoot())->toBeFalse();
});

test('section parent relation', function () {
    $parent = Section::factory()->create();
    $child = Section::factory()->create(['parent_id' => $parent->id]);

    expect($child->parent)->toBeInstanceOf(Section::class)
        ->and($child->parent->id)->toBe($parent->id);
});

test('section children relation', function () {
    $parent = Section::factory()->create();
    Section::factory()->count(3)->create(['parent_id' => $parent->id]);

    expect($parent->children)->toHaveCount(3);
});

test('section allChildren eager loads nested children', function () {
    $root = Section::factory()->create();
    $child = Section::factory()->create(['parent_id' => $root->id]);
    Section::factory()->create(['parent_id' => $child->id]);

    $root->load('allChildren');

    expect($root->allChildren)->toHaveCount(1)
        ->and($root->allChildren->first()->allChildren)->toHaveCount(1);
});

test('section members via section_memberships pivot', function () {
    $section = Section::factory()->create();
    $member = Member::factory()->create();

    $section->members()->attach($member, ['joined_at' => now()->toDateString()]);

    expect($section->members)->toHaveCount(1);
});

test('section activeMembers excludes members with left_at set', function () {
    $section = Section::factory()->create();
    $active = Member::factory()->create();
    $left = Member::factory()->create();

    $section->members()->attach($active, ['joined_at' => now()->subMonths(6)->toDateString()]);
    $section->members()->attach($left, [
        'joined_at' => now()->subYear()->toDateString(),
        'left_at' => now()->subMonths(3)->toDateString(),
    ]);

    expect($section->activeMembers)->toHaveCount(1)
        ->and($section->activeMembers->first()->id)->toBe($active->id);
});

test('inactive factory state sets is_active to false', function () {
    $section = Section::factory()->inactive()->create();

    expect($section->is_active)->toBeFalse();
});
