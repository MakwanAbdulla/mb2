<?php

use App\Models\Events\Event;
use App\Models\Events\EventPriceTier;
use App\Models\Events\EventOccurrence;
use App\Models\Membership\Member;
use App\Models\Organization\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── GET /api/v1/events ────────────────────────────────────────────────────────

test('event list returns only published events', function () {
    Event::factory()->published()->create(['title' => 'Published']);
    Event::factory()->create(['title' => 'Draft']);
    Event::factory()->cancelled()->create(['title' => 'Cancelled']);

    $this->getJson('/api/v1/events')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Published');
});

test('event list includes public events for unauthenticated users', function () {
    Event::factory()->published()->count(3)->create();

    $this->getJson('/api/v1/events')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('event list hides members_only events from unauthenticated users', function () {
    Event::factory()->published()->create(['visibility' => Event::VISIBILITY_PUBLIC]);
    Event::factory()->published()->membersOnly()->create();

    $this->getJson('/api/v1/events')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('event list shows members_only events to authenticated users', function () {
    Event::factory()->published()->create(['visibility' => Event::VISIBILITY_PUBLIC]);
    Event::factory()->published()->membersOnly()->create();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/events')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('event list never includes invite_only events', function () {
    Event::factory()->published()->create(['visibility' => Event::VISIBILITY_INVITE_ONLY]);

    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/events')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('event list shows section_only events to members of that section', function () {
    $section = Section::factory()->create();
    Event::factory()->published()->create([
        'visibility'            => Event::VISIBILITY_SECTION_ONLY,
        'visibility_section_id' => $section->id,
    ]);

    $user   = User::factory()->create();
    $member = Member::factory()->create(['user_id' => $user->id]);
    $member->sections()->attach($section->id, ['joined_at' => now()->toDateString()]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/events')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('event list hides section_only events from non-members of that section', function () {
    $section = Section::factory()->create();
    Event::factory()->published()->create([
        'visibility'            => Event::VISIBILITY_SECTION_ONLY,
        'visibility_section_id' => $section->id,
    ]);

    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/events')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('event list response includes expected fields', function () {
    Event::factory()->published()->has(EventOccurrence::factory(), 'occurrences')->create();

    $this->getJson('/api/v1/events')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [[
                'id', 'title', 'slug', 'status', 'visibility',
                'admission_type', 'registration_mode', 'waitlist_mode',
                'is_registration_open', 'occurrences', 'price_tiers',
            ]],
        ]);
});

// ── GET /api/v1/events/{slug} ─────────────────────────────────────────────────

test('event show returns 404 for non-existent slug', function () {
    $this->getJson('/api/v1/events/does-not-exist')->assertNotFound();
});

test('event show returns 404 for draft event', function () {
    $event = Event::factory()->create();

    $this->getJson("/api/v1/events/{$event->slug}")->assertNotFound();
});

test('event show returns 403 for members_only event when unauthenticated', function () {
    $event = Event::factory()->published()->membersOnly()->create();

    $this->getJson("/api/v1/events/{$event->slug}")->assertForbidden();
});

test('event show returns event with price tiers and occurrences', function () {
    $event = Event::factory()->published()->create();
    EventPriceTier::factory()->withPrice(10000)->create(['event_id' => $event->id, 'label' => 'Standard']);
    EventOccurrence::factory()->create(['event_id' => $event->id]);

    $response = $this->getJson("/api/v1/events/{$event->slug}");

    $response->assertOk()
        ->assertJsonPath('data.slug', $event->slug)
        ->assertJsonPath('data.price_tiers.0.label', 'Standard')
        ->assertJsonPath('data.price_tiers.0.price_ore', 10000)
        ->assertJsonCount(1, 'data.occurrences');
});

test('event show price tier includes formatted price and spots_taken when capped', function () {
    $event = Event::factory()->published()->create();
    EventPriceTier::factory()->withPrice(10000)->withCapacity(5)->create(['event_id' => $event->id]);

    $response = $this->getJson("/api/v1/events/{$event->slug}");

    $response->assertOk()
        ->assertJsonPath('data.price_tiers.0.capacity', 5)
        ->assertJsonPath('data.price_tiers.0.spots_taken', 0)
        ->assertJsonPath('data.price_tiers.0.price_formatted', '100,00 kr');
});
