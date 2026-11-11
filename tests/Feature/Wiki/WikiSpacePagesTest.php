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
});

function activeMember(): User
{
    $user = User::factory()->create();
    Member::factory()->active()->create(['user_id' => $user->id]);

    return $user->fresh();
}

function wikiManager(): User
{
    return tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('wiki.manage'));
}

// ── Start page and space page ─────────────────────────────────────────────────

it('shows guests the public spaces only', function () {
    WikiSpace::factory()->readableBy(WikiReadAccess::Public)->create(['name' => 'Hitta till lokalen']);
    WikiSpace::factory()->readableBy(WikiReadAccess::Members)->create(['name' => 'Arrangera ett LAN']);

    $this->get(route('wiki.index'))
        ->assertOk()
        ->assertSee('Hitta till lokalen')
        ->assertDontSee('Arrangera ett LAN');
});

it('shows a member the member spaces, but not a closed group space', function () {
    WikiSpace::factory()->readableBy(WikiReadAccess::Members)->create(['name' => 'Arrangera ett LAN']);
    WikiSpace::factory()->forGroup()->readableBy(WikiReadAccess::Group)->create(['name' => 'Styrelsens rum']);

    $this->actingAs(activeMember())->get(route('wiki.index'))
        ->assertOk()
        ->assertSee('Arrangera ett LAN')
        ->assertDontSee('Styrelsens rum');
});

it('lists recently changed pages from readable spaces only', function () {
    WikiPage::factory()->create(['title' => 'Stänga lokalen', 'wiki_space_id' => WikiSpace::factory()->readableBy(WikiReadAccess::Public)]);
    WikiPage::factory()->create(['title' => 'Larmkoder', 'wiki_space_id' => WikiSpace::factory()->forGroup()->readableBy(WikiReadAccess::Group)]);

    $this->get(route('wiki.index'))
        ->assertSee('Stänga lokalen')
        ->assertDontSee('Larmkoder');
});

it('hides archived spaces from the start page but keeps them readable', function () {
    $space = WikiSpace::factory()->readableBy(WikiReadAccess::Public)->create(['name' => 'Gamla konventet', 'archived_at' => now()]);

    $this->get(route('wiki.index'))->assertDontSee('Gamla konventet');
    $this->get(route('wiki.space', $space))->assertOk()->assertSee('Utrymmet arkiverades');
});

it('shows a space with its page tree', function () {
    $space = WikiSpace::factory()->readableBy(WikiReadAccess::Public)->create(['name' => 'Lokalen']);
    $root = WikiPage::factory()->create(['wiki_space_id' => $space->id, 'title' => 'Öppna och stänga']);
    WikiPage::factory()->childOf($root)->create(['title' => 'Larmet']);

    $this->get(route('wiki.space', $space))
        ->assertOk()
        ->assertSee('Lokalen')
        ->assertSeeInOrder(['Öppna och stänga', 'Larmet']);
});

it('answers 404 for a space the visitor may not read, so it stays hidden', function () {
    $this->get(route('wiki.space', WikiSpace::factory()->readableBy(WikiReadAccess::Members)->create()))->assertNotFound();

    $secret = Section::factory()->create(['visibility' => Section::VISIBILITY_SECRET]);
    $this->actingAs(activeMember())->get(route('wiki.space', $secret->wikiSpace))->assertNotFound();
});

it('always shows the wiki in the menu to logged-in users, without access lookups on every page', function () {
    $this->actingAs(User::factory()->create());

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('tournaments.index'))->assertSee(route('wiki.index'));
    DB::disableQueryLog();

    expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'wiki_spaces')))->toBeEmpty();

    // With nothing to read, /wiki says so.
    $this->get(route('wiki.index'))->assertSee('Det finns inga utrymmen som du kan läsa ännu.');
});

it('links a group page to the group wiki', function () {
    $group = Section::factory()->create(['visibility' => Section::VISIBILITY_PUBLIC]);
    $user = activeMember();

    $this->actingAs($user)->get(route('groups.show', $group))
        ->assertOk()
        ->assertSee('Gruppens wiki')
        ->assertSee(route('wiki.space', $group->wikiSpace));
});

// ── Admin ─────────────────────────────────────────────────────────────────────

it('keeps the admin page to wiki.manage', function () {
    $this->actingAs(activeMember())->get(route('admin.wiki.index'))->assertForbidden();
    $this->actingAs(wikiManager())->get(route('admin.wiki.index'))->assertOk();
});

it('lets wiki.manage create an association-wide space', function () {
    $this->actingAs(wikiManager());

    Volt::test('admin.wiki.index')
        ->call('create')
        ->set('name', 'Hela föreningen')
        ->set('description', 'Det som gäller alla')
        ->set('readAccess', 'public')
        ->call('save')
        ->assertHasNoErrors();

    $space = WikiSpace::where('name', 'Hela föreningen')->first();
    expect($space->isShared())->toBeTrue()
        ->and($space->slug)->toBe('hela-foreningen')
        ->and($space->read_access)->toBe(WikiReadAccess::Public);
});

