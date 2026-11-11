<?php

use App\Models\Events\Event;
use App\Models\Events\EventAddon;
use App\Models\Events\EventOccurrence;
use App\Models\Events\EventPriceTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('events.view');
    $this->createPermission('events.manage');
});

function adminEventsUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('events.manage');
    return $user;
}

// ── GET /api/v1/admin/events ──────────────────────────────────────────────────

test('admin events list requires auth', function () {
    $this->getJson('/api/v1/admin/events')->assertUnauthorized();
});

test('admin events list requires events.view or events.manage permission', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/admin/events')->assertForbidden();
});

test('admin events list returns all statuses', function () {
    Event::factory()->create(['title' => 'Draft']);
    Event::factory()->published()->create(['title' => 'Published']);
    Event::factory()->cancelled()->create(['title' => 'Cancelled']);

    Sanctum::actingAs(adminEventsUser());

    $this->getJson('/api/v1/admin/events')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('admin events list can filter by status', function () {
    Event::factory()->create();
    Event::factory()->published()->create();

    Sanctum::actingAs(adminEventsUser());

    $this->getJson('/api/v1/admin/events?status=draft')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ── POST /api/v1/admin/events ─────────────────────────────────────────────────

test('admin can create event draft', function () {
    Sanctum::actingAs(adminEventsUser());

    $this->postJson('/api/v1/admin/events', [
        'title'             => 'Nytt event',
        'visibility'        => 'public',
        'registration_mode' => 'standard',
        'admission_type'    => 'on_payment',
        'waitlist_mode'     => 'none',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.title', 'Nytt event');
});

test('admin create event auto-generates slug', function () {
    Sanctum::actingAs(adminEventsUser());

    $response = $this->postJson('/api/v1/admin/events', [
        'title'             => 'Stort Evenemang',
        'visibility'        => 'public',
        'registration_mode' => 'standard',
        'admission_type'    => 'on_payment',
        'waitlist_mode'     => 'none',
    ])->assertCreated();

    expect($response->json('data.slug'))->toBe('stort-evenemang');
});

test('admin create event requires events.manage permission', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('events.view');
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/admin/events', ['title' => 'Test'])->assertForbidden();
});

// ── PATCH /api/v1/admin/events/{event} ────────────────────────────────────────

test('admin can update event', function () {
    $event = Event::factory()->create(['title' => 'Gammalt namn']);
    Sanctum::actingAs(adminEventsUser());

    $this->patchJson("/api/v1/admin/events/{$event->id}", ['title' => 'Nytt namn'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Nytt namn');
});

// ── POST /api/v1/admin/events/{event}/publish ─────────────────────────────────

test('admin can publish a draft event', function () {
    $event = Event::factory()->create();
    Sanctum::actingAs(adminEventsUser());

    $this->postJson("/api/v1/admin/events/{$event->id}/publish")
        ->assertOk()
        ->assertJsonPath('status', 'published');
});

// ── POST /api/v1/admin/events/{event}/cancel ──────────────────────────────────

test('admin can cancel a published event', function () {
    $event = Event::factory()->published()->create();
    Sanctum::actingAs(adminEventsUser());

    $this->postJson("/api/v1/admin/events/{$event->id}/cancel", ['reason' => 'Lokalen stängd'])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');
});

// ── POST /api/v1/admin/events/{event}/occurrences ─────────────────────────────

test('admin can add occurrence to event', function () {
    $event = Event::factory()->create();
    Sanctum::actingAs(adminEventsUser());

    $this->postJson("/api/v1/admin/events/{$event->id}/occurrences", [
        'starts_at'         => '2027-01-15 18:00:00',
        'ends_at'           => '2027-01-15 22:00:00',
        'location_override' => 'Stora salen',
        'max_participants'  => 50,
    ])
        ->assertCreated()
        ->assertJsonPath('data.location_override', 'Stora salen')
        ->assertJsonPath('data.max_participants', 50);
});

test('admin can update occurrence', function () {
    $event      = Event::factory()->create();
    $occurrence = EventOccurrence::factory()->create(['event_id' => $event->id]);
    Sanctum::actingAs(adminEventsUser());

    $this->patchJson("/api/v1/admin/events/{$event->id}/occurrences/{$occurrence->id}", [
        'max_participants' => 100,
    ])->assertOk()->assertJsonPath('data.max_participants', 100);
});

test('admin can delete occurrence', function () {
    $event      = Event::factory()->create();
    $occurrence = EventOccurrence::factory()->create(['event_id' => $event->id]);
    Sanctum::actingAs(adminEventsUser());

    $this->deleteJson("/api/v1/admin/events/{$event->id}/occurrences/{$occurrence->id}")
        ->assertOk();

    expect(EventOccurrence::find($occurrence->id))->toBeNull();
});

// ── Price tiers ───────────────────────────────────────────────────────────────

test('admin can add price tier to event', function () {
    $event = Event::factory()->create();
    Sanctum::actingAs(adminEventsUser());

    $this->postJson("/api/v1/admin/events/{$event->id}/price-tiers", [
        'label'        => 'Standard',
        'price_ore'    => 15000,
        'members_only' => false,
    ])
        ->assertCreated()
        ->assertJsonPath('data.label', 'Standard')
        ->assertJsonPath('data.price_ore', 15000);
});

test('admin can update price tier', function () {
    $event = Event::factory()->create();
    $tier  = EventPriceTier::factory()->withPrice(10000)->create(['event_id' => $event->id]);
    Sanctum::actingAs(adminEventsUser());

    $this->patchJson("/api/v1/admin/events/{$event->id}/price-tiers/{$tier->id}", [
        'price_ore' => 20000,
    ])->assertOk()->assertJsonPath('data.price_ore', 20000);
});

test('admin can delete price tier', function () {
    $event = Event::factory()->create();
    $tier  = EventPriceTier::factory()->withPrice(10000)->create(['event_id' => $event->id]);
    Sanctum::actingAs(adminEventsUser());

    $this->deleteJson("/api/v1/admin/events/{$event->id}/price-tiers/{$tier->id}")
        ->assertOk();

    expect(EventPriceTier::find($tier->id))->toBeNull();
});

// ── Addons ────────────────────────────────────────────────────────────────────

test('admin can add addon to event', function () {
    $event = Event::factory()->create();
    Sanctum::actingAs(adminEventsUser());

    $this->postJson("/api/v1/admin/events/{$event->id}/addons", [
        'name'      => 'T-shirt',
        'price_ore' => 25000,
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'T-shirt');
});

test('admin can delete addon', function () {
    $event = Event::factory()->create();
    $addon = EventAddon::factory()->create(['event_id' => $event->id]);
    Sanctum::actingAs(adminEventsUser());

    $this->deleteJson("/api/v1/admin/events/{$event->id}/addons/{$addon->id}")
        ->assertOk();

    expect(EventAddon::find($addon->id))->toBeNull();
});
