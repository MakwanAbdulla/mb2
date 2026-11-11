<?php

use App\Models\Events\Event;
use App\Models\Events\EventOccurrence;
use App\Models\Events\EventPriceTier;
use App\Models\Events\EventRegistration;
use App\Models\User;
use App\Services\ScannerService;
use App\Services\ScanResult;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function confirmedRegForScan(): EventRegistration
{
    $event = Event::factory()->published()->create();
    $tier  = EventPriceTier::factory()->free()->create(['event_id' => $event->id]);
    $user  = User::factory()->create();
    return EventRegistration::factory()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'user_id'       => $user->id,
        'status'        => EventRegistration::STATUS_CONFIRMED,
    ]);
}

test('resolves confirmed registration by REG- token', function () {
    $reg    = confirmedRegForScan();
    $code   = 'REG-' . strtoupper($reg->check_in_token);
    $result = app(ScannerService::class)->resolve($code);

    expect($result->type)->toBe(ScanResult::TYPE_EVENT_REGISTRATION);
    expect($result->status)->toBe(ScanResult::STATUS_AVAILABLE);
    expect($result->model->id)->toBe($reg->id);
});

test('returns invalid result for cancelled registration', function () {
    $event = Event::factory()->published()->create();
    $tier  = EventPriceTier::factory()->free()->create(['event_id' => $event->id]);
    $reg   = EventRegistration::factory()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'status'        => EventRegistration::STATUS_CANCELLED,
    ]);

    $result = app(ScannerService::class)->resolve('REG-' . strtoupper($reg->check_in_token));

    expect($result->type)->toBe(ScanResult::TYPE_EVENT_REGISTRATION);
    expect($result->status)->toBe(ScanResult::STATUS_INVALID);
});

test('returns not_found for unknown REG- token', function () {
    $result = app(ScannerService::class)->resolve('REG-UNKNOWNTOKEN123456789012345678');

    expect($result->type)->toBe(ScanResult::TYPE_NOT_FOUND);
});

test('does not resolve registration without REG- prefix', function () {
    $reg    = confirmedRegForScan();
    // Raw token without prefix should NOT be treated as a registration
    $result = app(ScannerService::class)->resolve(strtoupper($reg->check_in_token));

    // Falls through to discount resolver and returns not_found
    expect($result->type)->not->toBe(ScanResult::TYPE_EVENT_REGISTRATION);
});
