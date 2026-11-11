<?php

namespace App\Models\Shop;

use App\Models\Shop\ProductVariant;
use App\Models\Events\EventPriceTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CartItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'cart_id',
        'cartable_type',
        'cartable_id',
        'quantity',
        'unit_price_ore',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'quantity'       => 'integer',
            'unit_price_ore' => 'integer',
            'meta'           => 'array',
        ];
    }

    public function lineTotalOre(): int
    {
        return $this->unit_price_ore * $this->quantity;
    }

    public function isProduct(): bool
    {
        return $this->cartable_type === ProductVariant::class;
    }

    public function isEventTicket(): bool
    {
        return $this->cartable_type === EventPriceTier::class;
    }

    /**
     * Human-readable label for display in cart/checkout.
     */
    public function displayLabel(): string
    {
        if ($this->isProduct()) {
            /** @var ProductVariant $variant */
            $variant = $this->cartable;

            return $variant->option_value
                ? "{$variant->product->name} ({$variant->option_value})"
                : $variant->product->name;
        }

        if ($this->isEventTicket()) {
            /** @var EventPriceTier $tier */
            $tier = $this->cartable;

            return "{$tier->event->title} — {$tier->label}";
        }

        return '—';
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function cartable(): MorphTo
    {
        return $this->morphTo();
    }
}
