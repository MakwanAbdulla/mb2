<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLine extends Model
{
    public const MONTHS = ['jan','feb','mar','apr','maj','jun','jul','aug','sep','okt','nov','dec'];

    public const MONTHS_PCT = ['jan_pct','feb_pct','mar_pct','apr_pct','maj_pct','jun_pct',
                               'jul_pct','aug_pct','sep_pct','okt_pct','nov_pct','dec_pct'];

    protected $fillable = [
        'budget_id',
        'account_id',
        'annual_ore',
        'jan_pct','feb_pct','mar_pct','apr_pct','maj_pct','jun_pct',
        'jul_pct','aug_pct','sep_pct','okt_pct','nov_pct','dec_pct',
    ];

    protected $casts = [
        'annual_ore' => 'integer',
        'jan_pct' => 'integer', 'feb_pct' => 'integer', 'mar_pct' => 'integer',
        'apr_pct' => 'integer', 'maj_pct' => 'integer', 'jun_pct' => 'integer',
        'jul_pct' => 'integer', 'aug_pct' => 'integer', 'sep_pct' => 'integer',
        'okt_pct' => 'integer', 'nov_pct' => 'integer', 'dec_pct' => 'integer',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Annual budget total in öre.
     */
    public function annualTotal(): int
    {
        return $this->annual_ore;
    }

    /**
     * Calculated amount for a given month (1–12) in öre.
     * monthly = annual_ore × month_pct / 10000
     */
    public function monthAmount(int $month): int
    {
        $pctCol = self::MONTHS_PCT[$month - 1];
        return (int) round($this->annual_ore * $this->$pctCol / 10000);
    }

    /**
     * Sum of all calculated monthly amounts (may differ from annual_ore due to rounding).
     */
    public function distributedTotal(): int
    {
        return array_sum(array_map(fn ($i) => $this->monthAmount($i + 1), range(0, 11)));
    }

    /**
     * Sum of all percentage values × 100 (should equal 10000 = 100%).
     */
    public function pctTotal(): int
    {
        return array_sum(array_map(fn ($col) => $this->$col, self::MONTHS_PCT));
    }
}
