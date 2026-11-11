<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SumupTransaction extends Model
{
    protected $fillable = [
        'transaction_id',
        'transaction_code',
        'bank_account_id',
        'bank_transaction_id',
        'purchased_at',
        'payout_date',
        'amount_ore',
        'currency',
        'status',
        'payment_type',
        'entry_mode',
        'card_type',
        'product_summary',
        'staff_email',
        'raw',
    ];

    protected $casts = [
        'purchased_at' => 'datetime',
        'payout_date'  => 'date',
        'raw'          => 'array',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }
}
