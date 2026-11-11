<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InvoiceSequence extends Model
{
    public const SERIES_SHOP = 'shop';
    public const SERIES_AR   = 'ar';

    protected $fillable = ['series', 'year', 'last_number'];

    /**
     * Atomically reserves and returns the next invoice number for the given
     * series and year. Safe under concurrent requests via row-level locking.
     *
     * Returns a formatted string: "{YEAR}-{SERIES}-{SEQUENCE}"
     * e.g. "2027-SHOP-0042" or "2027-AR-0003"
     */
    public static function next(string $series, int $year): string
    {
        $number = DB::transaction(function () use ($series, $year) {
            $row = self::where('series', $series)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($row) {
                $row->increment('last_number');
                return $row->fresh()->last_number;
            }

            self::create(['series' => $series, 'year' => $year, 'last_number' => 1]);
            return 1;
        });

        $seriesLabel = strtoupper($series);
        return "{$year}-{$seriesLabel}-" . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}
