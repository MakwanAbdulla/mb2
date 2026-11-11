<?php

namespace App\Models\Shop;

use App\Models\Accounting\Customer;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\InvoiceSequence;
use App\Models\Membership\MembershipPeriod;
use App\Models\Shop\OrderLine;
use App\Models\Shop\OrderFulfillment;
use App\Models\User;
use App\Events\OrderPaid;
use App\Events\OrderPaymentFailed;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Order extends Model
{
    use HasFactory;
    const STATUS_PENDING   = 'pending';
    const STATUS_PAID      = 'paid';
    const STATUS_REFUNDED  = 'refunded';
    const STATUS_CANCELLED = 'cancelled';

    const METHOD_STRIPE       = 'stripe';
    const METHOD_SWISH_HANDEL = 'swish_handel';
    const METHOD_SWISH        = 'swish';
    const METHOD_BANKGIRO     = 'bankgiro';
    const METHOD_MANUAL       = 'manual';
    const METHOD_FREE         = 'free';

    const REFERENCE_PREFIX = 'ARM';

    protected $fillable = [
        'user_id',
        'customer_id',
        'guest_name',
        'guest_email',
        'guest_phone',
        'payment_reference',
        'ocr_number',
        'status',
        'total_ore',
        'discount_ore',
        'payment_method',
        'stripe_session_id',
        'swish_payment_id',
        'notes',
        'paid_at',
        'invoice_number',
        'invoice_issued_at',
        'invoice_brand',
        'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'paid_at'            => 'datetime',
            'invoice_issued_at'  => 'datetime',
            'total_ore'          => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Order $order) {
            $padded = str_pad((string) $order->id, 5, '0', STR_PAD_LEFT);
            $order->updateQuietly([
                'payment_reference' => self::REFERENCE_PREFIX . '-' . $padded,
                'ocr_number'        => self::generateOcr($order->id, (int) $order->created_at->year),
            ]);
        });
    }

    /**
     * Generate a Bankgirot variant-2 OCR number.
     *
     * Format: {year}{zero-padded id}{length mod 10}{Luhn check digit}
     * The length digit encodes total OCR length mod 10, enabling automatic BGMax file import.
     *
     * Example: order 42, year 2026 → base "202600042" (9 digits)
     *   total length = 11, length digit = 1, check digit computed on "2026000421"
     *   result: "20260004211"
     */
    public static function generateOcr(int $id, int $year): string
    {
        $base        = (string) $year . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
        $totalLength = strlen($base) + 2; // +1 length digit +1 check digit
        $withLength  = $base . ($totalLength % 10);

        $digits = str_split($withLength);
        $sum    = 0;
        $double = true;

        foreach (array_reverse($digits) as $digit) {
            $n = (int) $digit;
            if ($double) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum   += $n;
            $double = ! $double;
        }

        return $withLength . ((10 - ($sum % 10)) % 10);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * Mark as paid and fire OrderPaid event.
     */
    public function markPaid(string $method): void
    {
        $invoiceNumber = $this->invoice_number
            ?? InvoiceSequence::next(InvoiceSequence::SERIES_SHOP, now()->year);

        $this->update([
            'status'            => self::STATUS_PAID,
            'payment_method'    => $method,
            'paid_at'           => Carbon::now(),
            'invoice_number'    => $invoiceNumber,
            'invoice_issued_at' => Carbon::now(),
        ]);

        event(new OrderPaid($this));
    }

    /**
     * Mark this order as failed/declined — never on an already-paid order
     * (idempotency against duplicate/late gateway callbacks).
     */
    public function markFailed(string $reason): void
    {
        if ($this->isPaid()) {
            return;
        }

        $this->update(['status' => self::STATUS_CANCELLED]);

        event(new OrderPaymentFailed($this, $reason));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function membershipPeriods(): HasMany
    {
        return $this->hasMany(MembershipPeriod::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(OrderFulfillment::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
