<?php

namespace App\Models\Accounting;

use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\PostingRule;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\Account;
use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    public const TYPE_EXTERNAL = 'external';
    public const TYPE_MEMBER   = 'member';

    protected $fillable = [
        'code',
        'name',
        'type',
        'member_id',
        'org_number',
        'bankgiro',
        'plusgiro',
        'iban',
        'bank_clearing_number',
        'email',
        'phone',
        'default_account_id',
        'default_vat_rate',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'default_vat_rate' => 'integer',
        'is_active'        => 'boolean',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function defaultAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_account_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function postingRules(): HasMany
    {
        return $this->hasMany(PostingRule::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
