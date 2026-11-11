<?php

namespace App\Models\Shop;

use App\Models\Membership\Member;
use App\Models\Shop\DiscountCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DiscountUse extends Model
{
    protected $fillable = [
        'discount_id',
        'discount_code_id',
        'member_id',
        'discountable_type',
        'discountable_id',
        'original_amount',
        'discount_amount',
    ];

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function discountable(): MorphTo
    {
        return $this->morphTo();
    }
}
