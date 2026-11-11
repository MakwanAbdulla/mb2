<?php

use App\Events\OrderPaymentFailed;
use App\Listeners\HandleOrderPaymentFailed;
use App\Mail\MembershipPaymentFailed;
use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function membershipOrder(array $periodAttrs = []): array
{
    $member = Member::factory()->create(array_merge(['status' => Member::STATUS_PENDING], $periodAttrs));
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

// ── Order::markFailed() ───────────────────────────────────────────────────────

test('markFailed sets order status to cancelled', function () {
    $order = Order::factory()->create(['status' => Order::STATUS_PENDING]);

    $order->markFailed('test_reason');

    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);
});

test('markFailed does nothing to an already-paid order', function () {
    $order = Order::factory()->create(['status' => Order::STATUS_PAID]);

    $order->markFailed('late_webhook');

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
});

test('markFailed fires OrderPaymentFailed event', function () {
    Event::fake([OrderPaymentFailed::class]);

    $order = Order::factory()->create(['status' => Order::STATUS_PENDING]);
    $order->markFailed('card_declined');

    Event::assertDispatched(OrderPaymentFailed::class, fn ($event) => $event->order->id === $order->id && $event->reason === 'card_declined');
});

// ── HandleOrderPaymentFailed listener ─────────────────────────────────────────

test('HandleOrderPaymentFailed sends mail when order has a membership line', function () {
    Mail::fake();

    [$member, $period, $order] = membershipOrder();
    $member->update(['email' => 'member@example.com']);

    (new HandleOrderPaymentFailed)->handle(new OrderPaymentFailed($order, 'card_declined'));

    Mail::assertQueued(MembershipPaymentFailed::class, fn ($mail) => $mail->member->id === $member->id && $mail->period->id === $period->id);
});

test('HandleOrderPaymentFailed does not send mail for non-membership orders', function () {
    Mail::fake();

    $order = Order::factory()->create(['status' => Order::STATUS_CANCELLED]);
    OrderLine::factory()->create([
        'order_id' => $order->id,
        'category' => OrderLine::CATEGORY_SHOP,
    ]);

    (new HandleOrderPaymentFailed)->handle(new OrderPaymentFailed($order, 'card_declined'));

    Mail::assertNotQueued(MembershipPaymentFailed::class);
});

test('HandleOrderPaymentFailed records an audit log entry', function () {
    Mail::fake();

    [, $period, $order] = membershipOrder();

    (new HandleOrderPaymentFailed)->handle(new OrderPaymentFailed($order, 'swish_declined'));

    expect(AuditLog::where('action', 'membership_payment_failed')->where('auditable_id', $period->id)->exists())->toBeTrue();
});

test('membership period is not activated when payment fails', function () {
    Mail::fake();

    [$member, $period, $order] = membershipOrder();

    $order->markFailed('card_declined');

    expect($period->fresh()->status)->toBe(MembershipPeriod::STATUS_PENDING_PAYMENT)
        ->and($member->fresh()->status)->toBe(Member::STATUS_PENDING);
});
