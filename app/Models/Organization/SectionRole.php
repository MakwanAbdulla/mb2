<?php

namespace App\Models\Organization;

use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectionRole extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'section_id',
        'member_id',
        'title',
        'is_admin',
        'valid_from',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'is_admin'    => 'boolean',
            'valid_from'  => 'date',
            'valid_until' => 'date',
        ];
    }

    public function isActive(): bool
    {
        return $this->valid_until === null || $this->valid_until->isFuture();
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
