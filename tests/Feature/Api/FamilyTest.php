<?php

use App\Models\Membership\FamilyGroup;
use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('unauthenticated cannot view family', function () {
    $this->getJson('/api/v1/family')->assertStatus(401);
});

test('member without family returns null data', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id, 'family_group_id' => null]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/family')
        ->assertOk()
        ->assertJsonPath('data', null);
});

test('user without member profile returns null data', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/family')
        ->assertOk()
        ->assertJsonPath('data', null);
});

test('member with family returns family data', function () {
    $user = User::factory()->create();
    $familyGroup = FamilyGroup::factory()->create();
    $member = Member::factory()->create(['user_id' => $user->id, 'family_group_id' => $familyGroup->id]);
    $familyGroup->update(['administrator_member_id' => $member->id]);
    DB::table('family_members')->insert([
        'family_group_id' => $familyGroup->id,
        'member_id'       => $member->id,
        'role'            => 'adult',
        'has_own_account' => true,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/family');

    $response->assertOk()
        ->assertJsonPath('data.id', $familyGroup->id)
        ->assertJsonStructure(['data' => ['id', 'administrator', 'members']])
        ->assertJsonCount(1, 'data.members');
});

test('family members include role and billing contact flag', function () {
    $user = User::factory()->create();
    $familyGroup = FamilyGroup::factory()->create();
    $member = Member::factory()->create(['user_id' => $user->id, 'family_group_id' => $familyGroup->id]);
    $familyGroup->update(['administrator_member_id' => $member->id]);
    DB::table('family_members')->insert([
        'family_group_id' => $familyGroup->id,
        'member_id'       => $member->id,
        'role'            => 'adult',
        'has_own_account' => true,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/family')
        ->assertJsonPath('data.members.0.role', 'adult')
        ->assertJsonPath('data.members.0.is_billing_contact', true)
        ->assertJsonPath('data.members.0.has_own_account', true);
});

test('unauthenticated cannot leave family', function () {
    $this->deleteJson('/api/v1/family/membership')->assertStatus(401);
});

test('member not in a family gets 422 on leave', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id, 'family_group_id' => null]);

    Sanctum::actingAs($user);

    $this->deleteJson('/api/v1/family/membership')->assertStatus(422);
});

test('member can leave family group', function () {
    $user = User::factory()->create();
    $admin = Member::factory()->create();
    $familyGroup = FamilyGroup::factory()->create(['administrator_member_id' => $admin->id]);
    $member = Member::factory()->create(['user_id' => $user->id, 'family_group_id' => $familyGroup->id]);
    DB::table('family_members')->insert([
        ['family_group_id' => $familyGroup->id, 'member_id' => $admin->id, 'role' => 'adult', 'has_own_account' => false],
        ['family_group_id' => $familyGroup->id, 'member_id' => $member->id, 'role' => 'adult', 'has_own_account' => true],
    ]);

    Sanctum::actingAs($user);

    $this->deleteJson('/api/v1/family/membership')->assertOk();

    expect($member->fresh()->family_group_id)->toBeNull();
    expect(DB::table('family_members')->where('member_id', $member->id)->exists())->toBeFalse();
});

test('billing contact cannot leave family', function () {
    $user = User::factory()->create();
    $familyGroup = FamilyGroup::factory()->create();
    $member = Member::factory()->create(['user_id' => $user->id, 'family_group_id' => $familyGroup->id]);
    $familyGroup->update(['administrator_member_id' => $member->id]);
    DB::table('family_members')->insert([
        'family_group_id' => $familyGroup->id,
        'member_id'       => $member->id,
        'role'            => 'adult',
        'has_own_account' => true,
    ]);

    Sanctum::actingAs($user);

    $this->deleteJson('/api/v1/family/membership')
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'Billing contact cannot leave. Transfer the role to another adult first.']);

    // Still in family
    expect($member->fresh()->family_group_id)->toBe($familyGroup->id);
});
