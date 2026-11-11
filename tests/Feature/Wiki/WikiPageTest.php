<?php

use App\Enums\WikiReadAccess;
use App\Models\Membership\Member;
use App\Models\Organization\Section;
use App\Models\Organization\SectionMembership;
use App\Models\User;
use App\Models\Wiki\WikiPage;
use App\Models\Wiki\WikiSpace;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    // A group whose active members write in its space; readable by all members.
    $this->group = Section::factory()->create(['visibility' => Section::VISIBILITY_PUBLIC, 'membership_requires_active' => false]);
    $this->space = tap($this->group->wikiSpace)->update(['read_access' => WikiReadAccess::Members]);
    $this->writer = pageUser(inGroup: $this->group);
    $this->reader = pageUser();
});

/** An active member, optionally in a group. Named apart from other wiki test helpers. */
function pageUser(?Section $inGroup = null, bool $apiAccess = false): User
{
    $user = User::factory()->create();
    $member = Member::factory()->active()->create(['user_id' => $user->id]);

    if ($inGroup) {
        SectionMembership::create(['section_id' => $inGroup->id, 'member_id' => $member->id, 'status' => SectionMembership::STATUS_ACTIVE, 'joined_at' => today()]);
    }
    if ($apiAccess) {
        $user->givePermissionTo('api.access');
    }

    return $user->fresh();
}

// ── Reading ───────────────────────────────────────────────────────────────────

it('shows a page with its breadcrumb, content and last editor', function () {
    $top = WikiPage::factory()->create(['wiki_space_id' => $this->space->id, 'title' => 'Lokalen']);
    $page = WikiPage::factory()->childOf($top)->create(['title' => 'Stänga lokalen', 'content' => '<div>Släck lampan</div>', 'updated_by' => $this->writer->id]);

    $this->actingAs($this->reader)->get(route('wiki.page', [$this->space, $page]))
        ->assertOk()
        ->assertSeeInOrder(['Wiki', $this->space->name, 'Lokalen', 'Stänga lokalen'])
        ->assertSee('Släck lampan', false)
        ->assertSee('av '.$this->writer->name);
});

it('answers 404 for a page the visitor may not read, a trashed page, or one from another space', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    $other = WikiSpace::factory()->readableBy(WikiReadAccess::Public)->create();

    $this->get(route('wiki.page', [$this->space, $page]))->assertNotFound();
    $this->actingAs($this->reader)->get("/wiki/{$other->slug}/{$page->slug}")->assertNotFound();

    $page->delete();
    $this->get(route('wiki.page', [$this->space, $page->slug]))->assertNotFound();
});

it('draws the page tree on several levels', function () {
    $a = WikiPage::factory()->create(['wiki_space_id' => $this->space->id, 'title' => 'Nivå ett']);
    $b = WikiPage::factory()->childOf($a)->create(['title' => 'Nivå två']);
    WikiPage::factory()->childOf($b)->create(['title' => 'Nivå tre']);
    WikiPage::factory()->childOf(WikiPage::factory()->childOf($b)->create(['title' => 'Också nivå tre']))->create(['title' => 'Nivå fyra']);

    $this->actingAs($this->reader)->get(route('wiki.space', $this->space))
        ->assertSeeInOrder(['Nivå ett', 'Nivå två', 'Nivå tre', 'Också nivå tre', 'Nivå fyra']);
});

it('builds the breadcrumb without a query per level', function () {
    $top = WikiPage::factory()->create(['wiki_space_id' => $this->space->id, 'title' => 'Nivå 1']);
    $deep = $top;
    foreach (range(2, 5) as $level) {
        $deep = WikiPage::factory()->childOf($deep)->create(['title' => "Nivå {$level}"]);
    }
    $this->actingAs($this->reader);

    $pageQueriesFor = function (WikiPage $page) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('wiki.page', [$this->space, $page]))->assertOk();
        DB::disableQueryLog();

        return collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'wiki_pages'))->count();
    };

    $pageQueriesFor($top); // warm-up: permission and settings caches

    expect($pageQueriesFor($deep))->toBe($pageQueriesFor($top));

    $this->get(route('wiki.page', [$this->space, $deep]))
        ->assertSeeInOrder(['Wiki', $this->space->name, 'Nivå 1', 'Nivå 2', 'Nivå 3', 'Nivå 4', 'Nivå 5']);
});

// ── Sanitizing ────────────────────────────────────────────────────────────────

it('keeps Trix image and gallery classes but drops every other class', function () {
    $page = WikiPage::factory()->create([
        'wiki_space_id' => $this->space->id,
        'content' => '<figure class="attachment attachment--preview attachment--png fixed inset-0 z-50"><img src="/storage/pages/karta.png"><figcaption class="attachment__caption">Karta</figcaption></figure>'
            .'<div class="attachment-gallery attachment-gallery--2 hidden">x</div><div class="fixed inset-0">Täcker allt</div><p class="attachment">p</p>',
    ]);

    expect($page->content)
        ->toContain('<figure class="attachment attachment--preview attachment--png">')
        ->toContain('<figcaption class="attachment__caption">')
        ->toContain('<div class="attachment-gallery attachment-gallery--2">')
        ->toContain('<div>Täcker allt</div>')
        ->toContain('<p>p</p>')
        ->not->toContain('inset-0')
        ->not->toContain('z-50')
        ->not->toContain('hidden');
});

