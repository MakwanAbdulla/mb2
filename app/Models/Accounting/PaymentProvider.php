<?php

namespace App\Models\Accounting;

use App\Models\Accounting\BankAccount;
use App\Models\Accounting\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProvider extends Model
{
    public const TYPE_ONLINE        = 'online';
    public const TYPE_CARD_TERMINAL = 'card_terminal';

    protected $fillable = [
        'name',
        'type',
        'account_id',
        'settlement_bank_account_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function settlementBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'settlement_bank_account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_ONLINE        => 'Online',
            self::TYPE_CARD_TERMINAL => 'Kortterminal',
            default                  => $this->type,
        };
    }
}
