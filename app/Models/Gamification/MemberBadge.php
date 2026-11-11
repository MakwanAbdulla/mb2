<?php

namespace App\Models\Gamification;

use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberBadge extends Model
{
    public $timestamps = false;

    protected $table = 'member_badges';

    protected $fillable = [
        'member_id',
        'badge_key',
        'awarded_at',
    ];

    protected function casts(): array
    {
        return [
            'awarded_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