it('removes scripts, event handlers and javascript links but keeps formatting', function () {
    $page = WikiPage::factory()->create([
        'wiki_space_id' => $this->space->id,
        'content' => '<div onclick="steal()">Hej<script>alert(1)</script><a href="javascript:alert(2)">x</a>'
            .'<img src="/x.png" onerror="alert(3)"><strong>fet</strong><a href="https://example.com">länk</a></div>',
    ]);

    expect($page->content)
        ->not->toContain('script')
        ->not->toContain('onclick')
        ->not->toContain('onerror')
        ->not->toContain('javascript:')
        ->toContain('<strong>fet</strong>')
        ->toContain('href="https://example.com"');
});

// ── Creating and editing ──────────────────────────────────────────────────────

it('lets a group member create a page, last among its siblings', function () {
    $parent = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    WikiPage::factory()->childOf($parent)->create(['sort_order' => 4]);
    $this->actingAs($this->writer);

    Volt::test('wiki.page-form', ['space' => $this->space])
        ->set('title', 'Kassörens år')
        ->set('content', '<div>Januari: bokslut</div>')
        ->set('parent_id', $parent->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('wiki.page', [$this->space, 'kassorens-ar']));

    $page = WikiPage::where('slug', 'kassorens-ar')->first();
    expect($page->parent_id)->toBe($parent->id)
        ->and($page->sort_order)->toBe(5)
        ->and($page->created_by)->toBe($this->writer->id);
});

it('preselects the parent from the link on a page', function () {
    $parent = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    $this->actingAs($this->writer);

    $other = WikiPage::factory()->create(); // a page in another space is ignored

    // The component's initial state is in its wire:snapshot attribute.
    $this->get(route('wiki.pages.create', ['space' => $this->space, 'parent' => $parent->id]))
        ->assertOk()
        ->assertSee('&quot;parent_id&quot;:'.$parent->id, false);

    $this->get(route('wiki.pages.create', ['space' => $this->space, 'parent' => $other->id]))
        ->assertOk()
        ->assertSee('&quot;parent_id&quot;:null', false);
});

it('keeps the slug when the title changes', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id, 'title' => 'Gammal', 'slug' => 'gammal']);
    $this->actingAs($this->writer);

    Volt::test('wiki.page-form', ['space' => $this->space, 'page' => $page])
        ->set('title', 'Ny titel')
        ->call('save')
        ->assertHasNoErrors();

    expect($page->fresh()->title)->toBe('Ny titel')
        ->and($page->fresh()->slug)->toBe('gammal')
        ->and($page->fresh()->updated_by)->toBe($this->writer->id);
});

it('refuses a parent that is the page itself, one of its subpages, or in another space', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    $child = WikiPage::factory()->childOf($page)->create();
    $grandchild = WikiPage::factory()->childOf($child)->create();
    $elsewhere = WikiPage::factory()->create();
    $this->actingAs($this->writer);

    foreach ([$page, $child, $grandchild, $elsewhere] as $parent) {
        Volt::test('wiki.page-form', ['space' => $this->space, 'page' => $page])
            ->set('parent_id', $parent->id)
            ->call('save')
            ->assertHasErrors('parent_id');
    }

    expect($page->fresh()->parent_id)->toBeNull();
});

it('gives a page a free slug: taken, trashed and reserved slugs are skipped', function () {
    WikiPage::factory()->create(['wiki_space_id' => $this->space->id, 'slug' => 'lan'])->delete();

    expect(WikiPage::createIn($this->space, ['title' => 'LAN'], $this->writer)->slug)->toBe('lan-2')
        ->and(WikiPage::createIn($this->space, ['title' => 'LAN'], $this->writer)->slug)->toBe('lan-3')
        ->and(WikiPage::createIn($this->space, ['title' => 'Ny sida'], $this->writer)->slug)->toBe('ny-sida-2');
});

it('keeps the form to those who may write', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);

    $this->get(route('wiki.pages.create', $this->space))->assertRedirect(route('login'));
    $this->actingAs($this->reader)->get(route('wiki.pages.create', $this->space))->assertForbidden();
    $this->actingAs($this->reader)->get(route('wiki.pages.edit', [$this->space, $page]))->assertForbidden();
    $this->actingAs($this->writer)->get(route('wiki.pages.create', $this->space))->assertOk();
    $this->actingAs($this->writer)->get(route('wiki.pages.edit', [$this->space, $page]))->assertOk();
});

it('makes an archived space read-only for the group', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    $this->space->update(['archived_at' => now()]);

    $this->actingAs($this->writer)->get(route('wiki.pages.edit', [$this->space, $page]))->assertForbidden();
    $this->actingAs($this->writer)->get(route('wiki.page', [$this->space, $page]))->assertOk()->assertDontSee('Redigera');
});

// ── Deleting and ordering ─────────────────────────────────────────────────────

