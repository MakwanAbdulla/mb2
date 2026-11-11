<?php

namespace App\Models\Accounting;

use App\Models\Accounting\JournalLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    public const TYPE_ASSET     = 'asset';
    public const TYPE_LIABILITY = 'liability';
    public const TYPE_EQUITY    = 'equity';
    public const TYPE_INCOME    = 'income';
    public const TYPE_EXPENSE   = 'expense';

    protected $fillable = [
        'code',
        'name',
        'type',
        'vat_code',
        'is_group',
        'is_active',
        'parent_id',
        'sort_order',
    ];

    protected $casts = [
        'is_group'   => 'boolean',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLeaf($query)
    {
        return $query->where('is_group', false);
    }

    public function isAsset(): bool     { return $this->type === self::TYPE_ASSET; }
    public function isLiability(): bool { return $this->type === self::TYPE_LIABILITY; }
    public function isEquity(): bool    { return $this->type === self::TYPE_EQUITY; }
    public function isIncome(): bool    { return $this->type === self::TYPE_INCOME; }
    public function isExpense(): bool   { return $this->type === self::TYPE_EXPENSE; }
}
