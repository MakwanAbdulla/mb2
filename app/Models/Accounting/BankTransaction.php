<?php

namespace App\Models\Accounting;

use App\Models\Accounting\BankAccount;
use App\Models\Accounting\CorporateCard;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BankTransaction extends Model
{
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_MATCHED   = 'matched';
    public const STATUS_POSTED    = 'posted';
    public const STATUS_IGNORED   = 'ignored';

    protected $fillable = [
        'bank_account_id',
        'booking_date',
        'transaction_date',
        'value_date',
        'amount',
        'balance_ore',
        'import_hash',
        'description',
        'reference',
        'status',
        'journal_entry_id',
        'corporate_card_id',
        'vendor_id',
        'invoice_id',
        'raw_data',
        'notes',
    ];

    protected $casts = [
        'booking_date'     => 'date',
        'transaction_date' => 'date',
        'value_date'       => 'date',
        'amount'           => 'integer',
        'balance_ore'      => 'integer',
        'raw_data'         => 'array',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function corporateCard(): BelongsTo
    {
        return $this->belongsTo(CorporateCard::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** The expense claim that was matched to this card transaction, if any. */
    public function expenseClaimViaCard(): HasOne
    {
        return $this->hasOne(ExpenseClaim::class, 'bank_transaction_id');
    }

    public function hasReceipt(): bool
    {
        return $this->expenseClaimViaCard()->exists();
    }

    public function scopeUnmatched($query)
    {
        return $query->where('status', self::STATUS_UNMATCHED);
    }

    public function isCredit(): bool { return $this->amount > 0; }
    public function isDebit(): bool  { return $this->amount < 0; }
}
