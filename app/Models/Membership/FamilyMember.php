<?php

namespace App\Models\Membership;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot for family_members. Exists so invited_at comes back as a Carbon
 * instance — the views format it with translatedFormat() (#251).
 */
class FamilyMember extends Pivot
{
    protected $table = 'family_members';

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
        ];
    }
}
