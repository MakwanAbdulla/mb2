<?php

use App\Models\Events\Event;
use App\Models\Events\EventPriceTier;
use App\Models\Events\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function confirmedReg(): EventRegistration
{
    $event = Event::factory()->published()->create();
    $tier  = EventPriceTier::factory()->free()->create(['event_id' => $event->id]);
    return EventRegistration::factory()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'status'        => EventRegistration::STATUS_CONFIRMED,
    ]);
}

test('QR route returns SVG for confirmed registration', function () {
    $reg = confirmedReg();

    $response = $this->get(route('ticket.qr', $reg->check_in_token));

    $response->assertOk();
    $response->assertHeader('content-type', 'image/svg+xml');
    expect($response->getContent())->toContain('<svg');
});

test('QR route returns invalid SVG for waitlisted registration', function () {
    $event = Event::factory()->published()->create();
    $tier  = EventPriceTier::factory()->free()->create(['event_id' => $event->id]);
    $reg   = EventRegistration::factory()->waitlisted()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
    ]);

    $response = $this->get(route('ticket.qr', $reg->check_in_token));

    $response->assertOk();
    $response->assertHeader('content-type', 'image/svg+xml');
    expect($response->getContent())->toContain('Ogiltig biljett');
});

test('QR route returns invalid SVG for cancelled registration', function () {
    $event = Event::factory()->published()->create();
    $tier  = EventPriceTier::factory()->free()->create(['event_id' => $event->id]);
    $reg   = EventRegistration::factory()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'status'        => EventRegistration::STATUS_CANCELLED,
    ]);

    $response = $this->get(route('ticket.qr', $reg->check_in_token));

    $response->assertOk();
    expect($response->getContent())->toContain('Ogiltig biljett');
});

test('QR route returns invalid SVG for unknown token', function () {
    $response = $this->get(route('ticket.qr', 'unknowntokenthatdoesnotexist12'));

    $response->assertOk();
    expect($response->getContent())->toContain('Ogiltig biljett');
});
