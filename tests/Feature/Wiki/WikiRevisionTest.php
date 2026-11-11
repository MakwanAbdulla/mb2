<?php

use App\Enums\WikiReadAccess;
use App\Models\Membership\Member;
use App\Models\Organization\Section;
use App\Models\Organization\SectionMembership;
use App\Models\User;
use App\Models\Wiki\WikiPage;
use App\Models\Wiki\WikiPageRevision;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->group = Section::factory()->create(['visibility' => Section::VISIBILITY_PUBLIC, 'membership_requires_active' => false]);
    $this->space = tap($this->group->wikiSpace)->update(['read_access' => WikiReadAccess::Members]);
    $this->writer = revisionUser(inGroup: $this->group);
    $this->reader = revisionUser();
});

/** An active member, optionally in a group. Named apart from other wiki test helpers. */
function revisionUser(?Section $inGroup = null, bool $apiAccess = false): User
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

// ── Writing revisions ─────────────────────────────────────────────────────────

it('writes the first revision when a page is created', function () {
    $this->actingAs($this->writer);

    Volt::test('wiki.page-form', ['space' => $this->space])
        ->set('title', 'Stänga lokalen')
        ->set('content', '<div>Släck lampan</div><script>alert(1)</script>')
        ->set('summary', 'Första versionen')
        ->call('save')
        ->assertHasNoErrors();

    $revision = WikiPage::firstWhere('title', 'Stänga lokalen')->revisions()->sole();

    expect($revision->title)->toBe('Stänga lokalen')
        ->and($revision->content)->toBe('<div>Släck lampan</div>')
        ->and($revision->summary)->toBe('Första versionen')
        ->and($revision->user_id)->toBe($this->writer->id);
});

it('writes a revision on every edit, with the description of the change', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id, 'content' => '<div>Gammal</div>']);
    $this->actingAs($this->writer);

    Volt::test('wiki.page-form', ['space' => $this->space, 'page' => $page])
        ->set('content', '<div>Ny</div>')
        ->set('summary', 'Lade till larmkoden')
        ->call('save')
        ->assertHasNoErrors();

    $revisions = $page->revisions()->get();

    expect($revisions)->toHaveCount(2)
        ->and($revisions->first()->content)->toBe('<div>Ny</div>')
        ->and($revisions->first()->summary)->toBe('Lade till larmkoden')
        ->and($revisions->first()->user_id)->toBe($this->writer->id)
        ->and($revisions->last()->content)->toBe('<div>Gammal</div>');
});

it('writes no revision when nothing in the text changed, or the page only moved', function () {
    $parent = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    $this->actingAs($this->writer);

    Volt::test('wiki.page-form', ['space' => $this->space, 'page' => $page])->call('save');
    Volt::test('wiki.page-form', ['space' => $this->space, 'page' => $page])->set('parent_id', $parent->id)->call('save');
    $page->fresh()->move(-1);

    expect($page->revisions()->count())->toBe(1)
        ->and($page->fresh()->parent_id)->toBe($parent->id);
});

it('does not save the page when its revision cannot be written', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id, 'title' => 'Före']);
    WikiPageRevision::creating(fn () => throw new RuntimeException('disk full'));

    expect(fn () => $page->updateFrom(['title' => 'Efter'], $this->writer))->toThrow(RuntimeException::class)
        ->and($page->fresh()->title)->toBe('Före')
        ->and($page->revisions()->count())->toBe(1);
});

it('limits the description of a change to 255 characters', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    $this->actingAs($this->writer);

    Volt::test('wiki.page-form', ['space' => $this->space, 'page' => $page])
        ->set('summary', str_repeat('a', 256))
        ->call('save')
        ->assertHasErrors(['summary' => 'max']);
});

it('keeps the history when the editor is deleted', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    $page->updateFrom(['title' => 'Ändrad'], $this->writer);

    $this->writer->delete();

    expect($page->revisions()->count())->toBe(2)
        ->and($page->revisions()->first()->user_id)->toBeNull();
});

it('gives pages written before revisions existed a first revision when migrating', function () {
    $user = User::factory()->create();
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id, 'title' => 'Gammal sida', 'content' => '<div>Text</div>', 'updated_by' => $user->id]);
    expect(DB::table('wiki_pages')->where('id', $page->id)->update(['updated_at' => '2026-03-12 18:30:00']))->toBe(1);

    Schema::drop('wiki_page_revisions');
    (require database_path('migrations/2026_11_05_100000_create_wiki_page_revisions_table.php'))->up();

    $revision = $page->revisions()->sole();
    expect($revision->title)->toBe('Gammal sida')
        ->and($revision->content)->toBe('<div>Text</div>')
        ->and($revision->user_id)->toBe($user->id)
        ->and($revision->summary)->toBeNull()
        ->and($revision->created_at->format('d/m Y H:i'))->toBe('12/03 2026 18:30');
});

