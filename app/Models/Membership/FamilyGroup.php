<?php

namespace App\Models\Membership;

use App\Models\User;
use App\Models\Membership\Member;
use App\Models\Membership\FamilyMemberHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;


class FamilyGroup extends Model
{
    /** @use HasFactory<\Database\Factories\FamilyGroupFactory> */
    use HasFactory;

    public const ROLE_ADULT = 'adult';
    public const ROLE_CHILD = 'child';

    public const STATUS_ACTIVE  = 'active';
    public const STATUS_PENDING = 'pending';

    /** @var list<string> */
    protected $fillable = [
        'administrator_member_id',
    ];

    public function administrator(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'administrator_member_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function membersWithPivot(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'family_members')
            ->using(FamilyMember::class)
            ->withPivot(['role', 'has_own_account', 'status', 'invitation_token', 'invited_at'])
            ->wherePivot('status', self::STATUS_ACTIVE);
    }

    public function pendingInvitations(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'family_members')
            ->using(FamilyMember::class)
            ->withPivot(['role', 'has_own_account', 'status', 'invitation_token', 'invited_at'])
            ->wherePivot('status', self::STATUS_PENDING);
    }

    public function adults(): BelongsToMany
    {
        return $this->membersWithPivot()->wherePivot('role', self::ROLE_ADULT);
    }

    public function children(): BelongsToMany
    {
        return $this->membersWithPivot()->wherePivot('role', self::ROLE_CHILD);
    }

    /** Returns true if the given member is an active adult in this family (can manage the group). */
    public function isManagedBy(Member $member): bool
    {
        return DB::table('family_members')
            ->where('family_group_id', $this->id)
            ->where('member_id', $member->id)
            ->where('role', self::ROLE_ADULT)
            ->where('status', self::STATUS_ACTIVE)
            ->exists();
    }

    /** Returns true if the given member is the billing contact for this family. */
    public function isBillingContact(Member $member): bool
    {
        return $this->administrator_member_id === $member->id;
    }

    public function history(): HasMany
    {
        return $this->hasMany(FamilyMemberHistory::class)->orderByDesc('created_at');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Membership mutations — always log history automatically
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Add a member to this family group with a given role.
     * Optionally attach a MembershipPeriod that was allocated as a free slot.
     */
    public function addMember(
        Member   $member,
        string   $role,
        bool     $hasOwnAccount,
        ?User    $performedBy = null,
        ?MembershipPeriod $period = null,
        ?string  $notes = null,
    ): void {
        $member->update(['family_group_id' => $this->id]);

        $this->membersWithPivot()->attach($member->id, [
            'role'            => $role,
            'has_own_account' => $hasOwnAccount,
        ]);

        FamilyMemberHistory::create([
            'family_group_id'      => $this->id,
            'member_id'            => $member->id,
            'action'               => FamilyMemberHistory::ACTION_ADDED,
            'performed_by_user_id' => $performedBy?->id,
            'year'                 => now()->year,
            'membership_period_id' => $period?->id,
            'notes'                => $notes,
        ]);
    }

    /**
     * Send an invitation to an existing member (has own account).
     * Sets family_group_id immediately so the member is excluded from searches.
     * Returns the invitation token for inclusion in the notification email.
     */
    public function inviteMember(
        Member  $member,
        string  $role,
        ?User   $performedBy = null,
    ): string {
        $token = Str::random(64);

        $member->update(['family_group_id' => $this->id]);

        $this->belongsToMany(Member::class, 'family_members')
            ->withPivot(['role', 'has_own_account', 'status', 'invitation_token', 'invited_at'])
            ->attach($member->id, [
                'role'             => $role,
                'has_own_account'  => true,
                'status'           => self::STATUS_PENDING,
                'invitation_token' => $token,
                'invited_at'       => now(),
            ]);

        FamilyMemberHistory::create([
            'family_group_id'      => $this->id,
            'member_id'            => $member->id,
            'action'               => FamilyMemberHistory::ACTION_INVITED,
            'performed_by_user_id' => $performedBy?->id,
            'year'                 => now()->year,
        ]);

        return $token;
    }

    /**
     * Accept a pending invitation — promotes the pivot row to active.
     */
    public function acceptInvitation(Member $member): void
    {
        $this->belongsToMany(Member::class, 'family_members')
            ->updateExistingPivot($member->id, [
                'status'           => self::STATUS_ACTIVE,
                'invitation_token' => null,
                'invited_at'       => null,
            ]);

        FamilyMemberHistory::create([
            'family_group_id'      => $this->id,
            'member_id'            => $member->id,
            'action'               => FamilyMemberHistory::ACTION_JOINED,
            'performed_by_user_id' => $member->user_id,
            'year'                 => now()->year,
        ]);
    }

    /**
     * Cancel or decline a pending invitation — removes the pivot row and clears family_group_id.
     */
    public function cancelInvitation(Member $member, ?User $performedBy = null): void
    {
        FamilyMemberHistory::create([
            'family_group_id'      => $this->id,
            'member_id'            => $member->id,
            'action'               => FamilyMemberHistory::ACTION_DECLINED,
            'performed_by_user_id' => $performedBy?->id,
            'year'                 => now()->year,
        ]);

        $this->belongsToMany(Member::class, 'family_members')->detach($member->id);
        $member->update(['family_group_id' => null]);
    }

    /**
     * Remove a member from this family group (admin-initiated).
     * Their membership period for the current year is left untouched.
     */
    public function removeMember(Member $member, ?User $performedBy = null, ?string $notes = null): void
    {
        // Log before detach so the member record is still reachable
        FamilyMemberHistory::create([
            'family_group_id'      => $this->id,
            'member_id'            => $member->id,
            'action'               => FamilyMemberHistory::ACTION_REMOVED,
            'performed_by_user_id' => $performedBy?->id,
            'year'                 => now()->year,
            'notes'                => $notes,
        ]);

        $this->membersWithPivot()->detach($member->id);
        $member->update(['family_group_id' => null]);
    }

    /**
     * Member voluntarily leaves this family group.
     * Their membership period for the current year is left untouched.
     */
    public function leave(Member $member, ?User $performedBy = null): void
    {
        FamilyMemberHistory::create([
            'family_group_id'      => $this->id,
            'member_id'            => $member->id,
            'action'               => FamilyMemberHistory::ACTION_LEFT,
            'performed_by_user_id' => $performedBy?->id,
            'year'                 => now()->year,
        ]);

        $this->membersWithPivot()->detach($member->id);
        $member->update(['family_group_id' => null]);
    }
}
