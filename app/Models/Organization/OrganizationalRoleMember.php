<?php

namespace App\Models\Organization;

use App\Models\Organization\OrganizationalRolePeriod;
use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationalRoleMember extends Model
{
    /** @use HasFactory<\Database\Factories\OrganizationalRoleMemberFactory> */
    use HasFactory;

    const TRANSITION_INCOMING = 'incoming';
    const TRANSITION_CURRENT = 'current';
    const TRANSITION_OUTGOING = 'outgoing';

    /** @var list<string> */
    protected $fillable = [
        'organizational_role_period_id',
        'member_id',
        'title',
        'transition_role',
        'active_from',
        'active_until',
        'access_until',
    ];

    protected function casts(): array
    {
        return [
            'active_from'  => 'date',
            'active_until' => 'date',
            'access_until' => 'date',
        ];
    }

    public function hasExtendedAccess(): bool
    {
        return $this->access_until !== null && $this->access_until->isFuture();
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(OrganizationalRolePeriod::class, 'organizational_role_period_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