it('leaves the content out of the history list', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    $this->actingAs($this->reader);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('wiki.pages.history', [$this->space, $page]))->assertOk();
    DB::disableQueryLog();

    $listQuery = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_contains($q, 'from "wiki_page_revisions"') && str_contains($q, 'limit'));
    expect($listQuery)->not->toBeNull()->not->toContain('*')->not->toContain('"content"');
});

// ── History pages ─────────────────────────────────────────────────────────────

it('lists who changed the page, when and why, newest first', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    $page->updateFrom(['content' => '<div>Ny</div>', 'summary' => 'Lade till larmkoden'], $this->writer);

    $this->actingAs($this->reader)->get(route('wiki.pages.history', [$this->space, $page]))
        ->assertOk()
        ->assertSeeInOrder(['Lade till larmkoden', 'Nuvarande', 'Ingen beskrivning'])
        ->assertSee($this->writer->name)
        ->assertSee($page->revisions()->first()->created_at->format('d/m Y H:i'));

    $this->actingAs($this->reader)->get(route('wiki.page', [$this->space, $page]))
        ->assertSee(route('wiki.pages.history', [$this->space, $page], absolute: false));
});

it('shows an old version with a link to the current one', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id, 'content' => '<div>Gammal text</div>']);
    $old = $page->revisions()->sole();
    $page->updateFrom(['content' => '<div>Ny text</div>'], $this->writer);

    $this->actingAs($this->reader)->get(route('wiki.pages.revision', [$this->space, $page, $old]))
        ->assertOk()
        ->assertSee('En äldre version')
        ->assertSee('Gammal text')
        ->assertDontSee('Ny text');

    $this->actingAs($this->reader)->get(route('wiki.pages.revision', [$this->space, $page, $page->revisions()->first()]))
        ->assertSee('Det här är den nuvarande versionen');
});

it('requires the same read access for the history as for the page', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    $revision = $page->revisions()->sole();

    $this->get(route('wiki.pages.history', [$this->space, $page]))->assertNotFound();
    $this->get(route('wiki.pages.revision', [$this->space, $page, $revision]))->assertNotFound();

    $this->space->update(['read_access' => WikiReadAccess::Public]);

    $this->get(route('wiki.pages.history', [$this->space, $page]))->assertOk();
    $this->get(route('wiki.pages.revision', [$this->space, $page, $revision]))->assertOk();
});

it('answers 404 for a revision of another page', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    $other = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);

    $this->actingAs($this->reader)
        ->get(route('wiki.pages.revision', [$this->space, $page, $other->revisions()->sole()]))
        ->assertNotFound();
});

// ── API ───────────────────────────────────────────────────────────────────────

it('lists and shows revisions over the API with the page\'s read access', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id, 'slug' => 'stanga', 'content' => '<div>Gammal</div>']);
    $page->updateFrom(['content' => '<div>Ny</div>', 'summary' => 'Uppdaterad'], $this->writer);
    $base = "/api/v1/wiki/spaces/{$this->space->slug}/pages/stanga/revisions";

    $this->getJson($base)->assertNotFound();

    Sanctum::actingAs($this->reader);
    $list = $this->getJson($base)->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.summary', 'Uppdaterad')
        ->assertJsonPath('data.0.user.name', $this->writer->name)
        ->assertJsonPath('meta.total', 2);
    expect($list->json('data.0'))->not->toHaveKey('content');

    $oldId = $list->json('data.1.id');
    $this->getJson("{$base}/{$oldId}")->assertOk()->assertJsonPath('data.content', '<div>Gammal</div>');

    $other = WikiPage::factory()->create(['wiki_space_id' => $this->space->id]);
    $this->getJson("{$base}/{$other->revisions()->value('id')}")->assertNotFound();
});

it('stores the description of a change sent over the API', function () {
    $page = WikiPage::factory()->create(['wiki_space_id' => $this->space->id, 'slug' => 'stanga']);
    Sanctum::actingAs(revisionUser(inGroup: $this->group, apiAccess: true));

    $this->patchJson("/api/v1/wiki/spaces/{$this->space->slug}/pages/stanga", ['title' => 'Stänga', 'summary' => 'Kortare titel'])
        ->assertOk();

    expect($page->revisions()->first()->summary)->toBe('Kortare titel');
});
