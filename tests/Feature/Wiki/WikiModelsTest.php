<?php

use App\Enums\WikiReadAccess;
use App\Models\AuditLog;
use App\Models\Organization\Section;
use App\Models\User;
use App\Models\Wiki\WikiPage;
use App\Models\Wiki\WikiSpace;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ── Spaces ────────────────────────────────────────────────────────────────────

it('creates an association-wide space by default', function () {
    $space = WikiSpace::factory()->create();

    expect($space->isShared())->toBeTrue()
        ->and($space->group)->toBeNull()
        ->and($space->read_access)->toBe(WikiReadAccess::Members);
});

it('belongs to a group', function () {
    $group = Section::factory()->create();

    $space = WikiSpace::factory()->forGroup($group)->create();

    expect($space->group->is($group))->toBeTrue()
        ->and($space->isShared())->toBeFalse()
        ->and(WikiSpace::factory()->forGroup()->create()->group)->toBeInstanceOf(Section::class);
});

it('casts read_access to the enum and defaults it to members on a new model', function () {
    $space = WikiSpace::factory()->readableBy(WikiReadAccess::Public)->create();

    expect($space->fresh()->read_access)->toBe(WikiReadAccess::Public)
        ->and((new WikiSpace)->read_access)->toBe(WikiReadAccess::Members);
});

it('allows only one space per group, but any number of shared spaces', function () {
    $group = Section::factory()->create();

    expect(fn () => WikiSpace::create(['group_id' => $group->id, 'name' => 'Till', 'slug' => 'till']))
        ->toThrow(QueryException::class);

    WikiSpace::factory()->count(2)->shared()->create();
    expect(WikiSpace::whereNull('group_id')->count())->toBe(2);
});

it('gives every new group a space named after it, readable by members', function () {
    $group = Section::factory()->create(['name' => 'Brädspel']);

    expect($group->wikiSpace)->not->toBeNull()
        ->and($group->wikiSpace->name)->toBe('Brädspel')
        ->and($group->wikiSpace->slug)->toBe('bradspel')
        ->and($group->wikiSpace->read_access)->toBe(WikiReadAccess::Members);
});

it('picks a free slug when the name is taken', function () {
    WikiSpace::factory()->shared()->create(['slug' => 'styrelsen']);

    expect(Section::factory()->create(['name' => 'Styrelsen'])->wikiSpace->slug)->toBe('styrelsen-2');
});

it('removes an empty space together with its group', function () {
    $group = Section::factory()->create();
    $spaceId = $group->wikiSpace->id;

    $group->delete();

    expect(WikiSpace::find($spaceId))->toBeNull();
});

it('keeps the empty space when deleting its group fails', function () {
    $group = Section::factory()->create();
    $spaceId = $group->wikiSpace->id;
    Section::deleting(fn () => throw new RuntimeException('blocked by another foreign key'));

    expect(fn () => $group->delete())->toThrow(RuntimeException::class)
        ->and(WikiSpace::find($spaceId))->not->toBeNull()
        ->and($group->fresh())->not->toBeNull();
});

it('refuses to delete a group whose wiki has pages, even deleted ones', function () {
    $group = Section::factory()->create();
    WikiPage::factory()->create(['wiki_space_id' => $group->wikiSpace->id])->delete();

    expect($group->hasWikiPages())->toBeTrue()
        ->and(fn () => $group->delete())->toThrow(QueryException::class)
        ->and($group->fresh())->not->toBeNull();
});

it('requires space slugs to be globally unique', function () {
    WikiSpace::factory()->create(['slug' => 'styrelsen']);

    expect(fn () => WikiSpace::factory()->create(['slug' => 'styrelsen']))->toThrow(QueryException::class);
});

it('backfills spaces for groups created before the wiki, once', function () {
    $old = Section::factory()->create();
    $old->wikiSpace->delete(); // as before the wiki existed

    (require database_path('migrations/2026_11_03_120000_create_wiki_spaces_for_existing_groups.php'))->up();

    expect($old->fresh()->wikiSpace)->not->toBeNull()
        ->and(WikiSpace::where('group_id', $old->id)->count())->toBe(1);
});

