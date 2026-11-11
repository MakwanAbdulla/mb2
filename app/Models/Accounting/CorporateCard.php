<?php

namespace App\Models\Accounting;

use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorporateCard extends Model
{
    protected $fillable = [
        'member_id',
        'card_reference',
        'card_number',
        'issuer',
        'label',
        'is_active',
        'expires_at',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'expires_at' => 'date',
    ];

    /** Grace period in months after expiry during which missing receipts are still shown. */
    public const RECEIPT_GRACE_MONTHS = 6;

    // ── Relationships ─────────────────────────────────────────────────────────

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function expenseClaims(): HasMany
    {
        return $this->hasMany(ExpenseClaim::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Transactions from this card that have no linked expense claim yet.
     * These appear in both the member's restlista and the kassör's overview.
     */
    public function missingReceipts(): HasMany
    {
        return $this->hasMany(BankTransaction::class)
            ->whereDoesntHave('expenseClaimViaCard');
    }

    /**
     * Returns the card number masked as "**** **** **** 1234".
     * If card_number is not stored, falls back to card_reference.
     */
    public function maskedNumber(): string
    {
        if (! $this->card_number) {
            return $this->card_reference;
        }

        $digits = preg_replace('/\D/', '', $this->card_number);
        $last4  = substr($digits, -4);

        return "**** **** **** {$last4}";
    }

    public function displayName(): string
    {
        return $this->label
            ? "{$this->card_reference} – {$this->label}"
            : $this->card_reference;
    }

    /**
     * Extract a card reference (K-prefix + digits) from a bank statement text.
     * Checks both the transaction description and reference fields.
     * Returns null if no known pattern is found.
     *
     * Example: "SNABBGROSSCLUB BORAS K3491" → "K3491"
     */
    public static function extractReference(string ...$fields): ?string
    {
        foreach ($fields as $field) {
            if (preg_match('/\b(K\d{3,6})\b/', $field, $m)) {
                return $m[1];
            }
        }
        return null;
    }
}
