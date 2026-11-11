<?php

namespace App\Support;

use App\Enums\WikiReadAccess;
use App\Models\Membership\Member;
use App\Models\Organization\OrganizationalRoleMember;
use App\Models\Organization\OrganizationalRolePeriod;
use App\Models\Organization\Section;
use App\Models\Organization\SectionMembership;
use App\Models\Organization\SectionRole;
use App\Models\User;
use App\Models\Wiki\WikiSpace;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may read and write which wiki space — the one place both the policies
 * and WikiSpace::readableBy() ask, so a page, a search hit and an API call can
 * never disagree.
 *
 * Built once per user object (a few queries) and then answered from memory,
 * so checking many pages costs no more than checking one. The cache lives as
 * long as the User instance — in practice one request — so a membership or
 * role changed mid-request is seen by the next request.
 *
 * Callers checking many pages or spaces eager-load what canRead() reads:
 * `with('space.group')` for pages, `with('group')` for spaces. Otherwise each
 * check lazy-loads those relations, one query per page.
 *
 * TODO(#168): "member of a group" and "official" are answered from sections,
 * section roles and org-role periods today. Org roles linked to a section are
 * already synced into its memberships (org-roles:sync-permissions), so this
 * matches what groups will be; swap the queries in forUser() for Group.
 */
final class WikiAccess
{
    /**
     * @param  list<int>  $groupIds  groups the user is an active member of
     */
    private function __construct(
        public readonly bool $seesAll,
        public readonly bool $managesAll,
        public readonly bool $editsShared,
        public readonly bool $isMember,
        public readonly bool $isOfficial,
        public readonly array $groupIds,
    ) {}

    /** @var \WeakMap<User, self>|null */
    private static ?\WeakMap $cache = null;

    public static function forUser(?User $user): self
    {
        if ($user === null) {
            return new self(false, false, false, false, false, []);
        }

        self::$cache ??= new \WeakMap;

        return self::$cache[$user] ??= self::build($user);
    }

    private static function build(User $user): self
    {
        $member = $user->member;
        $manages = $user->can('wiki.manage');

        return new self(
            seesAll: $manages || $user->can('wiki.view'),
            managesAll: $manages,
            editsShared: $manages || $user->can('wiki.edit'),
            isMember: $user->isMember(),
            isOfficial: $member !== null && self::memberIsOfficial($member->id),
            groupIds: $member !== null ? self::activeGroupIds($member) : [],
        );
    }

    public function canRead(WikiSpace $space): bool
    {
        if ($this->seesAll) {
            return true;
        }

        // A space is never more open than a private or secret group.
        if ($space->group_id !== null
            && in_array($space->group?->visibility, [Section::VISIBILITY_PRIVATE, Section::VISIBILITY_SECRET], true)
            && ! $this->isInGroup($space->group_id)) {
            return false;
        }

        return match ($space->read_access) {
            WikiReadAccess::Public => true,
            WikiReadAccess::Members => $this->isMember,
            WikiReadAccess::Officials => $this->isOfficial,
            WikiReadAccess::Group => $space->group_id !== null && $this->isInGroup($space->group_id),
        };
    }

    public function canWrite(WikiSpace $space): bool
    {
        if ($this->managesAll) {
            return true;
        }

        if ($space->isArchived()) {
            return false;
        }

        return $space->group_id === null
            ? $this->editsShared
            : $this->isInGroup($space->group_id);
    }

    /** The same rules as canRead(), as a query — the filter runs in the database. */
    public function applyReadable(Builder $query): Builder
    {
        if ($this->seesAll) {
            return $query;
        }

        $closed = [Section::VISIBILITY_PRIVATE, Section::VISIBILITY_SECRET];

        return $query
            ->where(function (Builder $q) {
                $q->where('read_access', WikiReadAccess::Public->value);
                if ($this->isMember) {
                    $q->orWhere('read_access', WikiReadAccess::Members->value);
                }
                if ($this->isOfficial) {
                    $q->orWhere('read_access', WikiReadAccess::Officials->value);
                }
                $q->orWhere(fn (Builder $g) => $g
                    ->where('read_access', WikiReadAccess::Group->value)
                    ->whereIn('group_id', $this->groupIds));
            })
            ->where(fn (Builder $q) => $q
                ->whereNull('group_id')
                ->orWhereIn('group_id', $this->groupIds)
                ->orWhereHas('group', fn (Builder $g) => $g->whereNotIn('visibility', $closed)));
    }

    private function isInGroup(int $groupId): bool
    {
        return in_array($groupId, $this->groupIds, true);
    }

    /** Active in a section role (started, not ended), or in an org-role period that is active now. */
    private static function memberIsOfficial(int $memberId): bool
    {
        $inSectionRole = SectionRole::where('member_id', $memberId)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', today()))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', today()))
            ->exists();

        return $inSectionRole || OrganizationalRoleMember::where('member_id', $memberId)
            ->whereHas('period', fn ($q) => $q->where('status', OrganizationalRolePeriod::STATUS_ACTIVE))
            ->exists();
    }

    /**
     * Groups whose content the member may use now: an active membership, and
     * not suspended by a lapsed association membership (Section::canAccess()).
     *
     * @return list<int>
     */
    private static function activeGroupIds(Member $member): array
    {
        $memberships = SectionMembership::where('member_id', $member->id)
            ->where('status', SectionMembership::STATUS_ACTIVE)
            ->whereNull('left_at')
            ->with('section:id,membership_requires_active')
            ->get();

        $hasActivePeriod = null;

        return $memberships
            ->filter(function (SectionMembership $m) use ($member, &$hasActivePeriod) {
                if (! $m->section?->membership_requires_active) {
                    return $m->section !== null;
                }

                return $hasActivePeriod ??= $member->activePeriod() !== null;
            })
            ->map(fn (SectionMembership $m) => (int) $m->section_id)
            ->values()
            ->all();
    }
}
