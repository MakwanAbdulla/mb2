<?php

namespace App\Models\Shop;

use App\Models\Shop\OrderFulfillment;
use App\Models\Shop\DiscountUse;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OrderLine extends Model
{
    use HasFactory;

    const CATEGORY_MEMBERSHIP = 'membership';
    const CATEGORY_EVENT      = 'event';
    const CATEGORY_RENTAL     = 'rental';
    const CATEGORY_TAG        = 'tag';
    const CATEGORY_SHOP       = 'shop';

    protected $fillable = [
        'order_id',
        'category',
        'slot_role',
        'orderable_type',
        'orderable_id',
        'description',
        'unit_price_ore',
        'quantity',
        'discount_ore',
        'line_total_ore',
    ];

    protected function casts(): array
    {
        return [
            'category'       => 'string',
            'unit_price_ore' => 'integer',
            'quantity'       => 'integer',
            'discount_ore'   => 'integer',
            'line_total_ore' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderable(): MorphTo
    {
        return $this->morphTo();
    }

    public function discountUse(): MorphOne
    {
        return $this->morphOne(DiscountUse::class, 'discountable');
    }

    public function fulfillment(): HasOne
    {
        return $this->hasOne(OrderFulfillment::class);
    }
}
