<?php

namespace App\Models\Membership;

use App\Models\Shop\OrderLine;
use App\Models\Shop\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int              $id
 * @property int              $member_id
 * @property string|null      $tag_uid
 * @property string           $status
 * @property int              $price_ore
 * @property int|null         $order_id
 * @property \Carbon\Carbon|null $issued_at
 * @property \Carbon\Carbon|null $deactivated_at
 * @property string|null      $notes
 * @property string           $paxton_sync_status
 * @property string|null      $paxton_sync_error
 * @property \Carbon\Carbon|null $paxton_synced_at
 */
class MemberTag extends Model
{
    // Status values
    public const STATUS_ORDERED     = 'ordered';
    public const STATUS_ACTIVE      = 'active';
    public const STATUS_LOST        = 'lost';
    public const STATUS_BROKEN      = 'broken';
    public const STATUS_DEACTIVATED = 'deactivated';

    public const STATUSES = [
        self::STATUS_ORDERED,
        self::STATUS_ACTIVE,
        self::STATUS_LOST,
        self::STATUS_BROKEN,
        self::STATUS_DEACTIVATED,
    ];

    // Paxton sync status
    public const PAXTON_PENDING = 'pending';
    public const PAXTON_SYNCED  = 'synced';
    public const PAXTON_FAILED  = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'member_id',
        'tag_uid',
        'status',
        'price_ore',
        'order_id',
        'issued_at',
        'deactivated_at',
        'notes',
        'paxton_sync_status',
        'paxton_sync_error',
        'paxton_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at'       => 'datetime',
            'deactivated_at'  => 'datetime',
            'paxton_synced_at' => 'datetime',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderLines(): MorphMany
    {
        return $this->morphMany(OrderLine::class, 'orderable');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDeactivated(): bool
    {
        return in_array($this->status, [
            self::STATUS_LOST,
            self::STATUS_BROKEN,
            self::STATUS_DEACTIVATED,
        ]);
    }

    public function formattedPrice(): string
    {
        return number_format($this->price_ore / 100, 2, ',', ' ') . ' kr';
    }

    /** Label shown in the UI for each status. */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_ORDERED     => 'Beställd',
            self::STATUS_ACTIVE      => 'Aktiv',
            self::STATUS_LOST        => 'Borttappad',
            self::STATUS_BROKEN      => 'Trasig',
            self::STATUS_DEACTIVATED => 'Avaktiverad',
            default                  => $status,
        };
    }

    /**
     * Add this tag as a line on an existing order.
     */
    public function addLineToOrder(Order $order): OrderLine
    {
        $line = OrderLine::create([
            'order_id'       => $order->id,
            'category'       => OrderLine::CATEGORY_TAG,
            'orderable_type' => self::class,
            'orderable_id'   => $this->id,
            'description'    => 'RFID-tagg',
            'unit_price_ore' => $this->price_ore,
            'quantity'       => 1,
            'discount_ore'   => 0,
            'line_total_ore' => $this->price_ore,
        ]);

        $order->increment('total_ore', $this->price_ore);
        $this->update(['order_id' => $order->id]);

        return $line;
    }

    /**
     * Create a standalone order for this tag (called when admin assigns a UID and activates).
     */
    public function createOrder(): Order
    {
        $this->loadMissing('member.user');

        $order = Order::create([
            'user_id'   => $this->member->user?->id,
            'total_ore' => $this->price_ore,
        ]);

        OrderLine::create([
            'order_id'       => $order->id,
            'category'       => OrderLine::CATEGORY_TAG,
            'orderable_type' => self::class,
            'orderable_id'   => $this->id,
            'description'    => 'RFID-tagg' . ($this->tag_uid ? ' (' . $this->tag_uid . ')' : ''),
            'unit_price_ore' => $this->price_ore,
            'quantity'       => 1,
            'discount_ore'   => 0,
            'line_total_ore' => $this->price_ore,
        ]);

        $this->update(['order_id' => $order->id]);

        return $order;
    }

    /**
     * Mark as pending sync so the Paxton agent picks it up.
     */
    public function queuePaxtonSync(): void
    {
        $this->update(['paxton_sync_status' => self::PAXTON_PENDING]);
    }
}
