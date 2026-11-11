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

// #132: Styrelse and Revisor only have payments.view, but saw Bekräfta/Avslå
// and the Bankgiro-import tab, and a denied Bekräfta looked like it worked.

beforeEach(function () {
    $this->createPermission('payments.view');
    $this->createPermission('payments.confirm');
    $this->createPermission('payments.import');
    $this->createPermission('users.manage-roles');
});

function paymentsUserWith(string ...$permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('payments.view', ...$permissions);

    return $user;
}

function pendingPaymentWithOrder(): array
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

test('a read-only user sees pending payments but no actions or import tab', function () {
    [$member] = pendingPaymentWithOrder();

    Volt::actingAs(paymentsUserWith())->test('admin.payments.index')
        ->assertSee($member->full_name)
        ->assertDontSee('Bekräfta betalning')
        ->assertDontSee('Avslå')
        ->assertDontSee('Bankgiro-import');
});

test('payments.confirm shows Bekräfta and Avslå, payments.import shows the import tab', function () {
    pendingPaymentWithOrder();

    Volt::actingAs(paymentsUserWith('payments.confirm'))->test('admin.payments.index')
        ->assertSee('Bekräfta betalning')
        ->assertSee('Avslå betalning')
        ->assertDontSee('Bankgiro-import');

    Volt::actingAs(paymentsUserWith('payments.import'))->test('admin.payments.index')
        ->assertSee('Bankgiro-import')
        ->assertDontSee('Bekräfta betalning');
});

test('a read-only user cannot open the import tab by setting it directly', function () {
    Volt::actingAs(paymentsUserWith())->test('admin.payments.index')
        ->set('activeTab', 'import')
        ->assertDontSee('Ladda upp betalfil');
});

test('a denied confirmPayment shows an error and changes nothing', function () {
    [, $period, $order] = pendingPaymentWithOrder();

    Volt::actingAs(paymentsUserWith())->test('admin.payments.index')
        ->call('confirmPayment', $period->id)
        ->assertHasErrors('payment')
        ->assertSee('Ingenting sparades')
        ->assertNotDispatched('payment-handled');

    expect($order->fresh()->status)->toBe(Order::STATUS_PENDING)
        ->and($period->fresh()->status)->toBe(MembershipPeriod::STATUS_PENDING_PAYMENT);
});

test('confirming and rejecting tell the row to close only after it was saved', function () {
    Mail::fake();
    [, $confirmed] = pendingPaymentWithOrder();
    [, $rejected] = pendingPaymentWithOrder();

    Volt::actingAs(paymentsUserWith('payments.confirm'))->test('admin.payments.index')
        ->call('confirmPayment', $confirmed->id)
        ->assertHasNoErrors()
        ->assertDispatched('payment-handled', id: $confirmed->id)
        ->call('rejectPayment', $rejected->id)
        ->assertDispatched('payment-handled', id: $rejected->id);
});
