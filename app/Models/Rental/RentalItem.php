<?php

namespace App\Models\Rental;

use App\Models\Rental\RentalItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int         $id
 * @property int         $rental_item_type_id
 * @property string      $label
 * @property bool        $is_available
 * @property string|null $notes
 * @property int         $sort_order
 */
class RentalItem extends Model
{
    protected $fillable = [
        'rental_item_type_id',
        'label',
        'is_available',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'is_available' => 'boolean',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(RentalItemType::class, 'rental_item_type_id');
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    public function activeRental(): HasOne
    {
        return $this->hasOne(Rental::class)->whereNull('ended_at');
    }

    public function isFree(): bool
    {
        return $this->is_available && ! $this->activeRental()->exists();
    }
}
