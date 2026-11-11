<?php

namespace App\Models\Accounting;

use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\BudgetLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Budget extends Model
{
    protected $fillable = [
        'fiscal_year_id',
        'name',
        'is_active',
        'is_locked',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_locked' => 'boolean',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    public function totalForMonth(int $month): int
    {
        $col = ['jan','feb','mar','apr','maj','jun','jul','aug','sep','okt','nov','dec'][$month - 1];
        return $this->lines->sum($col);
    }
}
