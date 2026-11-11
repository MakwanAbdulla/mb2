<?php

namespace App\Models\Shop;

use App\Models\Shop\StockAdjustment;
use App\Models\Shop\OrderLine;
use App\Models\Shop\CartItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProductVariant extends Model
{
    /** @use HasFactory<\Database\Factories\ProductVariantFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'option_value',
        'stock',
        'sku',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
        ];
    }

    // ── Stock helpers ──────────────────────────────────────────────────────────

    public function isInStock(): bool
    {
        if (! $this->product->track_stock) {
            return true;
        }

        return $this->stock === null || $this->stock > 0;
    }

    public function isInStockFor(int $quantity): bool
    {
        if (! $this->product->track_stock) {
            return true;
        }

        if ($this->stock === null) {
            return true;
        }

        return $this->stock >= $quantity;
    }

    /**
     * Decrement stock and record a stock_adjustment.
     * Only called when product->track_stock is true.
     */
    public function decrementStock(int $quantity, ?int $orderLineId = null): void
    {
        if (! $this->product->track_stock || $this->stock === null) {
            return;
        }

        $this->decrement('stock', $quantity);

        $this->stockAdjustments()->create([
            'quantity_change'  => -$quantity,
            'reason'           => StockAdjustment::REASON_SALE,
            'reference_type'   => $orderLineId ? OrderLine::class : null,
            'reference_id'     => $orderLineId,
            'created_by'       => auth()->id() ?? 1,
        ]);
    }

    /**
     * Display label — blank for products without variants.
     */
    public function label(): string
    {
        return $this->option_value ?: $this->product->name;
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class);
    }

    public function cartItems(): MorphMany
    {
        return $this->morphMany(CartItem::class, 'cartable');
    }
}
