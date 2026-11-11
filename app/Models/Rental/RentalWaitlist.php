<?php

namespace App\Models\Rental;

use App\Models\Rental\RentalItemType;
use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $rental_item_type_id
 * @property int         $member_id
 * @property string|null $notes
 * @property \Carbon\Carbon $created_at  Queue position is determined by created_at
 */
class RentalWaitlist extends Model
{
    protected $fillable = [
        'rental_item_type_id',
        'member_id',
        'notes',
    ];

    public function itemType(): BelongsTo
    {
        return $this->belongsTo(RentalItemType::class, 'rental_item_type_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
