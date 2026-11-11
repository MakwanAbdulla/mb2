<?php

use App\Models\Events\Event;
use App\Models\Shop\Order;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    SystemSetting::set('payment.swish_number', '1231234567');
    SystemSetting::set('payment.swish_type', 'merchant');
});

test('shop checkout shows the Swish Handel API button when swish_type is merchant', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['status' => Order::STATUS_PENDING, 'user_id' => $user->id]);

    session(['shop_order_id' => $order->id]);

    Volt::actingAs($user)->test('shop.checkout')
        ->assertSeeHtml('Betala med Swish')
        ->assertDontSee('Swisha till:');
});

test('shop checkout falls back to manual QR when swish_type is not merchant', function () {
    SystemSetting::set('payment.swish_type', 'company');

    $user = User::factory()->create();
    $order = Order::factory()->create(['status' => Order::STATUS_PENDING, 'user_id' => $user->id]);

    session(['shop_order_id' => $order->id]);

    Volt::actingAs($user)->test('shop.checkout')
        ->assertSee('Swisha till:')
        ->assertDontSeeHtml('Betala med Swish');
});

test('event payment shows the Swish Handel API button when swish_type is merchant', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $order = Order::factory()->create(['status' => Order::STATUS_PENDING, 'user_id' => $user->id]);

    session(['event_order_id' => $order->id]);

    Volt::actingAs($user)->test('events.payment', ['event' => $event])
        ->assertSeeHtml('Betala med Swish')
        ->assertSee('Klicka nedan för att initiera en Swish-betalning till ditt mobilnummer.')
        ->assertDontSee('Swisha till:');
});

test('event payment falls back to manual QR when swish_type is not merchant', function () {
    SystemSetting::set('payment.swish_type', 'company');

    $user = User::factory()->create();
    $event = Event::factory()->create();
    $order = Order::factory()->create(['status' => Order::STATUS_PENDING, 'user_id' => $user->id]);

    session(['event_order_id' => $order->id]);

    Volt::actingAs($user)->test('events.payment', ['event' => $event])
        ->assertSee('Swisha till:')
        ->assertDontSeeHtml('Betala med Swish');
});