it('moves subpages up one level when a page is deleted', function () {
    $top = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    $page = WikiPage::factory()->childOf($top)->create();
    $child = WikiPage::factory()->childOf($page)->create();
    $this->actingAs($this->writer);

    Volt::test('wiki.page', ['space' => $this->space, 'page' => $page])
        ->call('delete')
        ->assertRedirect(route('wiki.space', $this->space));

    expect(WikiPage::find($page->id))->toBeNull()
        ->and($child->fresh()->parent_id)->toBe($top->id);
});

it('keeps deleting to those who may write', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    $this->actingAs($this->reader);

    Volt::test('wiki.page', ['space' => $this->space, 'page' => $page])->call('delete')->assertForbidden();
    expect($page->fresh())->not->toBeNull();
});

it('reorders siblings, even when they share a sort order', function () {
    [$a, , $c] = collect(['A', 'B', 'C'])->map(fn ($t) => WikiPage::factory()->create(['wiki_space_id' => $this->space->id, 'title' => $t, 'sort_order' => 0]))->all();
    $this->actingAs($this->writer);

    Volt::test('wiki.space', ['space' => $this->space])->call('movePageDown', $a->id);

    expect($this->space->rootPages()->pluck('title')->all())->toBe(['B', 'A', 'C']);

    $this->actingAs($this->reader);
    Volt::test('wiki.space', ['space' => $this->space])->call('movePageUp', $c->id)->assertForbidden();
});

it('renumbers siblings in one query, without counting it as an edit', function () {
    $pages = collect(range(1, 5))->map(fn ($i) => WikiPage::factory()->create(['wiki_space_id' => $this->space->id, 'title' => "Sida {$i}", 'sort_order' => 0]));
    $this->travel(1)->hour();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $pages->last()->move(-1);
    DB::disableQueryLog();

    $updates = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'update'));

    expect($updates)->toHaveCount(1)
        ->and($this->space->rootPages()->pluck('title')->all())->toBe(['Sida 1', 'Sida 2', 'Sida 3', 'Sida 5', 'Sida 4'])
        ->and($pages->last()->fresh()->updated_at->equalTo($pages->last()->updated_at))->toBeTrue();
});

// ── API ───────────────────────────────────────────────────────────────────────

it('lists a space\'s pages without content and shows one with it', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id, 'slug' => 'stanga', 'content' => '<div>Släck</div>']);
    WikiPage::factory()->childOf($page)->create(['slug' => 'larmet']);

    $this->getJson("/api/v1/wiki/spaces/{$this->space->slug}/pages")->assertNotFound();

    Sanctum::actingAs($this->reader);
    $list = $this->getJson("/api/v1/wiki/spaces/{$this->space->slug}/pages")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonMissingPath('data.0.content')
        ->json('data');

    expect(collect($list)->firstWhere('slug', 'larmet')['parent_id'])->toBe($page->id);

    $this->getJson("/api/v1/wiki/spaces/{$this->space->slug}/pages/stanga")
        ->assertOk()
        ->assertJsonPath('data.content', '<div>Släck</div>')
        ->assertJsonPath('data.url', "/wiki/{$this->space->slug}/stanga");
});

it('creates, updates and deletes pages through the API with the same rules', function () {
    Sanctum::actingAs(pageUser(inGroup: $this->group, apiAccess: true));
    $base = "/api/v1/wiki/spaces/{$this->space->slug}/pages";

    $this->postJson($base, ['title' => 'Arrangera ett LAN', 'content' => '<div>Boka lokal<script>x()</script></div>'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'arrangera-ett-lan')
        ->assertJsonPath('data.content', '<div>Boka lokal</div>');

    $page = WikiPage::where('slug', 'arrangera-ett-lan')->first();
    $child = WikiPage::factory()->childOf($page)->create();

    $this->patchJson("{$base}/arrangera-ett-lan", ['title' => 'LAN-guiden'])
        ->assertOk()
        ->assertJsonPath('data.title', 'LAN-guiden')
        ->assertJsonPath('data.slug', 'arrangera-ett-lan');

    $this->patchJson("{$base}/arrangera-ett-lan", ['parent_id' => $child->id])->assertUnprocessable();

    $this->deleteJson("{$base}/arrangera-ett-lan")->assertOk();
    expect($child->fresh()->parent_id)->toBeNull();
});

it('refuses API writes without write access, and hides closed spaces', function () {
    Sanctum::actingAs(pageUser(apiAccess: true));
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id, 'slug' => 'sida']);
    $closed = WikiSpace::factory()->forGroup()->readableBy(WikiReadAccess::Group)->create();

    $this->postJson("/api/v1/wiki/spaces/{$this->space->slug}/pages", ['title' => 'X'])->assertForbidden();
    $this->patchJson("/api/v1/wiki/spaces/{$this->space->slug}/pages/sida", ['title' => 'X'])->assertForbidden();
    $this->deleteJson("/api/v1/wiki/spaces/{$this->space->slug}/pages/sida")->assertForbidden();
    $this->postJson("/api/v1/wiki/spaces/{$closed->slug}/pages", ['title' => 'X'])->assertNotFound();

    expect($page->fresh()->title)->not->toBe('X');
});
