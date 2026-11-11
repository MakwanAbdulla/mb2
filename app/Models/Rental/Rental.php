<?php

namespace App\Models\Rental;

use App\Models\Rental\RentalItem;
use App\Models\Membership\Member;
use App\Models\Shop\OrderLine;
use App\Models\Shop\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int                  $id
 * @property int                  $rental_item_id
 * @property int                  $member_id
 * @property int                  $price_per_year
 * @property \Carbon\Carbon       $started_at
 * @property \Carbon\Carbon|null  $ended_at
 * @property string|null          $notes
 */
class Rental extends Model
{
    protected $fillable = [
        'rental_item_id',
        'member_id',
        'price_per_year',
        'started_at',
        'ended_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'date',
            'ended_at'   => 'date',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function item(): BelongsTo
    {
        return $this->belongsTo(RentalItem::class, 'rental_item_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function orderLines(): MorphMany
    {
        return $this->morphMany(OrderLine::class, 'orderable');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    public function lastBilledYear(): ?int
    {
        return $this->orderLines
            ->filter(fn ($line) => $line->order?->status === Order::STATUS_PAID)
            ->map(fn ($line) => (int) $line->created_at->year)
            ->max() ?: null;
    }

    public function formattedPrice(): string
    {
        return number_format($this->price_per_year / 100, 2, ',', ' ') . ' kr/år';
    }

    /**
     * Create a one-time setup fee order when an item is first assigned.
     * Only called if the type has setup_fee_ore > 0.
     */
    public function createSetupFeeOrder(): Order
    {
        $this->loadMissing('item.type', 'member.user');
        $fee = $this->item->type->setup_fee_ore;

        $order = Order::create([
            'user_id'   => $this->member->user?->id,
            'total_ore' => $fee,
        ]);

        OrderLine::create([
            'order_id'       => $order->id,
            'category'       => OrderLine::CATEGORY_RENTAL,
            'orderable_type' => self::class,
            'orderable_id'   => $this->id,
            'description'    => $this->item->type->name . ' #' . $this->item->label . ' – anskaffningsavgift',
            'unit_price_ore' => $fee,
            'quantity'       => 1,
            'discount_ore'   => 0,
            'line_total_ore' => $fee,
        ]);

        return $order;
    }

    /**
     * Create a standalone order for this rental (used for mid-year assignments).
     */
    public function createOrder(?int $year = null): Order
    {
        $year ??= now()->year;
        $this->loadMissing('item.type.yearPrices', 'member.user');
        $price = $this->item->type->priceForYear($year);

        $order = Order::create([
            'user_id'   => $this->member->user?->id,
            'total_ore' => $price,
        ]);

        OrderLine::create([
            'order_id'       => $order->id,
            'category'       => OrderLine::CATEGORY_RENTAL,
            'orderable_type' => self::class,
            'orderable_id'   => $this->id,
            'description'    => $this->item->type->name . ' #' . $this->item->label . ' ' . $year,
            'unit_price_ore' => $price,
            'quantity'       => 1,
            'discount_ore'   => 0,
            'line_total_ore' => $price,
        ]);

        return $order;
    }

    /**
     * Add this rental as a line on an existing order (used for year-start bundling).
     */
    public function addLineToOrder(Order $order, ?int $year = null): OrderLine
    {
        $year ??= now()->year;
        $this->loadMissing('item.type.yearPrices');
        $price = $this->item->type->priceForYear($year);

        $line = OrderLine::create([
            'order_id'       => $order->id,
            'category'       => OrderLine::CATEGORY_RENTAL,
            'orderable_type' => self::class,
            'orderable_id'   => $this->id,
            'description'    => $this->item->type->name . ' #' . $this->item->label . ' ' . $year,
            'unit_price_ore' => $price,
            'quantity'       => 1,
            'discount_ore'   => 0,
            'line_total_ore' => $price,
        ]);

        $order->increment('total_ore', $price);

        return $line;
    }
}
