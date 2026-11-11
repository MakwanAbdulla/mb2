<?php

use App\Models\Membership\Member;
use App\Models\Rental\Rental;
use App\Models\Rental\RentalItem;
use App\Models\Rental\RentalItemType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function apiRentalType(): RentalItemType
{
    return RentalItemType::create([
        'name'           => 'Skåp',
        'price_per_year' => 300_00,
        'is_active'      => true,
        'sort_order'     => 0,
    ]);
}

function apiRentalItem(RentalItemType $type, string $label = '1'): RentalItem
{
    return RentalItem::create([
        'rental_item_type_id' => $type->id,
        'label'               => $label,
        'is_available'        => true,
        'sort_order'          => 0,
    ]);
}

function apiRentalUser(): array
{
    $user   = User::factory()->create();
    $member = Member::factory()->create(['user_id' => $user->id, 'status' => 'active']);
    return [$user, $member];
}

// ── GET /api/v1/rentals ───────────────────────────────────────────────────────

test('returns active rentals for authenticated user', function () {
    [$user, $member] = apiRentalUser();
    $type  = apiRentalType();
    $item  = apiRentalItem($type);

    Rental::create([
        'rental_item_id' => $item->id,
        'member_id'      => $member->id,
        'price_per_year' => 300_00,
        'started_at'     => now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/rentals')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_active', true);
});

test('does not return ended rentals', function () {
    [$user, $member] = apiRentalUser();
    $type = apiRentalType();
    $item = apiRentalItem($type);

    Rental::create([
        'rental_item_id' => $item->id,
        'member_id'      => $member->id,
        'price_per_year' => 300_00,
        'started_at'     => now()->subYear()->toDateString(),
        'ended_at'       => now()->subDay()->toDateString(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/rentals')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('returns empty list for user without member record', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/rentals')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('returns 401 for unauthenticated request', function () {
    $this->getJson('/api/v1/rentals')->assertUnauthorized();
});

test('response includes item and type names', function () {
    [$user, $member] = apiRentalUser();
    $type = apiRentalType();
    $item = apiRentalItem($type, '42');

    Rental::create([
        'rental_item_id' => $item->id,
        'member_id'      => $member->id,
        'price_per_year' => 300_00,
        'started_at'     => now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/rentals')
        ->assertOk()
        ->assertJsonPath('data.0.item.label', '42')
        ->assertJsonPath('data.0.item.type.name', 'Skåp');
});

test('does not return other members rentals', function () {
    [$user1, $member1] = apiRentalUser();
    [$user2, $member2] = apiRentalUser();
    $type  = apiRentalType();
    $item1 = apiRentalItem($type, '1');
    $item2 = apiRentalItem($type, '2');

    Rental::create([
        'rental_item_id' => $item1->id,
        'member_id'      => $member1->id,
        'price_per_year' => 300_00,
        'started_at'     => now()->toDateString(),
    ]);
    Rental::create([
        'rental_item_id' => $item2->id,
        'member_id'      => $member2->id,
        'price_per_year' => 300_00,
        'started_at'     => now()->toDateString(),
    ]);

    $this->actingAs($user1)
        ->getJson('/api/v1/rentals')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
