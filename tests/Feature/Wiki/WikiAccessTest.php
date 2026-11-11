<?php

use App\Enums\WikiReadAccess;
use App\Models\Membership\Member;
use App\Models\Organization\OrganizationalRoleMember;
use App\Models\Organization\OrganizationalRolePeriod;
use App\Models\Organization\Section;
use App\Models\Organization\SectionMembership;
use App\Models\Organization\SectionRole;
use App\Models\User;
use App\Models\Wiki\WikiPage;
use App\Models\Wiki\WikiSpace;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    // Sections require an active association membership by default; the personas
    // below are not active members, so the test group switches that off.
    $this->group = Section::factory()->create(['visibility' => Section::VISIBILITY_PUBLIC, 'membership_requires_active' => false]);
});

// ── Personas ──────────────────────────────────────────────────────────────────
// Each is one thing only: an official or a group member here is not also an
// active association member, so every read level is tested on its own.

function wikiUser(string $status = Member::STATUS_PENDING): User
{
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id, 'status' => $status]);

    return $user->fresh();
}

function wikiPersona(string $who, Section $group): ?User
{
    return match ($who) {
        'guest' => null,
        'user' => User::factory()->create(),
        'member' => wikiUser(Member::STATUS_ACTIVE),
        'official' => tap(wikiUser(), fn (User $u) => SectionRole::create([
            'section_id' => Section::factory()->create()->id, 'member_id' => $u->member->id, 'title' => 'Kassör', 'valid_from' => today()->subYear(),
        ])),
        'group member' => tap(wikiUser(), fn (User $u) => joinGroup($u, $group)),
        'wiki.view' => tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('wiki.view')),
    };
}

function joinGroup(User $user, Section $group, array $attributes = []): SectionMembership
{
    return SectionMembership::create([
        'section_id' => $group->id, 'member_id' => $user->member->id,
        'status' => SectionMembership::STATUS_ACTIVE, 'joined_at' => today(), ...$attributes,
    ]);
}

function wikiAllows(?User $user, string $ability, mixed $argument): bool
{
    return $user ? Gate::forUser($user)->allows($ability, $argument) : Gate::allows($ability, $argument);
}

// ── Reading ───────────────────────────────────────────────────────────────────

it('reads a group space according to its read level', function (WikiReadAccess $access, array $expected) {
    $space = WikiSpace::factory()->forGroup($this->group)->readableBy($access)->create();

    foreach ($expected as $who => $canRead) {
        expect(wikiAllows(wikiPersona($who, $this->group), 'view', $space))
            ->toBe($canRead, "{$who} reading a {$access->value} space");
    }
})->with([
    'public' => [WikiReadAccess::Public, ['guest' => true, 'user' => true, 'member' => true, 'official' => true, 'group member' => true, 'wiki.view' => true]],
    'members' => [WikiReadAccess::Members, ['guest' => false, 'user' => false, 'member' => true, 'official' => false, 'group member' => false, 'wiki.view' => true]],
    'officials' => [WikiReadAccess::Officials, ['guest' => false, 'user' => false, 'member' => false, 'official' => true, 'group member' => false, 'wiki.view' => true]],
    'group' => [WikiReadAccess::Group, ['guest' => false, 'user' => false, 'member' => false, 'official' => false, 'group member' => true, 'wiki.view' => true]],
]);

it('never opens a space wider than a private or secret group', function (string $visibility) {
    $this->group->update(['visibility' => $visibility]);
    $space = WikiSpace::factory()->forGroup($this->group)->readableBy(WikiReadAccess::Public)->create();

    expect(wikiAllows(null, 'view', $space))->toBeFalse()
        ->and(wikiAllows(wikiPersona('member', $this->group), 'view', $space))->toBeFalse()
        ->and(wikiAllows(wikiPersona('group member', $this->group), 'view', $space))->toBeTrue()
        ->and(wikiAllows(wikiPersona('wiki.view', $this->group), 'view', $space))->toBeTrue();
})->with([Section::VISIBILITY_PRIVATE, Section::VISIBILITY_SECRET]);

it('lets nobody but wiki.view read a shared space set to group', function () {
    $space = WikiSpace::factory()->shared()->readableBy(WikiReadAccess::Group)->create();

    expect(wikiAllows(wikiPersona('member', $this->group), 'view', $space))->toBeFalse()
        ->and(wikiAllows(wikiPersona('official', $this->group), 'view', $space))->toBeFalse()
        ->and(wikiAllows(wikiPersona('wiki.view', $this->group), 'view', $space))->toBeTrue();
});

it('does not count a section role that has not started yet', function () {
    $space = WikiSpace::factory()->readableBy(WikiReadAccess::Officials)->create();
    $future = wikiUser();
    SectionRole::create(['section_id' => $this->group->id, 'member_id' => $future->member->id, 'title' => 'Kassör', 'valid_from' => today()->addMonth()]);

    expect(wikiAllows($future, 'view', $space))->toBeFalse();
});

