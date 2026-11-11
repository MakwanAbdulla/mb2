<?php

use App\Models\Membership\Member;
use App\Models\Rental\Rental;
use App\Models\Rental\RentalItem;
use App\Models\Rental\RentalItemType;
use App\Models\Rental\RentalWaitlist;
use App\Models\Shop\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function rentalType(array $attrs = []): RentalItemType
{
    return RentalItemType::create(array_merge([
        'name'          => 'Skåp',
        'price_per_year' => 300_00,
        'is_active'     => true,
        'sort_order'    => 0,
    ], $attrs));
}

function rentalItem(RentalItemType $type, string $label = '1', bool $available = true): RentalItem
{
    return RentalItem::create([
        'rental_item_type_id' => $type->id,
        'label'               => $label,
        'is_available'        => $available,
        'sort_order'          => 0,
    ]);
}

function rentalMember(): Member
{
    $user = User::factory()->create();
    return Member::factory()->create(['user_id' => $user->id, 'status' => 'active']);
}

// ── Model helpers ─────────────────────────────────────────────────────────────

test('isFree returns true when item has no active rental', function () {
    $type = rentalType();
    $item = rentalItem($type);

    expect($item->isFree())->toBeTrue();
});

test('isFree returns false when item has active rental', function () {
    $type   = rentalType();
    $item   = rentalItem($type);
    $member = rentalMember();

    Rental::create([
        'rental_item_id'  => $item->id,
        'member_id'       => $member->id,
        'price_per_year'  => 300_00,
        'started_at'      => now()->toDateString(),
    ]);

    expect($item->fresh()->isFree())->toBeFalse();
});

test('isFree returns false when item is not available', function () {
    $type = rentalType();
    $item = rentalItem($type, '2', false);

    expect($item->isFree())->toBeFalse();
});

test('activeRental returns null when no active rental exists', function () {
    $type = rentalType();
    $item = rentalItem($type);

    expect($item->activeRental)->toBeNull();
});

test('isActive returns true for rental without ended_at', function () {
    $type   = rentalType();
    $item   = rentalItem($type);
    $member = rentalMember();

    $rental = Rental::create([
        'rental_item_id' => $item->id,
        'member_id'      => $member->id,
        'price_per_year' => 300_00,
        'started_at'     => now()->toDateString(),
    ]);

    expect($rental->isActive())->toBeTrue();
});

test('isActive returns false after rental is ended', function () {
    $type   = rentalType();
    $item   = rentalItem($type);
    $member = rentalMember();

    $rental = Rental::create([
        'rental_item_id' => $item->id,
        'member_id'      => $member->id,
        'price_per_year' => 300_00,
        'started_at'     => now()->subYear()->toDateString(),
        'ended_at'       => now()->subDay()->toDateString(),
    ]);

    expect($rental->isActive())->toBeFalse();
});

// ── Rental::createOrder ───────────────────────────────────────────────────────

test('createOrder creates an Order with correct total', function () {
    $type   = rentalType(['price_per_year' => 300_00]);
    $item   = rentalItem($type);
    $member = rentalMember();

    $rental = Rental::create([
        'rental_item_id' => $item->id,
        'member_id'      => $member->id,
        'price_per_year' => 300_00,
        'started_at'     => now()->toDateString(),
    ]);

    $order = $rental->createOrder();

    expect($order)->toBeInstanceOf(Order::class)
        ->and($order->total_ore)->toBe(300_00)
        ->and($order->lines->count())->toBe(1);
});

test('createOrder line has CATEGORY_RENTAL', function () {
    $type   = rentalType();
    $item   = rentalItem($type);
    $member = rentalMember();

    $rental = Rental::create([
        'rental_item_id' => $item->id,
        'member_id'      => $member->id,
        'price_per_year' => 300_00,
        'started_at'     => now()->toDateString(),
    ]);

    $order = $rental->createOrder();
    $line  = $order->lines->first();

    expect($line->category)->toBe(\App\Models\Shop\OrderLine::CATEGORY_RENTAL);
});

// ── Rental::addLineToOrder ────────────────────────────────────────────────────

test('addLineToOrder appends line and increments order total', function () {
    $type   = rentalType(['price_per_year' => 200_00]);
    $item1  = rentalItem($type, '1');
    $item2  = rentalItem($type, '2');
    $member = rentalMember();

    $r1 = Rental::create([
        'rental_item_id' => $item1->id,
        'member_id'      => $member->id,
        'price_per_year' => 200_00,
        'started_at'     => now()->toDateString(),
    ]);
    $r2 = Rental::create([
        'rental_item_id' => $item2->id,
        'member_id'      => $member->id,
        'price_per_year' => 200_00,
        'started_at'     => now()->toDateString(),
    ]);

    $order = $r1->createOrder();
    $r2->addLineToOrder($order);

    expect($order->fresh()->total_ore)->toBe(400_00)
        ->and($order->lines()->count())->toBe(2);
});

// ── RentalItemType helpers ────────────────────────────────────────────────────

test('availableItems returns only free items', function () {
    $type   = rentalType();
    $free   = rentalItem($type, '1');
    $rented = rentalItem($type, '2');
    $member = rentalMember();

    Rental::create([
        'rental_item_id' => $rented->id,
        'member_id'      => $member->id,
        'price_per_year' => 300_00,
        'started_at'     => now()->toDateString(),
    ]);

    $available = $type->availableItems()->get();

    expect($available->pluck('id')->contains($free->id))->toBeTrue()
        ->and($available->pluck('id')->contains($rented->id))->toBeFalse();
});

test('waitlist relation returns members ordered by created_at', function () {
    $type = rentalType();
    $m1   = rentalMember();
    $m2   = rentalMember();

    RentalWaitlist::create(['rental_item_type_id' => $type->id, 'member_id' => $m1->id]);
    RentalWaitlist::create(['rental_item_type_id' => $type->id, 'member_id' => $m2->id]);

    expect($type->waitlist->pluck('member_id')->first())->toBe($m1->id);
});

test('formattedPrice formats öre as kr/år', function () {
    $type = rentalType(['price_per_year' => 300_00]);

    expect($type->formattedPrice())->toBe('300,00 kr/år');
});
