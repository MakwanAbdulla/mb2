<?php

use App\Models\Accounting\ExpenseClaim;
use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('expenses.view');
    $this->createPermission('expenses.manage');
});

function expensesAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('expenses.manage');
    return $user;
}

function makeExpenseMember(User $user): Member
{
    return Member::factory()->create(['user_id' => $user->id, 'status' => 'active']);
}

function makeExpenseClaim(Member $member, string $status = 'submitted'): ExpenseClaim
{
    return ExpenseClaim::create([
        'member_id'    => $member->id,
        'description'  => 'Inköp av pennor',
        'amount_ore'   => 15000,
        'claim_type'   => 'reimbursement',
        'purchased_at' => '2027-01-10',
        'status'       => $status,
        'submitted_at' => now(),
    ]);
}

// ── POST /api/v1/expense-claims ───────────────────────────────────────────────

test('member can submit expense claim', function () {
    $user   = User::factory()->create();
    $member = makeExpenseMember($user);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/expense-claims', [
        'description'  => 'Köpte pennor',
        'amount_ore'   => 5000,
        'claim_type'   => 'reimbursement',
        'purchased_at' => '2027-01-15',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'submitted');
});

test('submit expense claim requires member profile', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/expense-claims', [
        'description'  => 'Test',
        'amount_ore'   => 5000,
        'claim_type'   => 'reimbursement',
        'purchased_at' => '2027-01-15',
    ])->assertStatus(422);
});

test('submit expense claim requires auth', function () {
    $this->postJson('/api/v1/expense-claims', [])->assertUnauthorized();
});

// ── GET /api/v1/expense-claims ────────────────────────────────────────────────

test('member sees only own claims', function () {
    $userA  = User::factory()->create();
    $userB  = User::factory()->create();
    $membA  = makeExpenseMember($userA);
    $membB  = makeExpenseMember($userB);
    makeExpenseClaim($membA);
    makeExpenseClaim($membB);

    Sanctum::actingAs($userA);

    $this->getJson('/api/v1/expense-claims')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('admin sees all claims', function () {
    $userA = User::factory()->create();
    $membA = makeExpenseMember($userA);
    makeExpenseClaim($membA);
    makeExpenseClaim($membA);

    Sanctum::actingAs(expensesAdminUser());

    $this->getJson('/api/v1/expense-claims')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── GET /api/v1/expense-claims/{claim} ────────────────────────────────────────

test('member can view own claim', function () {
    $user   = User::factory()->create();
    $member = makeExpenseMember($user);
    $claim  = makeExpenseClaim($member);
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/expense-claims/{$claim->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $claim->id);
});

test('member cannot view other members claim', function () {
    $userA  = User::factory()->create();
    $userB  = User::factory()->create();
    $membB  = makeExpenseMember($userB);
    $claim  = makeExpenseClaim($membB);
    Sanctum::actingAs($userA);

    $this->getJson("/api/v1/expense-claims/{$claim->id}")->assertForbidden();
});

// ── POST /api/v1/expense-claims/{claim}/approve ───────────────────────────────

test('admin can approve submitted claim', function () {
    $user   = User::factory()->create();
    $member = makeExpenseMember($user);
    $claim  = makeExpenseClaim($member);
    Sanctum::actingAs(expensesAdminUser());

    $this->postJson("/api/v1/expense-claims/{$claim->id}/approve")
        ->assertOk()
        ->assertJsonPath('status', 'approved');
});

test('cannot approve already approved claim', function () {
    $user   = User::factory()->create();
    $member = makeExpenseMember($user);
    $claim  = makeExpenseClaim($member, 'approved');
    Sanctum::actingAs(expensesAdminUser());

    $this->postJson("/api/v1/expense-claims/{$claim->id}/approve")
        ->assertStatus(422);
});

// ── POST /api/v1/expense-claims/{claim}/reject ────────────────────────────────

test('admin can reject submitted claim', function () {
    $user   = User::factory()->create();
    $member = makeExpenseMember($user);
    $claim  = makeExpenseClaim($member);
    Sanctum::actingAs(expensesAdminUser());

    $this->postJson("/api/v1/expense-claims/{$claim->id}/reject", [
        'note' => 'Saknar kvitto',
    ])
        ->assertOk()
        ->assertJsonPath('status', 'rejected');
});

test('cannot reject already rejected claim', function () {
    $user   = User::factory()->create();
    $member = makeExpenseMember($user);
    $claim  = makeExpenseClaim($member, 'rejected');
    Sanctum::actingAs(expensesAdminUser());

    $this->postJson("/api/v1/expense-claims/{$claim->id}/reject")
        ->assertStatus(422);
});

test('approve requires expenses.manage permission', function () {
    $user   = User::factory()->create();
    $member = makeExpenseMember($user);
    $claim  = makeExpenseClaim($member);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/expense-claims/{$claim->id}/approve")->assertForbidden();
});
