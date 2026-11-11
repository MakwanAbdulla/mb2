<?php

namespace Database\Seeders;

use App\Models\Accounting\CostCenter;
use Illuminate\Database\Seeder;

/**
 * Seeds cost centers (kostnadsställen) based on Armagedon's actual
 * Fortnox register, exported 2026-04-12.
 *
 * Mix of physical spaces, activities, and organizational units.
 */
class CostCenterSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->costCenters() as $data) {
            CostCenter::updateOrCreate(['code' => $data['code']], $data);
        }
    }

    private function costCenters(): array
    {
        return [
            // Aktiviteter / sektioner
            ['code' => '40K',    'name' => '40K Turnering',       'type' => 'cost_center', 'is_active' => true],
            ['code' => 'BRÄD',   'name' => 'Brädspel',            'type' => 'cost_center', 'is_active' => true],
            ['code' => 'BSK',    'name' => 'Borås Spelkonvent',    'type' => 'cost_center', 'is_active' => true],
            ['code' => 'FIGUR',  'name' => 'Figurspel',           'type' => 'cost_center', 'is_active' => true],
            ['code' => 'HOBBY',  'name' => 'Hobby',               'type' => 'cost_center', 'is_active' => true],
            ['code' => 'KIOSK',  'name' => 'Kiosken',             'type' => 'cost_center', 'is_active' => true],
            ['code' => 'KRIG',   'name' => 'Krigsspel',           'type' => 'cost_center', 'is_active' => true],
            ['code' => 'LAN',    'name' => 'LAN',                 'type' => 'cost_center', 'is_active' => true],
            ['code' => 'ROLL',   'name' => 'Rollspel',            'type' => 'cost_center', 'is_active' => true],
            ['code' => 'UTH',    'name' => 'Uthyrning',           'type' => 'cost_center', 'is_active' => true],

            // Lokaler / rum
            ['code' => 'BLÅ',   'name' => 'Blåa rummet',         'type' => 'cost_center', 'is_active' => true],
            ['code' => 'BRUN',  'name' => 'Bruna rummet',        'type' => 'cost_center', 'is_active' => true],
            ['code' => 'GRÖN',  'name' => 'Gröna rummet',        'type' => 'cost_center', 'is_active' => true],
            ['code' => 'KÖK',   'name' => 'Kök/Toaletter',       'type' => 'cost_center', 'is_active' => true],
            ['code' => 'LOKAL', 'name' => 'Lokalen Övrigt',      'type' => 'cost_center', 'is_active' => true],
            ['code' => 'MÅLA',  'name' => 'Målarrummen',         'type' => 'cost_center', 'is_active' => true],
            ['code' => 'RÖD',   'name' => 'Röda Rummet',         'type' => 'cost_center', 'is_active' => true],
            ['code' => 'STORA', 'name' => 'Stora Rummet',        'type' => 'cost_center', 'is_active' => true],
            ['code' => 'STY',   'name' => 'Styrelserummet',      'type' => 'cost_center', 'is_active' => true],

            // Organisatoriska
            ['code' => 'STYREL','name' => 'Styrelsen',           'type' => 'cost_center', 'is_active' => true],
        ];
    }
}
