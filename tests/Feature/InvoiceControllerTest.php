<?php

use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('members.manage');
});

test('unauthenticated cannot download invoice', function () {
    $order = Order::factory()->create();
    $this->get(route('invoice.download', $order))->assertRedirect();
});

test('member can download their own invoice as PDF', function () {
    $user = User::factory()->create();
    $order = Order::factory()->paid()->create(['user_id' => $user->id]);
    OrderLine::factory()->create(['order_id' => $order->id]);

    $this->actingAs($user)
        ->get(route('invoice.download', $order))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('download response has attachment disposition', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('invoice.download', $order));

    $disposition = (string) $response->headers->get('Content-Disposition');
    $this->assertStringContainsString('attachment', $disposition);
    $this->assertStringContainsString('faktura-', $disposition);
});

test('member can view their own invoice inline', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);
    OrderLine::factory()->create(['order_id' => $order->id]);

    $this->actingAs($user)
        ->get(route('invoice.view', $order))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('view response has inline disposition', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('invoice.view', $order));

    $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
});

test('member cannot download another users invoice', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)
        ->get(route('invoice.download', $order))
        ->assertStatus(403);
});

test('member cannot view another users invoice', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)
        ->get(route('invoice.view', $order))
        ->assertStatus(403);
});

test('admin can download any invoice', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('members.manage');
    $order = Order::factory()->create();

    $this->actingAs($admin)
        ->get(route('invoice.download', $order))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('admin can view any invoice', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('members.manage');
    $order = Order::factory()->create();

    $this->actingAs($admin)
        ->get(route('invoice.view', $order))
        ->assertOk();
});

test('invoice filename contains invoice number', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('invoice.download', $order));

    $this->assertStringContainsString(
        (string) $order->invoice_number,
        (string) $response->headers->get('Content-Disposition')
    );
});
