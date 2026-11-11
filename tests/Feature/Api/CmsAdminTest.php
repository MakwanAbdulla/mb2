<?php

use App\Models\Content\NavigationItem;
use App\Models\Content\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('pages.view');
    $this->createPermission('pages.manage');
});

function cmsAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('pages.manage');
    return $user;
}

// ── GET /api/v1/admin/pages ───────────────────────────────────────────────────

test('admin pages list requires auth', function () {
    $this->getJson('/api/v1/admin/pages')->assertUnauthorized();
});

test('admin pages list requires pages.manage permission', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/admin/pages')->assertForbidden();
});

test('admin pages list returns draft and published pages', function () {
    Page::create(['title' => 'Publicerad', 'slug' => 'pub', 'content' => '', 'is_published' => true,  'published_at' => now()]);
    Page::create(['title' => 'Utkast',     'slug' => 'drft', 'content' => '', 'is_published' => false]);
    Sanctum::actingAs(cmsAdminUser());

    $this->getJson('/api/v1/admin/pages')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── POST /api/v1/admin/pages ──────────────────────────────────────────────────

test('admin can create page draft', function () {
    Sanctum::actingAs(cmsAdminUser());

    $this->postJson('/api/v1/admin/pages', [
        'title'   => 'Ny sida',
        'content' => '<p>Innehåll</p>',
    ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Ny sida')
        ->assertJsonPath('data.is_published', false);
});

test('create page auto-generates slug', function () {
    Sanctum::actingAs(cmsAdminUser());

    $response = $this->postJson('/api/v1/admin/pages', [
        'title'   => 'Om Föreningen',
        'content' => '<p>Innehåll</p>',
    ])->assertCreated();

    expect($response->json('data.slug'))->not->toBeEmpty();
});

test('create page requires pages.manage permission', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/admin/pages', ['title' => 'Test'])->assertForbidden();
});

// ── PATCH /api/v1/admin/pages/{page} ─────────────────────────────────────────

test('admin can update page', function () {
    $page = Page::create(['title' => 'Gammalt', 'slug' => 'gammalt', 'content' => '', 'is_published' => false]);
    Sanctum::actingAs(cmsAdminUser());

    $this->patchJson("/api/v1/admin/pages/{$page->id}", [
        'title'   => 'Nytt',
        'content' => '<p>Nytt innehåll</p>',
    ])->assertOk()->assertJsonPath('data.title', 'Nytt');
});

// ── POST /api/v1/admin/pages/{page}/publish ───────────────────────────────────

test('admin can publish a page', function () {
    $page = Page::create(['title' => 'Test', 'slug' => 'test', 'content' => '', 'is_published' => false]);
    Sanctum::actingAs(cmsAdminUser());

    $this->postJson("/api/v1/admin/pages/{$page->id}/publish")
        ->assertOk()
        ->assertJsonPath('data.is_published', true);
});

// ── POST /api/v1/admin/pages/{page}/unpublish ─────────────────────────────────

test('admin can unpublish a non-protected page', function () {
    $page = Page::create([
        'title' => 'Test', 'slug' => 'test-unpublish', 'content' => '', 'is_published' => true, 'published_at' => now(),
    ]);
    Sanctum::actingAs(cmsAdminUser());

    $this->postJson("/api/v1/admin/pages/{$page->id}/unpublish")
        ->assertOk()
        ->assertJsonPath('data.is_published', false);
});

test('cannot unpublish protected slug', function () {
    $page = Page::create([
        'title' => 'Hem', 'slug' => 'hem', 'content' => '', 'is_published' => true, 'published_at' => now(),
    ]);
    Sanctum::actingAs(cmsAdminUser());

    $this->postJson("/api/v1/admin/pages/{$page->id}/unpublish")
        ->assertStatus(422);
});

// ── DELETE /api/v1/admin/pages/{page} ────────────────────────────────────────

test('admin can delete unprotected page', function () {
    $page = Page::create(['title' => 'Slut', 'slug' => 'slut', 'content' => '', 'is_published' => false]);
    Sanctum::actingAs(cmsAdminUser());

    $this->deleteJson("/api/v1/admin/pages/{$page->id}")->assertOk();
    expect(Page::find($page->id))->toBeNull();
});

test('cannot delete protected slug', function () {
    $page = Page::create(['title' => 'Hem', 'slug' => 'hem', 'content' => '', 'is_published' => true, 'published_at' => now()]);
    Sanctum::actingAs(cmsAdminUser());

    $this->deleteJson("/api/v1/admin/pages/{$page->id}")->assertStatus(422);
});

// ── POST /api/v1/admin/navigation ────────────────────────────────────────────

test('admin can create navigation item', function () {
    Sanctum::actingAs(cmsAdminUser());

    $this->postJson('/api/v1/admin/navigation', [
        'label'     => 'Kontakt',
        'location'  => 'topnav',
        'url'       => '/kontakt',
        'order'     => 10,
        'is_active' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('data.label', 'Kontakt');
});

test('create navigation item requires pages.manage permission', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/admin/navigation', [
        'label' => 'Hack', 'location' => 'topnav', 'order' => 1, 'is_active' => true,
    ])->assertForbidden();
});

// ── PATCH /api/v1/admin/navigation/{item} ────────────────────────────────────

test('admin can update navigation item', function () {
    $item = NavigationItem::create(['label' => 'Gammalt', 'location' => 'topnav', 'order' => 1, 'is_active' => true]);
    Sanctum::actingAs(cmsAdminUser());

    $this->patchJson("/api/v1/admin/navigation/{$item->id}", [
        'label' => 'Nytt',
    ])->assertOk()->assertJsonPath('data.label', 'Nytt');
});

// ── DELETE /api/v1/admin/navigation/{item} ────────────────────────────────────

test('admin can delete navigation item', function () {
    $item = NavigationItem::create(['label' => 'Ta bort', 'location' => 'footer', 'order' => 1, 'is_active' => true]);
    Sanctum::actingAs(cmsAdminUser());

    $this->deleteJson("/api/v1/admin/navigation/{$item->id}")->assertOk();
    expect(NavigationItem::find($item->id))->toBeNull();
});
