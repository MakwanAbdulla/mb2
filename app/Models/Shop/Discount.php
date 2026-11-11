<?php

namespace App\Models\Shop;

use App\Models\Shop\DiscountUse;
use App\Models\Shop\DiscountCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Discount extends Model
{
    use HasFactory;

    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED      = 'fixed';

    public const APPLIES_MEMBERSHIP = 'membership';
    public const APPLIES_EVENT      = 'event';
    public const APPLIES_SHOP       = 'shop';
    public const APPLIES_ALL        = 'all';

    public const CONDITION_FIRST_MEMBERSHIP = 'first_membership';

    public const LOGO_ARMAGEDON = 'armagedon';
    public const LOGO_BSK       = 'bsk';
    public const LOGO_NONE      = 'none';

    protected $fillable = [
        'name',
        'type',
        'value',
        'applies_to',
        'membership_type',
        'requires_membership',
        'condition',
        'min_subtotal',
        'max_discount',
        'max_pct_of_total',
        'valid_from',
        'valid_until',
        'max_uses',
        'max_uses_per_user',
        'is_active',
        'applicable_type',
        'applicable_id',
        'logo',
    ];

    protected function casts(): array
    {
        return [
            'valid_from'          => 'date',
            'valid_until'         => 'date',
            'is_active'           => 'boolean',
            'is_single_use'       => 'boolean',
            'requires_membership' => 'boolean',
        ];
    }

    public function applicable(): MorphTo
    {
        return $this->morphTo();
    }

    public function codes(): HasMany
    {
        return $this->hasMany(DiscountCode::class);
    }

    public function uses(): HasMany
    {
        return $this->hasMany(DiscountUse::class);
    }

    /**
     * Check whether this discount is eligible for the given member (or null for guests).
     * Respects requires_membership: null = anyone, true = active/grace members only,
     * false = non-members only.
     */
    public function eligibleFor(?Member $member): bool
    {
        if ($this->requires_membership === null) {
            return true;
        }

        $isMember = $member?->hasFullAccess() ?? false;
        return $this->requires_membership === $isMember;
    }

    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->valid_from && now()->lt($this->valid_from)) {
            return false;
        }
        if ($this->valid_until && now()->gt($this->valid_until)) {
            return false;
        }
        if ($this->max_uses && $this->uses()->count() >= $this->max_uses) {
            return false;
        }
        return true;
    }

    /**
     * Calculate the discount amount in öre, respecting all caps.
     */
    public function calculate(int $subtotalOre): int
    {
        if ($this->min_subtotal && $subtotalOre < $this->min_subtotal) {
            return 0;
        }

        $discount = $this->type === self::TYPE_PERCENTAGE
            ? (int) round($subtotalOre * ($this->value / 100))
            : $this->value;

        if ($this->max_discount) {
            $discount = min($discount, $this->max_discount);
        }

        if ($this->max_pct_of_total) {
            $cap = (int) round($subtotalOre * ($this->max_pct_of_total / 100));
            $discount = min($discount, $cap);
        }

        return min($discount, $subtotalOre); // Never exceed the total
    }
}
