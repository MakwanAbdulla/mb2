<?php

namespace App\Models\Accounting;

use App\Models\Accounting\CostCenter;
use App\Models\Accounting\Account;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\AccountingInboxEmail;
use App\Models\Accounting\AccountingInboxItem;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostingRule extends Model
{
    public const TRIGGER_BANK_TRANSACTION = 'bank_transaction';
    public const TRIGGER_AP_INVOICE       = 'ap_invoice';
    public const TRIGGER_INBOX_EMAIL      = 'inbox_email';

    public const TYPE_CREDIT = 'credit';
    public const TYPE_DEBIT  = 'debit';
    public const TYPE_ANY    = 'any';

    protected $fillable = [
        'name',
        'trigger',
        'bank_account_id',
        'vendor_id',
        'from_email_pattern',
        'transaction_type',
        'amount_min_ore',
        'amount_max_ore',
        'keyword',
        'valid_from',
        'valid_to',
        'day_from',
        'day_to',
        'counter_account_id',
        'debit_account_id',
        'credit_account_id',
        'cost_center_id',
        'series',
        'is_active',
        'requires_receipt',
        'sort_order',
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'requires_receipt' => 'boolean',
        'sort_order'     => 'integer',
        'amount_min_ore' => 'integer',
        'amount_max_ore' => 'integer',
        'day_from'       => 'integer',
        'day_to'         => 'integer',
        'valid_from'     => 'date',
        'valid_to'       => 'date',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_id');
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'credit_account_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTrigger($query, string $trigger)
    {
        return $query->where('trigger', $trigger);
    }

    /**
     * Returns true when this rule should be applied to the given bank transaction.
     */
    public function matches(BankTransaction $tx): bool
    {
        // Must have a counter account configured
        if (! $this->counter_account_id) {
            return false;
        }

        // Bank account filter (null = match any account)
        if ($this->bank_account_id && $this->bank_account_id !== $tx->bank_account_id) {
            return false;
        }

        // Transaction type
        if ($this->transaction_type === self::TYPE_CREDIT && $tx->amount <= 0) {
            return false;
        }
        if ($this->transaction_type === self::TYPE_DEBIT && $tx->amount >= 0) {
            return false;
        }

        // Amount range (compared against the absolute value in öre)
        $absAmount = abs($tx->amount);
        if ($this->amount_min_ore !== null && $absAmount < $this->amount_min_ore) {
            return false;
        }
        if ($this->amount_max_ore !== null && $absAmount > $this->amount_max_ore) {
            return false;
        }

        // Keyword match in description or reference (case-insensitive)
        if ($this->keyword !== null && $this->keyword !== '') {
            $needle = mb_strtolower($this->keyword);
            $desc   = mb_strtolower($tx->description ?? '');
            $ref    = mb_strtolower($tx->reference ?? '');
            if (! str_contains($desc, $needle) && ! str_contains($ref, $needle)) {
                return false;
            }
        }

        // Rule validity window
        $txDate = $tx->booking_date ?? $tx->transaction_date;
        if ($txDate) {
            if ($this->valid_from && $txDate->lt($this->valid_from)) {
                return false;
            }
            if ($this->valid_to && $txDate->gt($this->valid_to)) {
                return false;
            }

            // Day-of-month range
            $day = $txDate->day;
            if ($this->day_from !== null && $day < $this->day_from) {
                return false;
            }
            if ($this->day_to !== null && $day > $this->day_to) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true when this rule should be applied to the given AP invoice.
     * Matches on vendor, optional amount range, and optional keyword.
     */
    public function matchesInvoice(Invoice $inv): bool
    {
        if ($this->trigger !== self::TRIGGER_AP_INVOICE) {
            return false;
        }

        if (! $this->counter_account_id) {
            return false;
        }

        // Vendor filter (null = match any vendor)
        if ($this->vendor_id && $this->vendor_id !== $inv->vendor_id) {
            return false;
        }

        // Amount range against invoice total
        $total = abs($inv->total ?? 0);
        if ($this->amount_min_ore !== null && $total < $this->amount_min_ore) {
            return false;
        }
        if ($this->amount_max_ore !== null && $total > $this->amount_max_ore) {
            return false;
        }

        // Keyword in invoice_number or notes
        if ($this->keyword !== null && $this->keyword !== '') {
            $needle = mb_strtolower($this->keyword);
            $num    = mb_strtolower($inv->invoice_number ?? '');
            $notes  = mb_strtolower($inv->notes ?? '');
            if (! str_contains($num, $needle) && ! str_contains($notes, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true when this rule should be applied to the given inbox email.
     *
     * Matching criteria (all configured criteria must match):
     *  - from_email_pattern: matched against original_from_email, then from_email
     *    Supports exact address or @domain prefix (same as AccountingInboxSender).
     *  - keyword: matched case-insensitively against the email subject line.
     */
    public function matchesInboxEmail(AccountingInboxEmail $email): bool
    {
        if ($this->trigger !== self::TRIGGER_INBOX_EMAIL) {
            return false;
        }

        // from_email_pattern filter (null = match any sender)
        if ($this->from_email_pattern !== null && $this->from_email_pattern !== '') {
            $pattern    = strtolower(trim($this->from_email_pattern));
            $candidates = array_filter([
                strtolower(trim($email->original_from_email ?? '')),
                strtolower(trim($email->from_email)),
            ]);

            $matched = false;
            foreach ($candidates as $addr) {
                if ($addr === '') {
                    continue;
                }
                if (str_starts_with($pattern, '@')) {
                    $matched = str_ends_with($addr, $pattern);
                } else {
                    $matched = $addr === $pattern;
                }
                if ($matched) {
                    break;
                }
            }

            if (! $matched) {
                return false;
            }
        }

        // Keyword filter against subject (null/empty = match any subject)
        if ($this->keyword !== null && $this->keyword !== '') {
            $needle  = mb_strtolower($this->keyword);
            $subject = mb_strtolower($email->subject ?? '');
            if (! str_contains($subject, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true when this rule should be applied to the given inbox item.
     *
     * Like matchesInboxEmail() but the keyword is matched against BOTH the
     * email subject AND the attachment filename. This allows a single email
     * (e.g. a monthly SumUp overview containing a fee invoice + payout reports)
     * to have different attachments matched by different rules.
     */
    public function matchesInboxItem(AccountingInboxEmail $email, AccountingInboxItem $item): bool
    {
        if ($this->trigger !== self::TRIGGER_INBOX_EMAIL) {
            return false;
        }

        // Sender pattern: same logic as matchesInboxEmail
        if ($this->from_email_pattern !== null && $this->from_email_pattern !== '') {
            $pattern    = strtolower(trim($this->from_email_pattern));
            $candidates = array_filter([
                strtolower(trim($email->original_from_email ?? '')),
                strtolower(trim($email->from_email)),
            ]);

            $matched = false;
            foreach ($candidates as $addr) {
                if ($addr === '') {
                    continue;
                }
                if (str_starts_with($pattern, '@')) {
                    $matched = str_ends_with($addr, $pattern);
                } else {
                    $matched = $addr === $pattern;
                }
                if ($matched) {
                    break;
                }
            }

            if (! $matched) {
                return false;
            }
        }

        // Keyword: match against subject OR filename
        if ($this->keyword !== null && $this->keyword !== '') {
            $needle   = mb_strtolower($this->keyword);
            $subject  = mb_strtolower($email->subject ?? '');
            $filename = mb_strtolower($item->filename ?? '');
            if (! str_contains($subject, $needle) && ! str_contains($filename, $needle)) {
                return false;
            }
        }

        return true;
    }

    /** Human-readable transaction type label. */
    public function transactionTypeLabel(): string
    {
        return match ($this->transaction_type) {
            self::TYPE_CREDIT => 'Inbetalning',
            self::TYPE_DEBIT  => 'Utbetalning',
            default           => 'Båda',
        };
    }
}
