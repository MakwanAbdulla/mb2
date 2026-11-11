<?php

namespace Database\Seeders;

use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalSeries;
use Illuminate\Database\Seeder;

/**
 * Seeds journal series (verifikationsserier) based on Armagedon's actual
 * Fortnox configuration, exported 2026-04-12.
 *
 * default_for maps to accounting_settings keys for "förvalt serie per syfte".
 */
class JournalSeriesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->series() as $data) {
            JournalSeries::updateOrCreate(['code' => $data['code']], $data);
        }

        // Seed current fiscal year so local dev and testing can book transactions.
        $year = now()->year;
        FiscalYear::updateOrCreate(
            ['name' => (string) $year],
            ['starts_at' => "{$year}-01-01", 'ends_at' => "{$year}-12-31", 'is_locked' => false]
        );
    }

    private function series(): array
    {
        return [
            // System-managed series (created automatically by the application)
            ['code' => 'A', 'name' => 'Redovisning',                    'is_manual' => true,  'is_lockable' => true,  'is_system' => true,  'default_for' => null,           'sort_order' => 1],
            ['code' => 'B', 'name' => 'Kundfakturor',                   'is_manual' => false, 'is_lockable' => true,  'is_system' => true,  'default_for' => 'ar',           'sort_order' => 2],
            ['code' => 'C', 'name' => 'Inbetalningar från kunder',      'is_manual' => false, 'is_lockable' => true,  'is_system' => true,  'default_for' => 'ar_payment',   'sort_order' => 3],
            ['code' => 'D', 'name' => 'Leverantörsfakturor',            'is_manual' => false, 'is_lockable' => true,  'is_system' => true,  'default_for' => 'ap',           'sort_order' => 4],
            ['code' => 'E', 'name' => 'Utbetalningar till leverantörer','is_manual' => false, 'is_lockable' => true,  'is_system' => true,  'default_for' => 'ap_payment',   'sort_order' => 5],
            ['code' => 'G', 'name' => 'Avskrivning',                    'is_manual' => false, 'is_lockable' => true,  'is_system' => false, 'default_for' => 'depreciation', 'sort_order' => 7],
            ['code' => 'H', 'name' => 'Periodisering',                  'is_manual' => false, 'is_lockable' => true,  'is_system' => false, 'default_for' => 'accrual',      'sort_order' => 8],
            ['code' => 'I', 'name' => 'Bokslut',                        'is_manual' => false, 'is_lockable' => false, 'is_system' => false, 'default_for' => 'year_end',     'sort_order' => 9],
            ['code' => 'M', 'name' => 'Momsrapport',                    'is_manual' => false, 'is_lockable' => true,  'is_system' => false, 'default_for' => 'vat',          'sort_order' => 13],
            ['code' => 'N', 'name' => 'Lager',                          'is_manual' => false, 'is_lockable' => true,  'is_system' => false, 'default_for' => 'inventory',    'sort_order' => 14],
            ['code' => 'U', 'name' => 'Automatbokföring',               'is_manual' => true,  'is_lockable' => true,  'is_system' => true,  'default_for' => 'auto',         'sort_order' => 21],

            // Manual series (kassören kan skapa verifikationer)
            ['code' => 'F', 'name' => 'Kassa',                          'is_manual' => false, 'is_lockable' => true,  'is_system' => false, 'default_for' => null,           'sort_order' => 6],
            ['code' => 'J', 'name' => 'Kontantfaktura',                 'is_manual' => false, 'is_lockable' => true,  'is_system' => false, 'default_for' => 'cash_invoice', 'sort_order' => 10],
            ['code' => 'K', 'name' => 'Kvitto',                         'is_manual' => false, 'is_lockable' => true,  'is_system' => false, 'default_for' => 'receipts',     'sort_order' => 11],
            ['code' => 'L', 'name' => 'Lön',                            'is_manual' => false, 'is_lockable' => true,  'is_system' => false, 'default_for' => 'payroll',      'sort_order' => 12],
            ['code' => 'S', 'name' => 'Kiosk',                          'is_manual' => true,  'is_lockable' => true,  'is_system' => false, 'default_for' => null,           'sort_order' => 19],
            ['code' => 'T', 'name' => 'BSK',                            'is_manual' => true,  'is_lockable' => true,  'is_system' => false, 'default_for' => null,           'sort_order' => 20],
            ['code' => 'V', 'name' => '40K Turnering',                  'is_manual' => true,  'is_lockable' => true,  'is_system' => false, 'default_for' => null,           'sort_order' => 22],
            ['code' => 'W', 'name' => 'CogWork',                        'is_manual' => true,  'is_lockable' => true,  'is_system' => false, 'default_for' => null,           'sort_order' => 23],
            ['code' => 'Z', 'name' => 'Zettle',                         'is_manual' => true,  'is_lockable' => true,  'is_system' => false, 'default_for' => null,           'sort_order' => 26],
        ];
    }
}