it('labels the wiki area and actions in the audit log', function () {
    expect(AuditLog::AREA_LABELS)->toHaveKey('wiki')
        ->and(AuditLog::ACTION_LABELS)->toHaveKeys(['wiki_space_created', 'wiki_space_updated', 'wiki_space_archived', 'wiki_space_restored', 'wiki_page_created', 'wiki_page_updated', 'wiki_page_deleted']);
});

// ── Pages ─────────────────────────────────────────────────────────────────────

it('builds a page tree within a space', function () {
    $root = WikiPage::factory()->create(['title' => 'Lokalen']);
    $second = WikiPage::factory()->childOf($root)->create(['title' => 'Stänga lokalen', 'sort_order' => 2]);
    $first = WikiPage::factory()->childOf($root)->create(['title' => 'Larmet', 'sort_order' => 1]);

    expect($root->space->rootPages->pluck('id')->all())->toBe([$root->id])
        ->and($root->children->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($first->parent->is($root))->toBeTrue()
        ->and($first->wiki_space_id)->toBe($root->wiki_space_id);
});

it('tracks who created and last updated a page', function () {
    $author = User::factory()->create();
    $editor = User::factory()->create();

    $page = WikiPage::factory()->create(['created_by' => $author->id, 'updated_by' => $editor->id]);

    expect($page->creator->is($author))->toBeTrue()
        ->and($page->updater->is($editor))->toBeTrue();
});

it('requires page slugs to be unique per space, not globally', function () {
    $page = WikiPage::factory()->create(['slug' => 'arsmotet']);

    WikiPage::factory()->create(['slug' => 'arsmotet']); // another space

    expect(fn () => WikiPage::factory()->create(['wiki_space_id' => $page->wiki_space_id, 'slug' => 'arsmotet']))
        ->toThrow(QueryException::class);
});

it('keeps the slug reserved while a page is soft-deleted, so it can be restored', function () {
    $page = WikiPage::factory()->create(['slug' => 'kassorens-ar']);
    $page->delete();

    expect(WikiPage::find($page->id))->toBeNull()
        ->and(WikiPage::withTrashed()->find($page->id))->not->toBeNull()
        ->and(fn () => WikiPage::factory()->create(['wiki_space_id' => $page->wiki_space_id, 'slug' => 'kassorens-ar']))
        ->toThrow(QueryException::class);

    $page->restore();
    expect(WikiPage::find($page->id))->not->toBeNull();
});

it('deletes the pages when a space is deleted', function () {
    $page = WikiPage::factory()->create();

    $page->space->delete();

    expect(WikiPage::withTrashed()->find($page->id))->toBeNull();
});

it('keeps pages when their author is deleted', function () {
    $author = User::factory()->create();
    $page = WikiPage::factory()->create(['created_by' => $author->id, 'updated_by' => $author->id]);

    $author->delete();

    expect($page->fresh())->not->toBeNull()
        ->and($page->fresh()->created_by)->toBeNull();
});

// ── Permissions ───────────────────────────────────────────────────────────────

it('seeds the wiki permissions and grants view and edit to the board and secretary', function () {
    $this->seed(PermissionSeeder::class);

    expect(Role::findByName('admin')->hasPermissionTo('wiki.manage'))->toBeTrue()
        ->and(Role::findByName('styrelse')->hasPermissionTo('wiki.edit'))->toBeTrue()
        ->and(Role::findByName('sekreterare')->hasPermissionTo('wiki.view'))->toBeTrue()
        ->and(Role::findByName('styrelse')->hasPermissionTo('wiki.manage'))->toBeFalse();
});

it('treats wiki.manage as staff access', function () {
    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create();

    expect($user->isStaff())->toBeFalse();

    $user->givePermissionTo('wiki.manage');

    expect($user->fresh()->isStaff())->toBeTrue();
});
