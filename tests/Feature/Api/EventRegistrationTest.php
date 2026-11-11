<?php

use App\Models\Events\Event;
use App\Models\Events\EventOccurrence;
use App\Models\Events\EventPriceTier;
use App\Models\Events\EventRegistration;
use App\Models\Events\EventRegistrationOccurrence;
use App\Models\Membership\Member;
use App\Models\Shop\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function publishedEvent(array $attributes = []): Event
{
    return Event::factory()->published()->withRegistrationWindow()->create($attributes);
}

function freeTier(Event $event): EventPriceTier
{
    return EventPriceTier::factory()->free()->create(['event_id' => $event->id]);
}

function paidTier(Event $event, int $ore = 10000): EventPriceTier
{
    return EventPriceTier::factory()->withPrice($ore)->create(['event_id' => $event->id]);
}

// ── POST /api/v1/events/{slug}/registrations ──────────────────────────────────

test('unauthenticated cannot register for event', function () {
    $event = publishedEvent();
    freeTier($event);

    $this->postJson("/api/v1/events/{$event->slug}/registrations", [
        'price_tier_id' => $event->priceTiers()->first()->id,
    ])->assertUnauthorized();
});

test('registration fails when registration is not open', function () {
    $event = Event::factory()->published()->create([
        'registration_opens_at'  => now()->addDays(7),
        'registration_closes_at' => now()->addDays(14),
    ]);
    $tier = freeTier($event);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/events/{$event->slug}/registrations", [
        'price_tier_id' => $tier->id,
    ])->assertStatus(422)
      ->assertJsonPath('message', 'Registration is not open.');
});

test('registration fails with invalid tier id', function () {
    $event = publishedEvent();

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/events/{$event->slug}/registrations", [
        'price_tier_id' => 9999,
    ])->assertStatus(422);
});

test('on_payment event: free tier creates confirmed registration', function () {
    $event = publishedEvent(['admission_type' => Event::ADMISSION_ON_PAYMENT]);
    $tier  = freeTier($event);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/events/{$event->slug}/registrations", [
        'price_tier_id' => $tier->id,
    ])->assertStatus(201)
      ->assertJsonPath('registration.status', EventRegistration::STATUS_CONFIRMED);
});

test('on_payment event: paid tier creates pending_payment registration', function () {
    $event = publishedEvent(['admission_type' => Event::ADMISSION_ON_PAYMENT]);
    $tier  = paidTier($event);

    Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson("/api/v1/events/{$event->slug}/registrations", [
        'price_tier_id' => $tier->id,
    ]);

    $response->assertStatus(201)
             ->assertJsonPath('registration.status', EventRegistration::STATUS_PENDING_PAYMENT)
             ->assertJsonPath('total_ore', $tier->price_ore);

    expect($response->json('payment_url'))->not->toBeNull();
});

test('automatic event: paid tier creates confirmed registration immediately', function () {
    $event = publishedEvent(['admission_type' => Event::ADMISSION_AUTOMATIC]);
    $tier  = paidTier($event);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/events/{$event->slug}/registrations", [
        'price_tier_id' => $tier->id,
    ])->assertStatus(201)
      ->assertJsonPath('registration.status', EventRegistration::STATUS_CONFIRMED);
});

test('manual event: registration creates pending_approval status', function () {
    $event = publishedEvent(['admission_type' => Event::ADMISSION_MANUAL]);
    $tier  = freeTier($event);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/events/{$event->slug}/registrations", [
        'price_tier_id' => $tier->id,
    ])->assertStatus(201)
      ->assertJsonPath('registration.status', EventRegistration::STATUS_PENDING_APPROVAL);
});

test('registration with notes saves the notes', function () {
    $event = publishedEvent();
    $tier  = freeTier($event);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/events/{$event->slug}/registrations", [
        'price_tier_id' => $tier->id,
        'notes'         => 'Allergic to peanuts',
    ])->assertStatus(201)
      ->assertJsonPath('registration.notes', 'Allergic to peanuts');
});

// ── Capacity and waitlist ─────────────────────────────────────────────────────

test('registration is blocked when tier is full and no waitlist', function () {
    $event = publishedEvent([
        'admission_type' => Event::ADMISSION_ON_PAYMENT,
        'waitlist_mode'  => Event::WAITLIST_NONE,
    ]);
    $tier = EventPriceTier::factory()->withPrice(5000)->withCapacity(1)->create(['event_id' => $event->id]);

    // Fill the spot
    EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'price_tier_id' => $tier->id]);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/events/{$event->slug}/registrations", [
        'price_tier_id' => $tier->id,
    ])->assertStatus(422)
      ->assertJsonPath('message', 'This ticket type is sold out.');
});

test('registration goes to waitlist when tier is full and waitlist is enabled', function () {
    $event = publishedEvent([
        'admission_type' => Event::ADMISSION_ON_PAYMENT,
        'waitlist_mode'  => Event::WAITLIST_MANUAL,
    ]);
    $tier = EventPriceTier::factory()->withPrice(5000)->withCapacity(1)->create(['event_id' => $event->id]);

    EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'price_tier_id' => $tier->id]);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/events/{$event->slug}/registrations", [
        'price_tier_id' => $tier->id,
    ])->assertStatus(201)
      ->assertJsonPath('registration.status', EventRegistration::STATUS_WAITLISTED);
});

test('pending_payment registrations count toward capacity', function () {
    $event = publishedEvent([
        'admission_type' => Event::ADMISSION_ON_PAYMENT,
        'waitlist_mode'  => Event::WAITLIST_NONE,
    ]);
    $tier = EventPriceTier::factory()->withPrice(5000)->withCapacity(1)->create(['event_id' => $event->id]);

    EventRegistration::factory()->pendingPayment()->create(['event_id' => $event->id, 'price_tier_id' => $tier->id]);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/events/{$event->slug}/registrations", [
        'price_tier_id' => $tier->id,
    ])->assertStatus(422)
      ->assertJsonPath('message', 'This ticket type is sold out.');
});

