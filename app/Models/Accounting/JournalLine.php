<?php

namespace App\Models\Accounting;

use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'cost_center_id',
        'debit',
        'credit',
        'description',
        'superseded_at',
        'superseded_by',
    ];

    protected $casts = [
        'debit'         => 'integer',
        'credit'        => 'integer',
        'superseded_at' => 'datetime',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superseded_by');
    }

    /** Net amount: positive = debit, negative = credit. */
    public function netAmount(): int
    {
        return $this->debit - $this->credit;
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    /** Only current (non-superseded) lines. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    /** Only superseded (historical) lines. */
    public function scopeSuperseded(Builder $query): Builder
    {
        return $query->whereNotNull('superseded_at');
    }
}
