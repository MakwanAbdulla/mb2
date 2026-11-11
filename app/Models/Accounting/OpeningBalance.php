<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $fiscal_year_id
 * @property int         $account_id
 * @property int         $amount           Signed öre: positive = debit, negative = credit
 * @property int         $created_by
 * @property int|null    $updated_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class OpeningBalance extends Model
{
    protected $fillable = [
        'fiscal_year_id',
        'account_id',
        'amount',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Amount in kronor for display. */
    public function amountKronor(): string
    {
        $abs  = abs($this->amount);
        $sign = $this->amount < 0 ? '-' : '';
        return $sign . number_format($abs / 100, 2, ',', ' ') . ' kr';
    }
}
