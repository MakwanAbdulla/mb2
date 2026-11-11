<?php

use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('members.manage');
});

test('unauthenticated cannot list orders', function () {
    $this->getJson('/api/v1/orders')->assertStatus(401);
});

test('authenticated user can list own orders', function () {
    $user = User::factory()->create();
    Order::factory()->count(3)->create(['user_id' => $user->id]);
    Order::factory()->create(); // another user's order

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/orders');

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'payment_reference', 'ocr_number', 'status', 'total_ore', 'total_formatted', 'paid_at', 'created_at']],
            'meta',
            'links',
        ]);
});

test('order list does not include other users orders', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Order::factory()->count(2)->create(['user_id' => $user->id]);
    Order::factory()->count(5)->create(['user_id' => $other->id]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('order list returns empty when user has no orders', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('order list paginates with per_page parameter', function () {
    $user = User::factory()->create();
    Order::factory()->count(10)->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/orders?per_page=3')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 10)
        ->assertJsonPath('meta.per_page', 3);
});

test('unauthenticated cannot view order', function () {
    $order = Order::factory()->create();

    $this->getJson("/api/v1/orders/{$order->id}")->assertStatus(401);
});

test('user can view own order with lines', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);
    OrderLine::factory()->create(['order_id' => $order->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/orders/{$order->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $order->id)
        ->assertJsonStructure([
            'data' => ['id', 'payment_reference', 'ocr_number', 'status', 'total_ore', 'total_formatted', 'lines'],
        ])
        ->assertJsonCount(1, 'data.lines');
});

test('user cannot view another users order', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $other->id]);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/orders/{$order->id}")->assertStatus(403);
});

test('admin can view any order', function () {
    $admin = User::factory()->create();
    $this->createPermission('members.manage');
    $admin->givePermissionTo('members.manage');
    $order = Order::factory()->create();

    Sanctum::actingAs($admin);

    $this->getJson("/api/v1/orders/{$order->id}")->assertOk();
});

test('order resource includes correct status for paid order', function () {
    $user = User::factory()->create();
    $order = Order::factory()->paid()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/orders/{$order->id}")
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.paid_at', $order->paid_at->toIso8601String());
});

test('order resource formats total_formatted correctly', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'total_ore' => 25000]);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/orders/{$order->id}")
        ->assertJsonPath('data.total_ore', 25000)
        ->assertJsonPath('data.total_formatted', '250,00 kr');
});
