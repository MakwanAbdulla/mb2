<?php

namespace App\Models\Booking;

use App\Models\Booking\BookingResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property string      $name
 * @property string      $slug
 * @property string|null $description
 * @property string      $color
 * @property bool        $active
 */
class BookingCategory extends Model
{
    protected $table = 'booking_categories';

    protected $fillable = ['name', 'slug', 'description', 'color', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function resources(): HasMany
    {
        return $this->hasMany(BookingResource::class, 'category_id');
    }
}
