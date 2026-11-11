<?php

namespace App\Models\Accounting;

use App\Models\Accounting\PeriodLock;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Budget;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    protected $fillable = [
        'name',
        'starts_at',
        'ends_at',
        'is_locked',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at'   => 'date',
        'is_locked' => 'boolean',
        'closed_at' => 'datetime',
    ];

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function periodLocks(): HasMany
    {
        return $this->hasMany(PeriodLock::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function openingBalances(): HasMany
    {
        return $this->hasMany(OpeningBalance::class);
    }

    /** True when opening balances have been entered (at least one row). */
    public function hasOpeningBalances(): bool
    {
        return $this->openingBalances()->exists();
    }

    public function containsDate(\DateTimeInterface|string $date): bool
    {
        $d = is_string($date) ? \Carbon\Carbon::parse($date) : $date;
        return $d->between($this->starts_at, $this->ends_at);
    }
}
