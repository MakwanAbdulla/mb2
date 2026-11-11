<?php

namespace App\Models\Membership;

use App\Models\Gamification\MemberBadge;
use App\Models\Gamification\XpLog;
use App\Models\Rental\RentalWaitlist;
use App\Models\Rental\Rental;
use App\Models\Organization\SectionRole;
use App\Models\Organization\Section;
use App\Models\Organization\OrganizationalRoleMember;
use App\Models\Membership\MembershipPeriod;
use App\Models\Membership\MemberTag;
use App\Models\Membership\FamilyGroup;
use App\Models\Membership\AccountDeletionRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkspaceAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Member extends Model
{
    /** @use HasFactory<\Database\Factories\MemberFactory> */
    use HasFactory, SoftDeletes;

    // Status
    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_EXPIRED  = 'expired';
    public const STATUS_DECEASED = 'deceased';
    public const STATUS_DELETED  = 'deleted';   // GDPR erasure — data anonymised

    // Gender
    public const GENDER_FEMALE = 'female';
    public const GENDER_MALE   = 'male';
    public const GENDER_OTHER  = 'other';

    // Membership type
    public const TYPE_SINGLE   = 'single';
    public const TYPE_FAMILY   = 'family';
    public const TYPE_YOUTH    = 'youth';
    public const TYPE_CHILD    = 'child';
    public const TYPE_STUDENT  = 'student';
    public const TYPE_SENIOR   = 'senior';
    public const TYPE_HONORARY = 'honorary';

    /** Types that members can self-register with (admin controls the rest) */
    public const SELF_REGISTERABLE_TYPES = [
        self::TYPE_SINGLE,
        self::TYPE_FAMILY,
        self::TYPE_YOUTH,
        self::TYPE_CHILD,
        self::TYPE_STUDENT,
        self::TYPE_SENIOR,
    ];

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'family_group_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'postal_code',
        'city',
        'date_of_birth',
        'gender',
        'membership_type',
        'status',
        'cogworks_id',
        'membership_number',
        'notes',
    ];

    protected static function booting(): void
    {
        static::creating(function (self $member) {
            if (empty($member->membership_number)) {
                $member->membership_number = static::nextMembershipNumber();
            }
        });
    }

    public static function nextMembershipNumber(): string
    {
        $driver = \Illuminate\Support\Facades\DB::getDriverName();

        // MAX() on a varchar column compares lexicographically ('9' > '10'),
        // so every crossing of a power-of-ten boundary (9→10, 99→100, ...)
        // would otherwise hand out an already-taken number. Cast inside the
        // aggregate so the comparison is numeric, not textual.
        $castType = $driver === 'sqlite' ? 'INTEGER' : 'UNSIGNED';

        $max = static::withTrashed()
            ->whereNotNull('membership_number')
            ->when(
                $driver === 'sqlite',
                fn ($q) => $q->whereRaw("membership_number GLOB '[0-9]*'")
                             ->whereRaw("CAST(membership_number AS INTEGER) > 0"),
                fn ($q) => $q->whereRaw("membership_number REGEXP '^[0-9]+$'"),
            )
            ->max(\Illuminate\Support\Facades\DB::raw("CAST(membership_number AS {$castType})"));

        return (string) ((int) $max + 1);
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function notificationEmail(): ?string
    {
        return $this->user?->notificationEmail() ?? $this->email;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Full access = active member, OR returning member within the grace period.
     * Grace period: had an active period last year, current year not yet paid,
     * but we are still within Jan 1 + grace_period_months.
     */
    public function hasFullAccess(): bool
    {
        if ($this->isActive()) return true;
        if ($this->status === self::STATUS_DECEASED) return false;

        $graceMonths = (int) SystemSetting::get('membership.grace_period_months', 1);
        if ($graceMonths < 1) return false;

        $graceEnds = now()->startOfYear()->addMonths($graceMonths);
        if (now()->gte($graceEnds)) return false;

        return $this->membershipPeriods()
            ->where('year', now()->year - 1)
            ->where('status', MembershipPeriod::STATUS_ACTIVE)
            ->exists();
    }

    /**
     * True if the member should see a "renew for next year" CTA.
     */
    public function shouldShowRenewal(): bool
    {
        $renewalMonth = (int) SystemSetting::get('membership.renewal_visible_month', 12);
        if (now()->month < $renewalMonth) return false;

        $nextYear = now()->year + 1;

        return ! $this->membershipPeriods()
            ->where('year', $nextYear)
            ->whereIn('status', [MembershipPeriod::STATUS_ACTIVE, MembershipPeriod::STATUS_PENDING_PAYMENT])
            ->exists();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function familyGroup(): BelongsTo
    {
        return $this->belongsTo(FamilyGroup::class);
    }

    public function membershipPeriods(): HasMany
    {
        return $this->hasMany(MembershipPeriod::class);
    }

    public function activePeriod(): ?MembershipPeriod
    {
        return $this->membershipPeriods()
            ->where('status', MembershipPeriod::STATUS_ACTIVE)
            ->where('year', now()->year)
            ->first();
    }

    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(Section::class, 'section_memberships')
            ->withPivot(['status', 'joined_at', 'left_at', 'requested_at'])
            ->withTimestamps();
    }

    public function sectionRoles(): HasMany
    {
        return $this->hasMany(SectionRole::class);
    }

    public function organizationalRoleMemberships(): HasMany
    {
        return $this->hasMany(OrganizationalRoleMember::class);
    }

    public function deletionRequest(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(AccountDeletionRequest::class)->latest();
    }

    /**
     * Anonymise personal data in compliance with GDPR Article 17.
     * Keeps the record (with membership_number) for accounting/audit purposes.
     * Soft-deletes the associated User account.
     */
    public function anonymize(): void
    {
        $this->update([
            'first_name'    => 'Raderad',
            'last_name'     => 'Användare',
            'email'         => null,
            'phone'         => null,
            'address'       => null,
            'postal_code'   => null,
            'city'          => null,
            'date_of_birth' => null,
            'cogworks_id'   => null,
            'notes'         => null,
            'status'        => self::STATUS_DELETED,
        ]);

        $this->user?->delete(); // soft-delete the login account
    }

    /** Mark the member as expired (no active membership for current year). */
    public function expire(): void
    {
        $this->update(['status' => self::STATUS_EXPIRED]);

        if ($this->workspaceAccount) {
            app(\App\Services\GoogleWorkspaceService::class)->deprovision($this);
        }
    }

    public function workspaceAccount(): MorphOne
    {
        return $this->morphOne(WorkspaceAccount::class, 'accountable');
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    public function activeRentals(): HasMany
    {
        return $this->hasMany(Rental::class)->whereNull('ended_at');
    }

    public function rentalWaitlist(): HasMany
    {
        return $this->hasMany(RentalWaitlist::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(MemberTag::class);
    }

    public function activeTag(): ?MemberTag
    {
        return $this->tags()->where('status', MemberTag::STATUS_ACTIVE)->latest()->first();
    }

    public function xpLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(XpLog::class);
    }

    public function badges(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MemberBadge::class);
    }
}
