<?php

use App\Models\Membership\AccountDeletionRequest;
use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('members.manage');
});

test('unauthenticated cannot submit deletion request', function () {
    $this->postJson('/api/v1/account-deletion-requests')->assertStatus(401);
});

test('member can submit a deletion request', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/account-deletion-requests', ['reason' => 'I want to leave'])
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'pending');

    expect(AccountDeletionRequest::where('user_id', $user->id)->where('status', 'pending')->exists())->toBeTrue();
});

test('duplicate pending request returns 409', function () {
    $user = User::factory()->create();
    $member = Member::factory()->create(['user_id' => $user->id]);
    AccountDeletionRequest::create([
        'member_id' => $member->id,
        'user_id'   => $user->id,
        'status'    => AccountDeletionRequest::STATUS_PENDING,
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/account-deletion-requests')
        ->assertStatus(409);
});

test('member without profile gets 404', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/account-deletion-requests')
        ->assertStatus(404);
});

test('deletion request reason is optional', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/account-deletion-requests', [])
        ->assertStatus(201);
});

test('unauthenticated cannot cancel deletion request', function () {
    $this->deleteJson('/api/v1/account-deletion-requests/pending')->assertStatus(401);
});

test('member can cancel pending request', function () {
    $user = User::factory()->create();
    $member = Member::factory()->create(['user_id' => $user->id]);
    AccountDeletionRequest::create([
        'member_id' => $member->id,
        'user_id'   => $user->id,
        'status'    => AccountDeletionRequest::STATUS_PENDING,
    ]);

    Sanctum::actingAs($user);

    $this->deleteJson('/api/v1/account-deletion-requests/pending')->assertOk();

    expect(AccountDeletionRequest::where('member_id', $member->id)->where('status', 'pending')->exists())->toBeFalse();
});

test('cancelling when no pending request returns 404', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $this->deleteJson('/api/v1/account-deletion-requests/pending')->assertStatus(404);
});

test('admin can list deletion requests', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('members.manage');
    AccountDeletionRequest::factory()->count(3)->create();

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/account-deletion-requests')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('admin can filter deletion requests by status', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('members.manage');
    AccountDeletionRequest::factory()->count(2)->create(['status' => 'pending']);
    AccountDeletionRequest::factory()->count(3)->create(['status' => 'rejected']);

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/account-deletion-requests?status=pending')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('admin can approve deletion request', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('members.manage');
    $user = User::factory()->create();
    $member = Member::factory()->active()->create(['user_id' => $user->id]);
    $req = AccountDeletionRequest::create([
        'member_id' => $member->id,
        'user_id'   => $user->id,
        'status'    => AccountDeletionRequest::STATUS_PENDING,
    ]);

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/admin/account-deletion-requests/{$req->id}/approve")
        ->assertOk();

    expect($req->fresh()->status)->toBe('approved');
    expect($member->fresh()->first_name)->toBe('Raderad');
});

test('admin cannot approve already actioned request', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('members.manage');
    $member = Member::factory()->create();
    $req = AccountDeletionRequest::create([
        'member_id' => $member->id,
        'user_id'   => $member->user_id ?? User::factory()->create()->id,
        'status'    => AccountDeletionRequest::STATUS_REJECTED,
    ]);

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/admin/account-deletion-requests/{$req->id}/approve")
        ->assertStatus(422);
});

test('admin can reject deletion request with note', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('members.manage');
    $user = User::factory()->create();
    $member = Member::factory()->create(['user_id' => $user->id]);
    $req = AccountDeletionRequest::create([
        'member_id' => $member->id,
        'user_id'   => $user->id,
        'status'    => AccountDeletionRequest::STATUS_PENDING,
    ]);

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/admin/account-deletion-requests/{$req->id}/reject", [
        'admin_note' => 'Active membership obligations remain.',
    ])->assertOk();

    expect($req->fresh()->status)->toBe('rejected')
        ->and($req->fresh()->admin_note)->toBe('Active membership obligations remain.');
});

test('rejection requires admin_note', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('members.manage');
    $member = Member::factory()->create();
    $req = AccountDeletionRequest::create([
        'member_id' => $member->id,
        'user_id'   => $member->user_id ?? User::factory()->create()->id,
        'status'    => AccountDeletionRequest::STATUS_PENDING,
    ]);

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/admin/account-deletion-requests/{$req->id}/reject", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['admin_note']);
});

test('non-admin cannot access admin deletion request endpoints', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/admin/account-deletion-requests')->assertStatus(403);
});
