<?php

namespace App\Models\Events;

use App\Models\Organization\Section;
use App\Models\Shop\CartItem;
use App\Models\Events\EventRegistration;
use App\Models\Events\EventOccurrence;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class EventPriceTier extends Model
{
    /** @use HasFactory<\Database\Factories\EventPriceTierFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'sort_order',
        'label',
        'description',
        'price_ore',
        'requires_membership',
        'access_section_id',
        'min_age',
        'max_age',
        'max_count',
    ];

    protected function casts(): array
    {
        return [
            'requires_membership' => 'boolean',
            'price_ore'           => 'integer',
        ];
    }

    public function isFree(): bool
    {
        return $this->price_ore === 0;
    }

    /** Alias for max_count for view compatibility. */
    public function getCapacityAttribute(): ?int
    {
        return $this->max_count;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function accessSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'access_section_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class, 'price_tier_id');
    }

    /**
     * The specific occurrences this tier covers.
     * Empty = covers all occurrences of the event.
     */
    public function occurrences(): BelongsToMany
    {
        return $this->belongsToMany(
            EventOccurrence::class,
            'event_price_tier_occurrences',
            'price_tier_id',
            'occurrence_id',
        );
    }

    /**
     * True if this tier is scoped to specific occurrences (not all).
     */
    public function isScopedToOccurrences(): bool
    {
        return $this->occurrences()->exists();
    }

    public function cartItems(): MorphMany
    {
        return $this->morphMany(CartItem::class, 'cartable');
    }
}
