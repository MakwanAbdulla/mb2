<?php

use App\Models\Membership\Member;
use App\Models\Membership\MembershipFee;
use App\Models\Membership\MembershipPeriod;
use App\Models\Shop\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function membershipFeeForCurrentYear(): MembershipFee
{
    return MembershipFee::create([
        'year' => now()->year,
        'membership_type' => Member::TYPE_SINGLE,
        'is_active' => true,
        'amount' => 15000,
    ]);
}

test('applying for membership creates member, order and period', function () {
    Mail::fake();
    membershipFeeForCurrentYear();
    $user = User::factory()->create();

    Volt::actingAs($user)->test('membership.apply')
        ->set('first_name', 'Anna')
        ->set('last_name', 'Andersson')
        ->set('email', $user->email)
        ->call('save')
        ->assertRedirect(route('membership.payment'));

    $member = Member::where('user_id', $user->id)->first();
    expect($member)->not->toBeNull()
        ->and($member->status)->toBe(Member::STATUS_PENDING);
    expect(MembershipPeriod::where('member_id', $member->id)->exists())->toBeTrue();
    expect(Order::where('user_id', $user->id)->exists())->toBeTrue();
});

test('a mail failure while applying does not block the membership application', function () {
    membershipFeeForCurrentYear();
    $user = User::factory()->create();

    Mail::shouldReceive('to')
        ->andThrow(new RuntimeException('GmailApiTransport: could not build Gmail client'));

    Volt::actingAs($user)->test('membership.apply')
        ->set('first_name', 'Anna')
        ->set('last_name', 'Andersson')
        ->set('email', $user->email)
        ->call('save')
        ->assertRedirect(route('membership.payment'));

    $member = Member::where('user_id', $user->id)->first();
    expect($member)->not->toBeNull();
    expect(MembershipPeriod::where('member_id', $member->id)->exists())->toBeTrue();
    expect(Order::where('user_id', $user->id)->exists())->toBeTrue();
});
