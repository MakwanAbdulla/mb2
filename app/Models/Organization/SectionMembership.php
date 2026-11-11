<?php

namespace App\Models\Organization;

use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectionMembership extends Model
{
    const STATUS_ACTIVE  = 'active';
    const STATUS_PENDING = 'pending';

    /** @var list<string> */
    protected $fillable = [
        'section_id',
        'member_id',
        'status',
        'joined_at',
        'left_at',
        'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at'    => 'date',
            'left_at'      => 'date',
            'requested_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->left_at === null;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->left_at === null;
    }

    public function approve(): void
    {
        $this->update([
            'status'    => self::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
    }

    public function deny(): void
    {
        $this->update(['left_at' => now()]);
    }

    /** Mark this membership as ended (admin-initiated removal). */
    public function remove(): void
    {
        $this->update(['left_at' => now()]);
    }

    /** Remove all active memberships for a member in a section. */
    public static function removeActive(int $sectionId, int $memberId): void
    {
        self::where('section_id', $sectionId)
            ->where('member_id', $memberId)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
