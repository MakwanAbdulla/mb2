<?php

namespace App\Models\Booking;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 * @property int    $sort_order
 * @property bool   $active
 */
class BookingActivity extends Model
{
    protected $table = 'booking_activities';

    protected $fillable = ['name', 'sort_order', 'active'];

    protected $casts = ['active' => 'boolean'];

    /** @return array<int, string> */
    public static function activeNames(): array
    {
        return static::where('active', true)->orderBy('name')->pluck('name')->all();
    }
}
