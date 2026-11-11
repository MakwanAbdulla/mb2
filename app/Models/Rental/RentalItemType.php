<?php

namespace App\Models\Rental;

use App\Models\Rental\RentalWaitlist;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property int         $price_per_year
 * @property int         $setup_fee_ore
 * @property bool        $is_active
 * @property bool        $is_admin_controlled
 * @property int         $sort_order
 */
class RentalItemType extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price_per_year',
        'setup_fee_ore',
        'is_active',
        'is_admin_controlled',
        'sort_order',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'is_admin_controlled' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(RentalItem::class);
    }

    public function waitlist(): HasMany
    {
        return $this->hasMany(RentalWaitlist::class)->orderBy('created_at');
    }

    public function yearPrices(): HasMany
    {
        return $this->hasMany(RentalTypePrice::class)->orderByDesc('year');
    }

    public function priceForYear(int $year): int
    {
        return $this->yearPrices->firstWhere('year', $year)?->price_ore
            ?? $this->price_per_year;
    }

    public static function allForYear(int $year): Collection
    {
        return static::where('is_active', true)
            ->with(['yearPrices' => fn ($q) => $q->where('year', $year)])
            ->orderBy('sort_order')
            ->get();
    }

    public function availableItems(): HasMany
    {
        return $this->hasMany(RentalItem::class)
            ->where('is_available', true)
            ->whereDoesntHave('activeRental');
    }

    public function formattedPrice(): string
    {
        return number_format($this->price_per_year / 100, 2, ',', ' ') . ' kr/år';
    }
}
