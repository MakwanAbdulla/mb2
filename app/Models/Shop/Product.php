<?php

namespace App\Models\Shop;

use App\Models\Shop\ProductVariant;
use App\Models\Shop\ProductDimension;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory, SoftDeletes;

    public const TYPE_PHYSICAL = 'physical';
    public const TYPE_DIGITAL  = 'digital';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED  = 'archived';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'status',
        'price_ore',
        'member_price_ore',
        'requires_membership',
        'show_in_event_checkout',
        'pickup_only',
        'track_stock',
        'weight_grams',
        'length_mm',
        'width_mm',
        'height_mm',
        'digital_file_path',
        'image_path',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_ore'              => 'integer',
            'member_price_ore'       => 'integer',
            'requires_membership'    => 'boolean',
            'show_in_event_checkout' => 'boolean',
            'pickup_only'            => 'boolean',
            'track_stock'            => 'boolean',
        ];
    }

    protected static function booting(): void
    {
        static::creating(function (self $product) {
            if (empty($product->slug)) {
                $product->slug = static::uniqueSlug($product->name);
            }
        });

        // Create default variant for products without dimension variants
        static::created(function (self $product) {
            if ($product->variants()->doesntExist()) {
                $product->variants()->create(['option_value' => '', 'sort_order' => 0]);
            }
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 2;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    // ── Status helpers ─────────────────────────────────────────────────────────

    public function isDraft(): bool     { return $this->status === self::STATUS_DRAFT; }
    public function isPublished(): bool { return $this->status === self::STATUS_PUBLISHED; }
    public function isArchived(): bool  { return $this->status === self::STATUS_ARCHIVED; }
    public function isPhysical(): bool   { return $this->type === self::TYPE_PHYSICAL; }
    public function isDigital(): bool    { return $this->type === self::TYPE_DIGITAL; }
    public function isPickupOnly(): bool { return (bool) $this->pickup_only; }

    public function hasVariants(): bool
    {
        return $this->dimension()->exists();
    }

    public function hasMemberPrice(): bool
    {
        return $this->member_price_ore !== null;
    }

    /**
     * Effective price for a buyer. Variants carry no price adjustment.
     */
    public function priceFor(bool $isMember = false): int
    {
        if ($isMember && $this->member_price_ore !== null) {
            return $this->member_price_ore;
        }

        return $this->price_ore;
    }

    /**
     * Default variant for products without a dimension.
     */
    public function defaultVariant(): ?ProductVariant
    {
        return $this->variants()->where('option_value', '')->first();
    }

    /**
     * Check if any variant has stock available (or stock is not tracked).
     */
    public function isAvailable(): bool
    {
        if (! $this->track_stock) {
            return true;
        }

        return $this->variants()->where(function ($q) {
            $q->whereNull('stock')->orWhere('stock', '>', 0);
        })->exists();
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function dimension(): HasOne
    {
        return $this->hasOne(ProductDimension::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }
}
