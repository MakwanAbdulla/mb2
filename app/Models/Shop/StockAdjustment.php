<?php

namespace App\Models\Shop;

use App\Models\Shop\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockAdjustment extends Model
{
    public const REASON_SALE            = 'sale';
    public const REASON_RETURN          = 'return';
    public const REASON_ADJUSTMENT      = 'adjustment';
    public const REASON_INVENTORY_COUNT = 'inventory_count';

    /** @var list<string> */
    protected $fillable = [
        'product_variant_id',
        'quantity_change',
        'reason',
        'reference_type',
        'reference_id',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'integer',
        ];
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
