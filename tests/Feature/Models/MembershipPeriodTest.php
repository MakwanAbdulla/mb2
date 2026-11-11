<?php

use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('membership period can be created with factory', function () {
    $period = MembershipPeriod::factory()->create();

    expect($period)->toBeInstanceOf(MembershipPeriod::class)
        ->and($period->year)->toBe(now()->year)
        ->and($period->status)->toBe(MembershipPeriod::STATUS_PENDING_PAYMENT);
});

test('membership period status constants are defined', function () {
    expect(MembershipPeriod::STATUS_PENDING_PAYMENT)->toBe('pending_payment')
        ->and(MembershipPeriod::STATUS_ACTIVE)->toBe('active')
        ->and(MembershipPeriod::STATUS_EXPIRED)->toBe('expired')
        ->and(MembershipPeriod::STATUS_CANCELLED)->toBe('cancelled');
});

test('membership period isActive returns true only for active status', function () {
    $active = MembershipPeriod::factory()->active()->create();
    $pending = MembershipPeriod::factory()->create();

    expect($active->isActive())->toBeTrue()
        ->and($pending->isActive())->toBeFalse();
});

test('membership period belongs to member', function () {
    $member = Member::factory()->create();
    $period = MembershipPeriod::factory()->create(['member_id' => $member->id]);

    expect($period->member)->toBeInstanceOf(Member::class)
        ->and($period->member->id)->toBe($member->id);
});

test('active membership period has activated_at and expires_at dates', function () {
    $period = MembershipPeriod::factory()->active()->create();

    expect($period->activated_at)->not->toBeNull()
        ->and($period->expires_at)->not->toBeNull();
});
