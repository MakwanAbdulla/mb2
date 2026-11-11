<?php

namespace App\Models\Rental;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $rental_item_type_id
 * @property int $year
 * @property int $price_ore
 */
class RentalTypePrice extends Model
{
    protected $fillable = [
        'rental_item_type_id',
        'year',
        'price_ore',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(RentalItemType::class, 'rental_item_type_id');
    }
}
