<?php

namespace App\Models\Gamification;

use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XpLog extends Model
{
    public $timestamps = false;

    protected $table = 'member_xp_logs';

    protected $fillable = [
        'member_id',
        'giver_member_id',
        'source_type',
        'source_id',
        'points',
        'note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public const string SOURCE_MEMBERSHIP_RENEWAL  = 'membership_renewal';
    public const string SOURCE_EVENT_CHECKIN       = 'event_checkin';
    public const string SOURCE_EVENT_REGISTRATION  = 'event_registration';
    public const string SOURCE_PROFILE_AVATAR      = 'profile_avatar';
    public const string SOURCE_PROFILE_COMPLETE    = 'profile_complete';
    public const string SOURCE_PEER_KUDOS          = 'peer_kudos';
    public const string SOURCE_BOOKING             = 'booking';
    public const string SOURCE_TASK                       = 'task';
    public const string SOURCE_TOURNAMENT_PLACEMENT       = 'tournament_placement';

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function giver(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'giver_member_id');
    }
}
