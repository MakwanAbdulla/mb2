<?php

namespace App\Models\Accounting;

use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\Account;
use App\Models\Accounting\CostCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    public const SWISH_TYPE_PRIVATE = 'private';
    public const SWISH_TYPE_HANDEL  = 'handel';

    protected $fillable = [
        'name',
        'account_number',
        'clearing_number',
        'bank_name',
        'account_id',
        'currency',
        'is_active',
        'notes',
        'bankgiro',
        'plusgiro',
        'swish_number',
        'swish_type',
        'cost_center_id',
        'iban',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
