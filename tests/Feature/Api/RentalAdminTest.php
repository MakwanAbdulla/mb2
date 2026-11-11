<?php

use App\Models\Membership\Member;
use App\Models\Rental\Rental;
use App\Models\Rental\RentalItem;
use App\Models\Rental\RentalItemType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('rentals.view');
    $this->createPermission('rentals.manage');
});

function rentalsAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('rentals.manage');
    return $user;
}

function makeRentalItem(): RentalItem
{
    $type = RentalItemType::create(['name' => 'Nyckel', 'price_per_year' => 20000]);
    return RentalItem::create([
        'rental_item_type_id' => $type->id,
        'label'               => 'Nyckel #1',
        'is_available'        => true,
        'sort_order'          => 1,
    ]);
}

function makeRentalMember(): Member
{
    return Member::factory()->create(['status' => 'active']);
}

// ── GET /api/v1/admin/rentals ─────────────────────────────────────────────────

test('admin rentals list requires auth', function () {
    $this->getJson('/api/v1/admin/rentals')->assertUnauthorized();
});

test('admin rentals list requires rentals permission', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/admin/rentals')->assertForbidden();
});

test('admin can list active rentals', function () {
    $item   = makeRentalItem();
    $member = makeRentalMember();
    Rental::create([
        'rental_item_id' => $item->id,
        'member_id'      => $member->id,
        'price_per_year' => 20000,
        'started_at'     => now()->subMonth(),
    ]);

    Sanctum::actingAs(rentalsAdminUser());

    $this->getJson('/api/v1/admin/rentals')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('admin can include ended rentals', function () {
    $item   = makeRentalItem();
    $member = makeRentalMember();
    Rental::create([
        'rental_item_id' => $item->id,
        'member_id'      => $member->id,
        'price_per_year' => 20000,
        'started_at'     => now()->subYear(),
        'ended_at'       => now()->subMonth(),
    ]);

    Sanctum::actingAs(rentalsAdminUser());

    $this->getJson('/api/v1/admin/rentals?include_ended=1')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/admin/rentals')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ── POST /api/v1/admin/rentals ────────────────────────────────────────────────

test('admin can create rental', function () {
    $item   = makeRentalItem();
    $member = makeRentalMember();
    Sanctum::actingAs(rentalsAdminUser());

    $this->postJson('/api/v1/admin/rentals', [
        'member_id'      => $member->id,
        'rental_item_id' => $item->id,
        'price_per_year' => 20000,
        'started_at'     => '2027-01-01',
    ])
        ->assertCreated()
        ->assertJsonPath('data.price_per_year', 20000);
});

test('create rental requires rentals.manage permission', function () {
    $item   = makeRentalItem();
    $member = makeRentalMember();
    $user   = User::factory()->create();
    $user->givePermissionTo('rentals.view');
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/admin/rentals', [
        'member_id'      => $member->id,
        'rental_item_id' => $item->id,
        'price_per_year' => 20000,
        'started_at'     => '2027-01-01',
    ])->assertForbidden();
});

// ── PATCH /api/v1/admin/rentals/{rental} ──────────────────────────────────────

test('admin can end a rental', function () {
    $item   = makeRentalItem();
    $member = makeRentalMember();
    $rental = Rental::create([
        'rental_item_id' => $item->id,
        'member_id'      => $member->id,
        'price_per_year' => 20000,
        'started_at'     => now()->subMonth(),
    ]);
    Sanctum::actingAs(rentalsAdminUser());

    $this->patchJson("/api/v1/admin/rentals/{$rental->id}", [
        'ended_at' => now()->toDateString(),
    ])->assertOk();

    expect(Rental::find($rental->id)->ended_at)->not->toBeNull();
});

test('admin can update rental price', function () {
    $item   = makeRentalItem();
    $member = makeRentalMember();
    $rental = Rental::create([
        'rental_item_id' => $item->id,
        'member_id'      => $member->id,
        'price_per_year' => 20000,
        'started_at'     => now()->subMonth(),
    ]);
    Sanctum::actingAs(rentalsAdminUser());

    $this->patchJson("/api/v1/admin/rentals/{$rental->id}", [
        'price_per_year' => 30000,
    ])
        ->assertOk()
        ->assertJsonPath('data.price_per_year', 30000);
});
