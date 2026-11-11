<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZettlePayout extends Model
{
    protected $fillable = [
        'bank_account_id',
        'bank_transaction_id',
        'payout_uuid',
        'payout_date',
        'amount_ore',
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
}
