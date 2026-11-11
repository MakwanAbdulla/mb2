<?php

namespace App\Models\Meeting;

use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $meeting_id
 * @property int|null    $member_id
 * @property string|null $name
 * @property string|null $email
 * @property string      $role
 * @property bool        $invited
 * @property bool|null   $present
 * @property int|null    $proxy_for_member_id
 * @property bool        $voting_rights
 * @property \Carbon\Carbon|null $membership_verified_at
 * @property bool|null   $membership_valid
 * @property string|null $notes
 */
class MeetingAttendee extends Model
{
    protected $fillable = [
        'meeting_id', 'member_id', 'name', 'email', 'role',
        'invited', 'present', 'proxy_for_member_id', 'voting_rights',
        'membership_verified_at', 'membership_valid', 'notes',
    ];

    protected $casts = [
        'invited'                => 'boolean',
        'present'                => 'boolean',
        'voting_rights'          => 'boolean',
        'membership_valid'       => 'boolean',
        'membership_verified_at' => 'datetime',
    ];

    public const ROLES = [
        'attendee'      => 'Deltagare',
        'chair'         => 'Ordförande',
        'secretary'     => 'Sekreterare',
        'adjuster'      => 'Justerare',
        'vote_counter'  => 'Rösträknare',
        'proxy'         => 'Ombud',
        'adjunct'       => 'Adjungerad',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function proxyFor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'proxy_for_member_id');
    }

    public function displayName(): string
    {
        return $this->member?->full_name ?? $this->name ?? $this->email ?? '–';
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }
}