it('checks ten pages with no more queries than one', function () {
    $space = WikiSpace::factory()->forGroup($this->group)->readableBy(WikiReadAccess::Members)->create();
    WikiPage::factory()->count(10)->create(['wiki_space_id' => $space->id]);
    $member = wikiPersona('group member', $this->group);

    $queriesFor = function (int $count) use ($member) {
        $user = $member->fresh(); // a new user object: nothing cached yet
        $pages = WikiPage::with('space.group')->limit($count)->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        foreach ($pages as $page) {
            Gate::forUser($user)->allows('view', $page);
            Gate::forUser($user)->allows('update', $page);
        }
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $queriesFor(1); // warms Spatie's permission cache, loaded once per process

    expect($queriesFor(10))->toBe($queriesFor(1));
});

it('counts only active roles and terms as officials', function () {
    $space = WikiSpace::factory()->readableBy(WikiReadAccess::Officials)->create();

    $ended = wikiUser();
    SectionRole::create(['section_id' => $this->group->id, 'member_id' => $ended->member->id, 'title' => 'Ordförande', 'valid_from' => today()->subYears(2), 'valid_until' => today()->subDay()]);

    $closedTerm = wikiUser();
    OrganizationalRoleMember::factory()->create([
        'member_id' => $closedTerm->member->id,
        'organizational_role_period_id' => OrganizationalRolePeriod::factory()->closed(),
    ]);

    $activeTerm = wikiUser();
    OrganizationalRoleMember::factory()->create(['member_id' => $activeTerm->member->id]);

    expect(wikiAllows($ended, 'view', $space))->toBeFalse()
        ->and(wikiAllows($closedTerm, 'view', $space))->toBeFalse()
        ->and(wikiAllows($activeTerm, 'view', $space))->toBeTrue();
});

it('gives readableBy() exactly the same answer as the policy', function (string $who) {
    $secret = Section::factory()->create(['visibility' => Section::VISIBILITY_SECRET, 'membership_requires_active' => false]);
    $user = wikiPersona($who, $this->group);
    if ($user?->member && $who === 'group member') {
        joinGroup($user, $secret);
    }

    $spaces = collect();
    foreach (WikiReadAccess::cases() as $access) {
        $spaces->push(WikiSpace::factory()->shared()->readableBy($access)->create());
        $spaces->push(WikiSpace::factory()->forGroup()->readableBy($access)->create());
    }
    $spaces->push(WikiSpace::factory()->forGroup($this->group)->readableBy(WikiReadAccess::Group)->create());
    $spaces->push(WikiSpace::factory()->forGroup($secret)->readableBy(WikiReadAccess::Public)->create());

    $byPolicy = $spaces->filter(fn (WikiSpace $s) => wikiAllows($user, 'view', $s))->pluck('id')->sort()->values()->all();
    $byQuery = WikiSpace::readableBy($user)->pluck('id')->sort()->values()->all();

    expect($byQuery)->toBe($byPolicy)->not->toBeEmpty();
})->with(['guest', 'user', 'member', 'official', 'group member', 'wiki.view']);

it('filters pages by the space they live in', function () {
    $open = WikiPage::factory()->create(['wiki_space_id' => WikiSpace::factory()->readableBy(WikiReadAccess::Public)]);
    $closed = WikiPage::factory()->create(['wiki_space_id' => WikiSpace::factory()->forGroup($this->group)->readableBy(WikiReadAccess::Group)]);
    $member = wikiPersona('member', $this->group);

    expect(WikiPage::readableBy($member)->pluck('id')->all())->toBe([$open->id])
        ->and(wikiAllows($member, 'view', $open))->toBeTrue()
        ->and(wikiAllows($member, 'view', $closed))->toBeFalse();
});

// ── Writing ───────────────────────────────────────────────────────────────────

it('lets the group write in its own space, and nobody else without wiki.manage', function () {
    $space = WikiSpace::factory()->forGroup($this->group)->readableBy(WikiReadAccess::Public)->create();
    $page = WikiPage::factory()->create(['wiki_space_id' => $space->id]);

    $inGroup = wikiPersona('group member', $this->group);
    $manager = tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('wiki.manage'));

    foreach ([[$inGroup, true], [$manager, true], [wikiPersona('member', $this->group), false], [wikiPersona('official', $this->group), false], [wikiPersona('wiki.view', $this->group), false]] as [$user, $canWrite]) {
        expect(Gate::forUser($user)->allows('write', $space))->toBe($canWrite)
            ->and(Gate::forUser($user)->allows('create', [WikiPage::class, $space]))->toBe($canWrite)
            ->and(Gate::forUser($user)->allows('update', $page))->toBe($canWrite)
            ->and(Gate::forUser($user)->allows('delete', $page))->toBe($canWrite);
    }
});

it('takes write access away from someone who has left the group', function () {
    $space = WikiSpace::factory()->forGroup($this->group)->create();
    $user = wikiPersona('group member', $this->group);

    expect(Gate::forUser($user)->allows('write', $space))->toBeTrue();

    SectionMembership::where('member_id', $user->member->id)->update(['left_at' => today()]);

    expect(Gate::forUser($user->fresh())->allows('write', $space))->toBeFalse();
});

it('suspends write access when the group requires an active membership that has lapsed', function () {
    $this->group->update(['membership_requires_active' => true]);
    $space = WikiSpace::factory()->forGroup($this->group)->create();

    expect(Gate::forUser(wikiPersona('group member', $this->group))->allows('write', $space))->toBeFalse();
});

it('lets wiki.edit write in shared spaces only', function () {
    $editor = tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('wiki.edit'));

    expect(Gate::forUser($editor)->allows('write', WikiSpace::factory()->shared()->create()))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('write', WikiSpace::factory()->forGroup()->create()))->toBeFalse()
        ->and(Gate::forUser(wikiPersona('member', $this->group))->allows('write', WikiSpace::factory()->shared()->create()))->toBeFalse();
});

it('keeps creating and changing spaces to wiki.manage', function () {
    $space = WikiSpace::factory()->create();
    $manager = tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('wiki.manage'));
    $viewer = wikiPersona('wiki.view', $this->group);

    expect(Gate::forUser($manager)->allows('create', WikiSpace::class))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('update', $space))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('create', WikiSpace::class))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('delete', $space))->toBeFalse();
});
