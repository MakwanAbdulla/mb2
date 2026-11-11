<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('meta endpoint is public', function () {
    $this->getJson('/api/v1/meta')->assertOk();
});

test('meta returns expected top-level keys', function () {
    $response = $this->getJson('/api/v1/meta')->assertOk();

    expect($response->json())->toHaveKeys([
        'event', 'event_registration', 'meeting', 'meeting_agenda_item',
        'poll', 'submission', 'mailing', 'section',
        'product', 'order', 'discount', 'invoice', 'account', 'wiki_space',
    ]);
});

test('meta wiki space read access contains expected values', function () {
    $response = $this->getJson('/api/v1/meta')->assertOk();

    expect($response->json('wiki_space.read_access'))->toBe(['public', 'members', 'officials', 'group']);
});

test('meta event statuses contains expected values', function () {
    $response = $this->getJson('/api/v1/meta')->assertOk();

    expect($response->json('event.statuses'))->toContain('draft', 'published', 'cancelled', 'completed');
});

test('meta poll types contains expected values', function () {
    $response = $this->getJson('/api/v1/meta')->assertOk();

    expect($response->json('poll.types'))->toContain('yes_no_abstain', 'yes_no', 'free');
});

test('meta section membership types contains expected values', function () {
    $response = $this->getJson('/api/v1/meta')->assertOk();

    expect($response->json('section.membership_types'))->toContain('open', 'application', 'closed');
});
