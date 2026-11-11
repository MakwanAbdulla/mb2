<?php

namespace App\Models\Shop;

use App\Models\Shop\OrderLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrderFulfillment extends Model
{
    public const STATUS_PENDING          = 'pending';
    public const STATUS_PROCESSING       = 'processing';
    public const STATUS_SHIPPED          = 'shipped';
    public const STATUS_DELIVERED        = 'delivered';
    public const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';
    public const STATUS_PICKED_UP        = 'picked_up';
    public const STATUS_CANCELLED        = 'cancelled';

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'order_line_id',
        'status',
        'tracking_number',
        'shipped_at',
        'delivered_at',
        'ready_at',
        'picked_up_at',
        'notes',
        'item_token',
    ];

    protected static function booting(): void
    {
        static::creating(function (self $fulfillment) {
            if (empty($fulfillment->item_token)) {
                $fulfillment->item_token = strtolower(Str::random(32));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'shipped_at'   => 'datetime',
            'delivered_at' => 'datetime',
            'ready_at'     => 'datetime',
            'picked_up_at' => 'datetime',
        ];
    }

    public function isPending(): bool        { return $this->status === self::STATUS_PENDING; }
    public function isShipped(): bool        { return $this->status === self::STATUS_SHIPPED; }
    public function isDelivered(): bool      { return $this->status === self::STATUS_DELIVERED; }
    public function isReadyForPickup(): bool { return $this->status === self::STATUS_READY_FOR_PICKUP; }
    public function isPickedUp(): bool       { return $this->status === self::STATUS_PICKED_UP; }
    public function isCancelled(): bool      { return $this->status === self::STATUS_CANCELLED; }

    public function isComplete(): bool
    {
        return in_array($this->status, [
            self::STATUS_DELIVERED,
            self::STATUS_PICKED_UP,
        ]);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }
}
