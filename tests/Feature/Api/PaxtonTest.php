<?php

use App\Models\Membership\Member;
use App\Models\Membership\MemberTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('tags.manage');
});

function agentUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('tags.manage');
    return $user;
}

function makeTag(string $status, string $paxtonStatus = 'pending'): MemberTag
{
    $member = Member::factory()->create();
    return MemberTag::create([
        'member_id'          => $member->id,
        'tag_uid'            => strtoupper(bin2hex(random_bytes(4))),
        'status'             => $status,
        'price_ore'          => 7500,
        'paxton_sync_status' => $paxtonStatus,
    ]);
}

// ── sync-queue ────────────────────────────────────────────────────────────────

test('unauthenticated cannot access sync queue', function () {
    $this->getJson('/api/v1/paxton/sync-queue')->assertStatus(401);
});

test('authenticated without permission cannot access sync queue', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/paxton/sync-queue')->assertStatus(403);
});

test('sync queue returns pending tags', function () {
    $active      = makeTag(MemberTag::STATUS_ACTIVE, MemberTag::PAXTON_PENDING);
    $lost        = makeTag(MemberTag::STATUS_LOST, MemberTag::PAXTON_PENDING);
    $synced      = makeTag(MemberTag::STATUS_ACTIVE, MemberTag::PAXTON_SYNCED);    // should NOT appear

    Sanctum::actingAs(agentUser());

    $response = $this->getJson('/api/v1/paxton/sync-queue');

    $response->assertOk();
    $data = $response->json();

    expect($data)->toHaveCount(2);

    $tagIds = collect($data)->pluck('tag_id');
    expect($tagIds)->toContain($active->id);
    expect($tagIds)->toContain($lost->id);
    expect($tagIds)->not->toContain($synced->id);
});

test('active tag action is add', function () {
    $tag = makeTag(MemberTag::STATUS_ACTIVE, MemberTag::PAXTON_PENDING);

    Sanctum::actingAs(agentUser());

    $response = $this->getJson('/api/v1/paxton/sync-queue');
    $item     = collect($response->json())->firstWhere('tag_id', $tag->id);

    expect($item['action'])->toBe('add');
});

test('lost tag action is deactivate_card', function () {
    $tag = makeTag(MemberTag::STATUS_LOST, MemberTag::PAXTON_PENDING);

    Sanctum::actingAs(agentUser());

    $item = collect($this->getJson('/api/v1/paxton/sync-queue')->json())->firstWhere('tag_id', $tag->id);

    expect($item['action'])->toBe('deactivate_card');
});

test('deactivated tag action is deactivate_all', function () {
    $tag = makeTag(MemberTag::STATUS_DEACTIVATED, MemberTag::PAXTON_PENDING);

    Sanctum::actingAs(agentUser());

    $item = collect($this->getJson('/api/v1/paxton/sync-queue')->json())->firstWhere('tag_id', $tag->id);

    expect($item['action'])->toBe('deactivate_all');
});

// ── sync-confirm ──────────────────────────────────────────────────────────────

test('unauthenticated cannot confirm sync', function () {
    $this->postJson('/api/v1/paxton/sync-confirm', ['tag_id' => 1, 'status' => 'synced'])
         ->assertStatus(401);
});

test('sync-confirm marks tag as synced', function () {
    $tag = makeTag(MemberTag::STATUS_ACTIVE, MemberTag::PAXTON_PENDING);

    Sanctum::actingAs(agentUser());

    $this->postJson('/api/v1/paxton/sync-confirm', [
        'tag_id' => $tag->id,
        'status' => 'synced',
    ])->assertOk()->assertJson(['ok' => true]);

    $tag->refresh();
    expect($tag->paxton_sync_status)->toBe(MemberTag::PAXTON_SYNCED);
    expect($tag->paxton_sync_error)->toBeNull();
    expect($tag->paxton_synced_at)->not->toBeNull();
});

test('sync-confirm marks tag as failed with error', function () {
    $tag = makeTag(MemberTag::STATUS_ACTIVE, MemberTag::PAXTON_PENDING);

    Sanctum::actingAs(agentUser());

    $this->postJson('/api/v1/paxton/sync-confirm', [
        'tag_id' => $tag->id,
        'status' => 'failed',
        'error'  => 'Cardholder not found',
    ])->assertOk();

    $tag->refresh();
    expect($tag->paxton_sync_status)->toBe(MemberTag::PAXTON_FAILED);
    expect($tag->paxton_sync_error)->toBe('Cardholder not found');
});

test('sync-confirm validates required fields', function () {
    Sanctum::actingAs(agentUser());

    $this->postJson('/api/v1/paxton/sync-confirm', [])
         ->assertStatus(422)
         ->assertJsonValidationErrors(['tag_id', 'status']);
});

test('sync-confirm rejects invalid status', function () {
    $tag = makeTag(MemberTag::STATUS_ACTIVE, MemberTag::PAXTON_PENDING);

    Sanctum::actingAs(agentUser());

    $this->postJson('/api/v1/paxton/sync-confirm', [
        'tag_id' => $tag->id,
        'status' => 'invalid',
    ])->assertStatus(422)->assertJsonValidationErrors(['status']);
});
