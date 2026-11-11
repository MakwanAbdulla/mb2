<?php

namespace App\Models\Shop;

use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscountCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'discount_id',
        'code',
        'is_single_use',
        'max_uses',
        'batch_label',
        'redeemed_by_member_id',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_single_use' => 'boolean',
            'redeemed_at'   => 'datetime',
        ];
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'redeemed_by_member_id');
    }

    public function uses(): HasMany
    {
        return $this->hasMany(DiscountUse::class);
    }

    /**
     * Whether this code has been fully consumed and can no longer be applied.
     * Requires uses_count to be eager-loaded for accurate limited-code results.
     */
    public function isUsedUp(): bool
    {
        if ($this->is_single_use) {
            return $this->redeemed_at !== null;
        }
        if ($this->max_uses !== null) {
            return ($this->uses_count ?? $this->uses()->count()) >= $this->max_uses;
        }
        return false;
    }

    public function isAvailable(): bool
    {
        if ($this->isUsedUp()) {
            return false;
        }
        return $this->discount->isValid();
    }

    /**
     * Scope: codes that are not fully consumed.
     * Works in SQL without needing withCount.
     */
    public function scopeAvailable(Builder $query): void
    {
        $query
            // Single-use: not yet redeemed
            ->where(fn ($q) => $q->where('is_single_use', false)->orWhereNull('redeemed_at'))
            // Limited multi-use: uses below cap
            ->where(fn ($q) => $q->whereNull('max_uses')
                ->orWhereRaw(
                    '(SELECT COUNT(*) FROM discount_uses WHERE discount_code_id = discount_codes.id) < discount_codes.max_uses'
                ));
    }

    /** Mark a single-use code as redeemed. No-op for shared/limited codes (tracked via discount_uses). */
    public function redeem(?Member $member = null): void
    {
        if (! $this->is_single_use) {
            return;
        }

        $this->update([
            'redeemed_at'           => now(),
            'redeemed_by_member_id' => $member?->id,
        ]);
    }
}
