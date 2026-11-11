<?php

namespace App\Models\Accounting;

use App\Models\Accounting\FiscalYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodLock extends Model
{
    protected $fillable = [
        'fiscal_year_id',
        'year',
        'month',
        'locked_at',
        'locked_by',
    ];

    protected $casts = [
        'year'      => 'integer',
        'month'     => 'integer',
        'locked_at' => 'datetime',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
