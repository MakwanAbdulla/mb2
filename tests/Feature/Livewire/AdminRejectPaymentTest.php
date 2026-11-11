<?php

use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('payments.view');
    $this->createPermission('payments.confirm');
    $this->createPermission('users.manage-roles');
});

function paymentsAdmin(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('payments.view', 'payments.confirm');

    return $user;
}

function pendingMembershipPeriod(): array
{
    $member = Member::factory()->create(['status' => Member::STATUS_PENDING]);
    $period = MembershipPeriod::factory()->create([
        'member_id' => $member->id,
        'status' => MembershipPeriod::STATUS_PENDING_PAYMENT,
    ]);
    $order = Order::factory()->create(['status' => Order::STATUS_PENDING, 'user_id' => $member->user_id]);
    OrderLine::factory()->create([
        'order_id' => $order->id,
        'category' => OrderLine::CATEGORY_MEMBERSHIP,
        'orderable_type' => MembershipPeriod::class,
        'orderable_id' => $period->id,
    ]);
    $period->update(['order_id' => $order->id]);

    return [$member, $period, $order];
}

test('rejectPayment requires payments.confirm', function () {
    [, $period, $order] = pendingMembershipPeriod();
    $user = User::factory()->create();
    $user->givePermissionTo('payments.view');

    Volt::actingAs($user)->test('admin.payments.index')
        ->call('rejectPayment', $period->id)
        ->assertHasErrors('payment')
        ->assertNotDispatched('payment-handled');

    expect($order->fresh()->status)->toBe(Order::STATUS_PENDING);
});

test('rejectPayment marks the order as failed and leaves the period pending', function () {
    Mail::fake();
    [, $period, $order] = pendingMembershipPeriod();

    Volt::actingAs(paymentsAdmin())->test('admin.payments.index')
        ->set('rejectNote', 'Kortet nekades')
        ->call('rejectPayment', $period->id);

    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED)
        ->and($period->fresh()->status)->toBe(MembershipPeriod::STATUS_PENDING_PAYMENT);
});

test('rejectPayment does nothing for an already-active period', function () {
    [, $period, $order] = pendingMembershipPeriod();
    $period->update(['status' => MembershipPeriod::STATUS_ACTIVE]);

    Volt::actingAs(paymentsAdmin())->test('admin.payments.index')
        ->call('rejectPayment', $period->id);

    expect($order->fresh()->status)->toBe(Order::STATUS_PENDING);
});
