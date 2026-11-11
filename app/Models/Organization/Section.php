<?php

namespace App\Models\Organization;

use App\Models\Booking\BookingActivity;
use App\Models\Membership\Member;
use App\Models\SectionPost;
use App\Models\Organization\SectionMembership;
use App\Models\Organization\SectionRole;
use App\Models\Tag;
use App\Models\Wiki\WikiSpace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\WorkspaceAccount;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;

class Section extends Model
{
    /** @use HasFactory<\Database\Factories\SectionFactory> */
    use HasFactory;

    // Visibility: who can see the group
    const VISIBILITY_PUBLIC  = 'public';   // any logged-in user (account required)
    const VISIBILITY_MEMBERS = 'members';  // only active association members
    const VISIBILITY_PRIVATE = 'private';  // only group members (group appears in listings)
    const VISIBILITY_SECRET  = 'secret';   // only group members + admins (invisible to others)

    // Membership type: how one joins
    const MEMBERSHIP_MANDATORY   = 'mandatory';    // all active members auto-added
    const MEMBERSHIP_OPEN        = 'open';          // self-join, no approval needed
    const MEMBERSHIP_APPLICATION = 'application';   // apply, requires approval
    const MEMBERSHIP_CLOSED      = 'closed';        // group admin adds/removes
    const MEMBERSHIP_ADMIN_ONLY  = 'admin_only';    // only site admin can manage

    /** @var list<string> */
    protected $fillable = [
        'parent_id',
        'activity_id',
        'name',
        'slug',
        'description',
        'is_active',
        'sort_order',
        'visibility',
        'membership_type',
        'membership_requires_active',
        'accepts_issues',
    ];

    protected function casts(): array
    {
        return [
            'is_active'                  => 'boolean',
            'accepts_issues'             => 'boolean',
            'sort_order'                 => 'integer',
            'membership_requires_active' => 'boolean',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SectionPost::class)->orderByDesc('is_pinned')->orderByDesc('created_at');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Section::class, 'parent_id')->orderBy('sort_order');
    }

    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public function depth(): int
    {
        $depth = 0;
        $current = $this;
        while ($current->parent_id !== null) {
            $depth++;
            $current = $current->parent()->first();
            if (! $current) break;
        }
        return $depth;
    }

    public function canHaveChildren(): bool
    {
        $maxDepth = \App\Models\SystemSetting::get('sections.max_depth', 1);
        return $this->depth() < (int) $maxDepth;
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'section_memberships')
            ->withPivot(['status', 'joined_at', 'left_at', 'requested_at'])
            ->withTimestamps();
    }

    /** Active (approved) members still in the group */
    public function activeMembers(): BelongsToMany
    {
        return $this->members()
            ->wherePivot('status', SectionMembership::STATUS_ACTIVE)
            ->wherePivotNull('left_at');
    }

    /** Pending membership requests (application-type groups) */
    public function pendingMembers(): BelongsToMany
    {
        return $this->members()
            ->wherePivot('status', SectionMembership::STATUS_PENDING)
            ->wherePivotNull('left_at');
    }

    public function sectionMemberships(): HasMany
    {
        return $this->hasMany(SectionMembership::class);
    }

    public function pendingMemberships(): HasMany
    {
        return $this->sectionMemberships()
            ->where('status', SectionMembership::STATUS_PENDING)
            ->whereNull('left_at');
    }

    public function sectionRoles(): HasMany
    {
        return $this->hasMany(SectionRole::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(BookingActivity::class, 'activity_id');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Can the given member (or null = guest) see this group in listings?
     */
    public function canView(?Member $member = null): bool
    {
        return match ($this->visibility) {
            self::VISIBILITY_PUBLIC  => $member !== null,
            self::VISIBILITY_MEMBERS => $member !== null && $member->activePeriod() !== null,
            self::VISIBILITY_PRIVATE,
            self::VISIBILITY_SECRET  => $member !== null && $this->activeMembers()->where('member_id', $member->id)->exists(),
            default                  => false,
        };
    }

    /**
     * Can the given member access (use) this group?
     * A member with a lapsed association membership stays in the group but access is suspended
     * if membership_requires_active is true.
     */
    public function canAccess(Member $member): bool
    {
        $membership = SectionMembership::where('section_id', $this->id)
            ->where('member_id', $member->id)
            ->where('status', SectionMembership::STATUS_ACTIVE)
            ->whereNull('left_at')
            ->exists();

        if (! $membership) {
            return false;
        }

        if ($this->membership_requires_active && $member->activePeriod() === null) {
            return false;
        }

        return true;
    }

    /**
     * Is this member's access currently suspended due to lapsed association membership?
     */
    public function membershipLapsed(Member $member): bool
    {
        $inGroup = SectionMembership::where('section_id', $this->id)
            ->where('member_id', $member->id)
            ->where('status', SectionMembership::STATUS_ACTIVE)
            ->whereNull('left_at')
            ->exists();

        return $inGroup
            && $this->membership_requires_active
            && $member->activePeriod() === null;
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(\App\Models\Meeting\Meeting::class);
    }

    public function workspaceAccount(): MorphOne
    {
        return $this->morphOne(WorkspaceAccount::class, 'accountable');
    }

    public function wikiSpace(): HasOne
    {
        return $this->hasOne(WikiSpace::class, 'group_id');
    }

    /** A group whose wiki holds pages can't be deleted: the knowledge would go with it. */
    public function hasWikiPages(): bool
    {
        return $this->wikiSpace?->pages()->withTrashed()->exists() ?? false;
    }

    /**
     * The group and its empty wiki space (SectionObserver::deleting) go together:
     * if the group can't be deleted, the space stays.
     */
    public function delete(): ?bool
    {
        return DB::transaction(fn () => parent::delete());
    }
}
