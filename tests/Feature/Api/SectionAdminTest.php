<?php

use App\Models\Organization\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('sections.manage');
});

function sectionsAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('sections.manage');
    return $user;
}

// ── POST /api/v1/sections ─────────────────────────────────────────────────────

test('can create section', function () {
    Sanctum::actingAs(sectionsAdminUser());

    $this->postJson('/api/v1/sections', [
        'name'            => 'Brädspel',
        'description'     => 'Sektionen för brädspel',
        'visibility'      => 'public',
        'membership_type' => 'open',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Brädspel');
});

test('create section auto-generates slug', function () {
    Sanctum::actingAs(sectionsAdminUser());

    $response = $this->postJson('/api/v1/sections', [
        'name'            => 'Rollspel & LajvLajv',
        'visibility'      => 'public',
        'membership_type' => 'open',
    ])->assertCreated();

    expect($response->json('data.slug'))->not->toBeEmpty();
});

test('can create child section', function () {
    $parent = Section::factory()->create(['name' => 'Förälder']);
    Sanctum::actingAs(sectionsAdminUser());

    $this->postJson('/api/v1/sections', [
        'name'            => 'Barn',
        'parent_id'       => $parent->id,
        'visibility'      => 'members',
        'membership_type' => 'open',
    ])
        ->assertCreated()
        ->assertJsonPath('data.parent_id', $parent->id);
});

test('create section requires sections.manage permission', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/sections', ['name' => 'Test'])->assertForbidden();
});

test('create section requires auth', function () {
    $this->postJson('/api/v1/sections', ['name' => 'Test'])->assertUnauthorized();
});

// ── PATCH /api/v1/sections/{section} ──────────────────────────────────────────

test('can update section', function () {
    $section = Section::factory()->create(['name' => 'Gammalt namn']);
    Sanctum::actingAs(sectionsAdminUser());

    $this->patchJson("/api/v1/sections/{$section->id}", [
        'name' => 'Nytt namn',
    ])->assertOk()->assertJsonPath('data.name', 'Nytt namn');
});

test('can move section to new parent', function () {
    $parent  = Section::factory()->create();
    $section = Section::factory()->create(['parent_id' => null]);
    Sanctum::actingAs(sectionsAdminUser());

    $this->patchJson("/api/v1/sections/{$section->id}", ['parent_id' => $parent->id])
        ->assertOk()
        ->assertJsonPath('data.parent_id', $parent->id);
});

// ── DELETE /api/v1/sections/{section} ────────────────────────────────────────

test('can delete section without children', function () {
    $section = Section::factory()->create();
    Sanctum::actingAs(sectionsAdminUser());

    $this->deleteJson("/api/v1/sections/{$section->id}")->assertOk();

    expect(Section::find($section->id))->toBeNull();
});

test('cannot delete section with children', function () {
    $parent = Section::factory()->create();
    Section::factory()->create(['parent_id' => $parent->id]);
    Sanctum::actingAs(sectionsAdminUser());

    $this->deleteJson("/api/v1/sections/{$parent->id}")
        ->assertStatus(422);
});
