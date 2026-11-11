<?php

namespace App\Models\Accounting;

use App\Models\Accounting\AccountingDocument;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\Customer;
use App\Models\Accounting\PaymentTerm;
use App\Models\Accounting\Vendor;
use App\Models\Accounting\ApprovalLog;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\InvoiceLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    public const TYPE_PAYABLE    = 'payable';
    public const TYPE_RECEIVABLE = 'receivable';

    public const STATUS_DRAFT            = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED         = 'approved';
    public const STATUS_POSTED           = 'posted';
    public const STATUS_PAID             = 'paid';
    public const STATUS_CANCELLED        = 'cancelled';

    // AP payment methods (how we pay the vendor)
    public const PM_BANKGIRO      = 'bankgiro';
    public const PM_PLUSGIRO      = 'plusgiro';
    public const PM_BANK_TRANSFER = 'bank_transfer';

    // AR payment methods (how they pay us)
    public const PM_SWISH         = 'swish';

    protected $fillable = [
        'type',
        'vendor_id',
        'customer_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'payment_term_id',
        'payment_method',
        'bank_account_id',
        'ocr_number',
        'amount_ex_vat',
        'vat_amount',
        'total',
        'currency',
        'status',
        'ai_extraction_raw',
        'journal_entry_id',
        'payment_journal_entry_id',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
        'paid_at',
        'sent_at',
    ];

    protected $casts = [
        'invoice_date'       => 'date',
        'due_date'           => 'date',
        'amount_ex_vat'      => 'integer',
        'vat_amount'         => 'integer',
        'total'              => 'integer',
        'ai_extraction_raw'  => 'array',
        'approved_at'        => 'datetime',
        'paid_at'            => 'datetime',
        'sent_at'            => 'datetime',
    ];

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function paymentJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'payment_journal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AccountingDocument::class)->latest();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function approvalLogs(): HasMany
    {
        return $this->hasMany(ApprovalLog::class);
    }

    /** The bank transaction that was matched as payment for this invoice. */
    public function bankTransaction(): HasOne
    {
        return $this->hasOne(BankTransaction::class);
    }

    /**
     * Generate a Swedish bankgiro OCR reference number (Modulo 10 / Luhn).
     * Uses the invoice ID zero-padded to 8 digits + 1 check digit = 9 digits total.
     */
    public function generateOcr(): string
    {
        $base   = str_pad((string) $this->id, 8, '0', STR_PAD_LEFT);
        $digits = str_split($base);
        $sum    = 0;
        $double = false;

        // Luhn from right to left
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum   += $d;
            $double = ! $double;
        }

        $check = (10 - ($sum % 10)) % 10;
        return $base . $check;
    }

    /** Label for display. */
    public function paymentMethodLabel(): string
    {
        return match ($this->payment_method) {
            self::PM_BANKGIRO      => 'Bankgiro',
            self::PM_PLUSGIRO      => 'Plusgiro',
            self::PM_BANK_TRANSFER => 'Banköverföring',
            self::PM_SWISH         => 'Swish',
            default                => $this->payment_method ?? '–',
        };
    }

    public function isDraft(): bool      { return $this->status === self::STATUS_DRAFT; }
    public function isApproved(): bool   { return $this->status === self::STATUS_APPROVED; }
    public function isPosted(): bool     { return $this->status === self::STATUS_POSTED; }
    public function isPaid(): bool       { return $this->status === self::STATUS_PAID; }
    public function isCancelled(): bool  { return $this->status === self::STATUS_CANCELLED; }
    public function isPayable(): bool    { return $this->type === self::TYPE_PAYABLE; }
    public function isReceivable(): bool { return $this->type === self::TYPE_RECEIVABLE; }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && ! $this->isPaid()
            && ! $this->isCancelled();
    }

    public function scopePayable($query)    { return $query->where('type', self::TYPE_PAYABLE); }
    public function scopeReceivable($query) { return $query->where('type', self::TYPE_RECEIVABLE); }
    public function scopePending($query)
    {
        return $query->whereNotIn('status', [self::STATUS_PAID, self::STATUS_CANCELLED]);
    }
}
