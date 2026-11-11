<?php

use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('tags.view');
    $this->createPermission('tags.manage');
});

function tagsAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('tags.manage');
    return $user;
}

// ── GET /api/v1/tags ──────────────────────────────────────────────────────────

test('tags list requires auth', function () {
    $this->getJson('/api/v1/tags')->assertUnauthorized();
});

test('tags list requires tags permission', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/tags')->assertForbidden();
});

test('tags list returns all tags sorted by name', function () {
    Tag::create(['name' => 'Övrigt',   'slug' => 'ovrigt']);
    Tag::create(['name' => 'Brädspel', 'slug' => 'bradspel']);
    Sanctum::actingAs(tagsAdminUser());

    $response = $this->getJson('/api/v1/tags')->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('data.0.name'))->toBe('Brädspel');
});

// ── POST /api/v1/tags ─────────────────────────────────────────────────────────

test('admin can create tag', function () {
    Sanctum::actingAs(tagsAdminUser());

    $this->postJson('/api/v1/tags', ['name' => 'Rollspel'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Rollspel');
});

test('create tag auto-generates slug', function () {
    Sanctum::actingAs(tagsAdminUser());

    $response = $this->postJson('/api/v1/tags', ['name' => 'Miniatyrspel'])->assertCreated();
    expect($response->json('data.slug'))->toBe('miniatyrspel');
});

test('cannot create duplicate tag', function () {
    Tag::create(['name' => 'Brädspel', 'slug' => 'bradspel']);
    Sanctum::actingAs(tagsAdminUser());

    $this->postJson('/api/v1/tags', ['name' => 'Brädspel'])->assertUnprocessable();
});

test('create tag requires tags.manage permission', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('tags.view');
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/tags', ['name' => 'Hack'])->assertForbidden();
});

// ── DELETE /api/v1/tags/{tag} ─────────────────────────────────────────────────

test('admin can delete tag', function () {
    $tag = Tag::create(['name' => 'Ta bort', 'slug' => 'ta-bort']);
    Sanctum::actingAs(tagsAdminUser());

    $this->deleteJson("/api/v1/tags/{$tag->id}")->assertOk();
    expect(Tag::find($tag->id))->toBeNull();
});

test('delete tag requires tags.manage permission', function () {
    $tag  = Tag::create(['name' => 'Ta bort', 'slug' => 'ta-bort']);
    $user = User::factory()->create();
    $user->givePermissionTo('tags.view');
    Sanctum::actingAs($user);

    $this->deleteJson("/api/v1/tags/{$tag->id}")->assertForbidden();
});
