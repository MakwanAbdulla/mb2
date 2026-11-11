<?php

namespace App\Models\Membership;

use App\Models\Shop\Order;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipPeriod extends Model
{
    /** @use HasFactory<\Database\Factories\MembershipPeriodFactory> */
    use HasFactory;

    const STATUS_PENDING_PAYMENT = 'pending_payment';
    const STATUS_ACTIVE          = 'active';
    const STATUS_EXPIRED         = 'expired';
    const STATUS_CANCELLED       = 'cancelled';

    /** @var list<string> */
    protected $fillable = [
        'member_id',
        'order_id',
        'year',
        'membership_type',
        'status',
        'activated_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'expires_at'   => 'datetime',
            'year'         => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function activate(): void
    {
        $this->update([
            'status'       => self::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
    }

    public function expire(): void
    {
        $this->update([
            'status'     => self::STATUS_EXPIRED,
            'expires_at' => now(),
        ]);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
