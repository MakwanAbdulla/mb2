<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZettleTransaction extends Model
{
    protected $fillable = [
        'purchase_uuid',
        'bank_account_id',
        'bank_transaction_id',
        'purchase_date',
        'purchase_time',
        'receipt_number',
        'gross_ore',
        'fee_ore',
        'net_ore',
        'payment_type',
        'card_type',
        'card_last_four',
        'staff',
        'description',
        'raw',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'raw'           => 'array',
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
