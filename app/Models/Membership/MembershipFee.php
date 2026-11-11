<?php

namespace App\Models\Membership;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property int         $year
 * @property string      $membership_type
 * @property int         $amount
 * @property int|null    $min_age
 * @property int|null    $max_age
 * @property int         $sort_order
 * @property bool        $is_active
 */
class MembershipFee extends Model
{
    protected $fillable = [
        'year',
        'membership_type',
        'amount',
        'min_age',
        'max_age',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];


    /**
     * Get all fees for a year, keyed by membership_type, ordered by sort_order.
     */
    public static function forYear(int $year): \Illuminate\Support\Collection
    {
        return static::where('year', $year)
            ->orderBy('sort_order')
            ->orderBy('membership_type')
            ->get()
            ->keyBy('membership_type');
    }

    /**
     * Get active fees for self-registration (excludes honorary), ordered for display.
     */
    public static function forRegistration(int $year): \Illuminate\Support\Collection
    {
        return static::where('year', $year)
            ->where('is_active', true)
            ->whereIn('membership_type', Member::SELF_REGISTERABLE_TYPES)
            ->orderBy('sort_order')
            ->orderBy('membership_type')
            ->get();
    }

    public function formattedAmount(): string
    {
        return number_format($this->amount / 100, 2, ',', ' ') . ' kr';
    }

    /**
     * Age range label, e.g. "0–20 år" or "65+ år" or null if no bounds.
     */
    public function ageLabel(): ?string
    {
        if ($this->min_age !== null && $this->max_age !== null) {
            return "{$this->min_age}–{$this->max_age} år";
        }
        if ($this->min_age !== null) {
            return "{$this->min_age}+ år";
        }
        if ($this->max_age !== null) {
            return "0–{$this->max_age} år";
        }
        return null;
    }
}