// ── Access rules ──────────────────────────────────────────────────────────────

test('registration blocked for members_only event when user has no membership', function () {
    $event = publishedEvent([
        'visibility'          => Event::VISIBILITY_MEMBERS_ONLY,
        'requires_membership' => true,
    ]);
    $tier = freeTier($event);

    $user = User::factory()->create(); // no member record
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/events/{$event->slug}/registrations", [
        'price_tier_id' => $tier->id,
    ])->assertForbidden();
});

// ── GET /api/v1/events/{slug}/registrations ───────────────────────────────────

test('unauthenticated cannot list registrations', function () {
    $event = publishedEvent();

    $this->getJson("/api/v1/events/{$event->slug}/registrations")->assertUnauthorized();
});

test('user without permission cannot list registrations', function () {
    $event = publishedEvent();
    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/v1/events/{$event->slug}/registrations")->assertForbidden();
});

test('user with events.view permission can list registrations', function () {
    $this->createPermission('events.view');
    $event = publishedEvent();
    EventRegistration::factory()->confirmed()->count(3)->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $user->givePermissionTo('events.view');
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/events/{$event->slug}/registrations")
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('organizer can list registrations for their own event', function () {
    $event  = publishedEvent();
    $user   = User::factory()->create();
    $member = Member::factory()->create(['user_id' => $user->id]);

    $event->organizers()->attach($member->id, ['role' => Event::ORGANIZER_ADMIN]);
    EventRegistration::factory()->confirmed()->count(2)->create(['event_id' => $event->id]);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/events/{$event->slug}/registrations")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('registration list excludes cancelled registrations', function () {
    $this->createPermission('events.view');
    $event = publishedEvent();
    EventRegistration::factory()->confirmed()->count(3)->create(['event_id' => $event->id]);
    EventRegistration::factory()->cancelled()->count(2)->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $user->givePermissionTo('events.view');
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/events/{$event->slug}/registrations")
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

// ── POST /api/v1/events/{slug}/check-in ──────────────────────────────────────

test('unauthenticated cannot check in', function () {
    $event = publishedEvent();
    $this->postJson("/api/v1/events/{$event->slug}/check-in", [
        'check_in_token' => 'token',
    ])->assertUnauthorized();
});

test('user without check-in permission cannot check in', function () {
    $event = publishedEvent();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/events/{$event->slug}/check-in", [
        'check_in_token' => 'token',
    ])->assertForbidden();
});

test('check-in with valid token records attendance', function () {
    $this->createPermission('events.check-in');

    $event      = publishedEvent();
    $occurrence = EventOccurrence::factory()->create(['event_id' => $event->id]);
    $reg        = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id]);

    $admin = User::factory()->create();
    $admin->givePermissionTo('events.check-in');
    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/events/{$event->slug}/check-in", [
        'check_in_token' => $reg->check_in_token,
        'occurrence_id'  => $occurrence->id,
    ])->assertOk()
      ->assertJsonPath('message', 'Checked in successfully.')
      ->assertJsonPath('registration_id', $reg->id);

    expect(EventRegistrationOccurrence::where('registration_id', $reg->id)
        ->where('occurrence_id', $occurrence->id)
        ->whereNotNull('attended_at')
        ->exists()
    )->toBeTrue();
});

test('check-in returns 404 for unknown token', function () {
    $this->createPermission('events.check-in');
    $event = publishedEvent();

    $user = User::factory()->create();
    $user->givePermissionTo('events.check-in');
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/events/{$event->slug}/check-in", [
        'check_in_token' => 'invalid-token-xyz',
    ])->assertNotFound();
});

test('check-in returns 422 for unconfirmed registration', function () {
    $this->createPermission('events.check-in');

    $event      = publishedEvent();
    $occurrence = EventOccurrence::factory()->create(['event_id' => $event->id]);
    $reg        = EventRegistration::factory()->pendingPayment()->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $user->givePermissionTo('events.check-in');
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/events/{$event->slug}/check-in", [
        'check_in_token' => $reg->check_in_token,
        'occurrence_id'  => $occurrence->id,
    ])->assertStatus(422)
      ->assertJsonPath('status', EventRegistration::STATUS_PENDING_PAYMENT);
});

test('duplicate check-in returns 409', function () {
    $this->createPermission('events.check-in');

    $event      = publishedEvent();
    $occurrence = EventOccurrence::factory()->create(['event_id' => $event->id]);
    $reg        = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id]);

    EventRegistrationOccurrence::create([
        'registration_id' => $reg->id,
        'occurrence_id'   => $occurrence->id,
        'attended_at'     => now(),
    ]);

    $user = User::factory()->create();
    $user->givePermissionTo('events.check-in');
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/events/{$event->slug}/check-in", [
        'check_in_token' => $reg->check_in_token,
        'occurrence_id'  => $occurrence->id,
    ])->assertStatus(409)
      ->assertJsonPath('message', 'Already checked in.');
});

test('organizer can check in participants to their own event', function () {
    $event      = publishedEvent();
    $occurrence = EventOccurrence::factory()->create(['event_id' => $event->id]);
    $reg        = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id]);

    $user   = User::factory()->create();
    $member = Member::factory()->create(['user_id' => $user->id]);
    $event->organizers()->attach($member->id, ['role' => Event::ORGANIZER_HELPER]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/events/{$event->slug}/check-in", [
        'check_in_token' => $reg->check_in_token,
        'occurrence_id'  => $occurrence->id,
    ])->assertOk();
});
