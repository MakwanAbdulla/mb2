<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwishTransaction extends Model
{
    protected $fillable = [
        'swish_import_id',
        'bank_account_id',
        'bank_transaction_id',
        'row_number',
        'booking_date',
        'transaction_date',
        'value_date',
        'swish_number',
        'sender_phone',
        'sender_name',
        'message',
        'transaction_time',
        'amount_ore',
        'order_reference',
        'import_hash',
    ];

    protected $casts = [
        'booking_date'     => 'date',
        'transaction_date' => 'date',
        'value_date'       => 'date',
    ];

    public function swishImport(): BelongsTo
    {
        return $this->belongsTo(SwishImport::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public static function importHash(int $bankAccountId, array $row): string
    {
        return hash('sha256', implode('|', [
            $bankAccountId,
            $row['booking_date'],
            $row['transaction_date'],
            $row['transaction_time'],  // HH:mm — makes same-person/same-amount/same-day unique
            $row['sender_phone'],
            $row['amount_ore'],
        ]));
    }
}
