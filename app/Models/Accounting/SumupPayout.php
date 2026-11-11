<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SumupPayout extends Model
{
    protected $fillable = [
        'sumup_id',
        'bank_account_id',
        'bank_transaction_id',
        'payout_date',
        'transaction_code',
        'reference',
        'status',
        'type',
        'currency',
        'amount_ore',
        'fee_ore',
        'raw',
    ];

    protected $casts = [
        'payout_date' => 'date',
        'raw'         => 'array',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(SumupTransaction::class, 'transaction_code', 'transaction_code');
    }
}
