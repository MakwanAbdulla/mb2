<?php

use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('my rentals link points to the rentals tab for members with full access', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Mina hyror')
        ->assertSee(route('dashboard').'#hyror', false);
});

test('my rentals link is hidden for accounts without full access', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Mina hyror')
        ->assertDontSee(route('dashboard').'#hyror', false);
});

test('non-members see my orders and my registrations but not booking links', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Mina beställningar')
        ->assertSee(route('shop.my-orders'), false)
        ->assertSee('Mina anmälningar')
        ->assertSee(route('events.my-registrations'), false)
        ->assertDontSee('Mina bokningar')
        ->assertDontSee(route('booking.my'), false)
        ->assertDontSee(route('booking.create'), false);
});

test('members see booking links, my orders and my registrations', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Mina bokningar')
        ->assertSee(route('booking.my'), false)
        ->assertSee(route('booking.create'), false)
        ->assertSee('Mina beställningar')
        ->assertSee('Mina anmälningar');
});

test('app layout hides booking links from non-members', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('meetings.mine'))
        ->assertOk()
        ->assertSee(route('booking.calendar'), false)
        ->assertDontSee(route('booking.my'), false)
        ->assertDontSee(route('booking.create'), false)
        ->assertDontSee(route('booking.venue-application.create'), false);
});

test('app layout shows booking links to members', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    $this->actingAs($user)
        ->get(route('meetings.mine'))
        ->assertOk()
        ->assertSee(route('booking.my'), false)
        ->assertSee(route('booking.create'), false)
        ->assertSee(route('booking.venue-application.create'), false);
});

test('non-members can open my orders and my registrations', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('shop.my-orders'))->assertOk();
    $this->actingAs($user)->get(route('events.my-registrations'))->assertOk();
});
