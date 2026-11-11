<?php

namespace App\Models\Membership;

use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $year
 * @property int $price_ore
 */
class TagYearPrice extends Model
{
    protected $fillable = ['year', 'price_ore', 'annual_fee_ore'];

    public static function forYear(int $year): int
    {
        return static::where('year', $year)->value('price_ore')
            ?? (int) SystemSetting::get('tags.price_ore', 7500);
    }
}
