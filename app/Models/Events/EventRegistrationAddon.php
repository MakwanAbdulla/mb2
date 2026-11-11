<?php

namespace App\Models\Events;

use App\Models\Events\EventAddon;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EventRegistrationAddon extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'registration_id',
        'addon_id',
        'option_value',
        'quantity',
        'item_token',
        'redeemed_at',
        'redeemed_by_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity'    => 'integer',
            'redeemed_at' => 'datetime',
        ];
    }

    protected static function booting(): void
    {
        static::creating(function (self $addon) {
            if (empty($addon->item_token)) {
                $addon->item_token = strtolower(Str::random(32));
            }
        });
    }

    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(EventAddon::class, 'addon_id');
    }

    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_id');
    }
}
