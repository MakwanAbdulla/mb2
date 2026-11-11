<?php

namespace App\Models\Events;

use App\Models\Events\EventRegistrationAddon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventAddon extends Model
{
    /** @use HasFactory<\Database\Factories\EventAddonFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'name',
        'description',
        'price_ore',
        'options',
        'max_count',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'options'    => 'array',
            'price_ore'  => 'integer',
        ];
    }

    public function hasVariants(): bool
    {
        return ! empty($this->options);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registrationAddons(): HasMany
    {
        return $this->hasMany(EventRegistrationAddon::class, 'addon_id');
    }
}
