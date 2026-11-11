<?php

namespace App\Models\Membership;

use App\Models\Membership\MembershipPeriod;
use App\Models\Membership\Member;
use App\Models\Membership\FamilyGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyMemberHistory extends Model
{
    public const ACTION_JOINED   = 'joined';
    public const ACTION_ADDED    = 'added';
    public const ACTION_LEFT     = 'left';
    public const ACTION_REMOVED  = 'removed';
    public const ACTION_INVITED  = 'invited';
    public const ACTION_DECLINED = 'declined';

    public $timestamps = false;

    protected $table = 'family_member_history';

    protected $fillable = [
        'family_group_id',
        'member_id',
        'action',
        'performed_by_user_id',
        'year',
        'membership_period_id',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'year'       => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function familyGroup(): BelongsTo
    {
        return $this->belongsTo(FamilyGroup::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    public function membershipPeriod(): BelongsTo
    {
        return $this->belongsTo(MembershipPeriod::class);
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_JOINED   => 'Gick med',
            self::ACTION_ADDED    => 'Tillagd',
            self::ACTION_LEFT     => 'Lämnade',
            self::ACTION_REMOVED  => 'Borttagen',
            self::ACTION_INVITED  => 'Inbjuden',
            self::ACTION_DECLINED => 'Avböjde',
            default              => $this->action,
        };
    }
}