it('keeps the slug when a space is renamed, so links keep working', function () {
    $space = WikiSpace::factory()->create(['name' => 'Gammalt namn', 'slug' => 'gammalt-namn']);
    $this->actingAs(wikiManager());

    Volt::test('admin.wiki.index')
        ->call('edit', $space->id)
        ->set('name', 'Nytt namn')
        ->call('save')
        ->assertHasNoErrors();

    expect($space->fresh()->name)->toBe('Nytt namn')
        ->and($space->fresh()->slug)->toBe('gammalt-namn');
});

it('validates the form', function () {
    $this->actingAs(wikiManager());

    Volt::test('admin.wiki.index')
        ->call('create')
        ->set('name', '')
        ->set('readAccess', 'everyone')
        ->call('save')
        ->assertHasErrors(['name' => 'required', 'readAccess']);
});

it('archives and restores a space, and an archived space is read-only for its group', function () {
    $group = Section::factory()->create(['membership_requires_active' => false]);
    $user = activeMember();
    SectionMembership::create(['section_id' => $group->id, 'member_id' => $user->member->id, 'status' => SectionMembership::STATUS_ACTIVE, 'joined_at' => today()]);
    $space = $group->wikiSpace;

    expect($user->can('write', $space))->toBeTrue();

    $this->actingAs(wikiManager());
    Volt::test('admin.wiki.index')->call('toggleArchived', $space->id);

    expect($space->fresh()->isArchived())->toBeTrue()
        ->and($user->fresh()->can('write', $space->fresh()))->toBeFalse();

    Volt::test('admin.wiki.index')->call('toggleArchived', $space->id);
    expect($space->fresh()->isArchived())->toBeFalse();
});

// ── Groups ────────────────────────────────────────────────────────────────────

it('creates spaces for groups that lack one, and can run again', function () {
    $without = Section::factory()->createQuietly(['name' => 'Rollspel']);
    $with = Section::factory()->create();

    $this->artisan('wiki:create-group-spaces')->expectsOutputToContain('Skapade 1 wikiutrymmen')->assertSuccessful();
    $this->artisan('wiki:create-group-spaces')->expectsOutputToContain('Alla grupper har redan')->assertSuccessful();

    expect($without->fresh()->wikiSpace->name)->toBe('Rollspel')
        ->and(WikiSpace::where('group_id', $with->id)->count())->toBe(1);
});

it('explains why a group whose wiki has pages cannot be deleted', function () {
    $group = Section::factory()->create(['name' => 'Brädspel']);
    WikiPage::factory()->create(['wiki_space_id' => $group->wikiSpace->id]);
    $admin = tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('sections.manage'));

    $this->actingAs($admin);
    Volt::test('admin.sections.index')
        ->call('deleteSection', $group->id)
        ->assertHasErrors('delete');

    expect($group->fresh())->not->toBeNull();
});

it('deletes a group with an empty wiki space', function () {
    $group = Section::factory()->create();
    $admin = tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('sections.manage'));

    $this->actingAs($admin);
    Volt::test('admin.sections.index')->call('deleteSection', $group->id)->assertHasNoErrors();

    expect($group->fresh())->toBeNull()
        ->and(WikiSpace::where('group_id', $group->id)->exists())->toBeFalse();
});

// ── API ───────────────────────────────────────────────────────────────────────

it('lists public spaces to guests and more with a token', function () {
    WikiSpace::factory()->readableBy(WikiReadAccess::Public)->create(['slug' => 'oppet']);
    WikiSpace::factory()->readableBy(WikiReadAccess::Members)->create(['slug' => 'medlemmar']);
    WikiSpace::factory()->readableBy(WikiReadAccess::Public)->create(['slug' => 'arkiverat', 'archived_at' => now()]);

    $this->getJson('/api/v1/wiki/spaces')
        ->assertOk()
        ->assertJsonPath('data.*.slug', ['oppet']);

    Sanctum::actingAs(activeMember());

    expect(collect($this->getJson('/api/v1/wiki/spaces')->json('data'))->pluck('slug')->sort()->values()->all())
        ->toBe(['medlemmar', 'oppet']);
});

it('shows a readable space and 404s a closed one', function () {
    $open = WikiSpace::factory()->readableBy(WikiReadAccess::Public)->create(['slug' => 'oppet']);
    WikiSpace::factory()->forGroup()->readableBy(WikiReadAccess::Group)->create(['slug' => 'stangt']);

    $this->getJson('/api/v1/wiki/spaces/oppet')
        ->assertOk()
        ->assertJsonPath('data.id', $open->id)
        ->assertJsonPath('data.read_access', 'public')
        ->assertJsonPath('data.url', '/wiki/oppet');

    $this->getJson('/api/v1/wiki/spaces/stangt')->assertNotFound();
    $this->getJson('/api/v1/wiki/spaces/finns-inte')->assertNotFound();
});

it('refuses to delete a group whose wiki has pages through the API', function () {
    $group = Section::factory()->create();
    WikiPage::factory()->create(['wiki_space_id' => $group->wikiSpace->id]);
    $admin = tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo(['sections.manage', 'api.access']));

    Sanctum::actingAs($admin);

    $this->deleteJson("/api/v1/sections/{$group->id}")->assertStatus(422);
    expect($group->fresh())->not->toBeNull();
});
