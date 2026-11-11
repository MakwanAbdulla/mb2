<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class MembershipSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'key'         => 'family.max_adults',
                'category'    => SystemSetting::CATEGORY_MEMBERSHIP,
                'label'       => 'Max vuxna per familj',
                'value'       => '2',
                'type'        => SystemSetting::TYPE_INTEGER,
                'description' => 'Maximalt antal vuxna (18+) i en familjegrupp.',
            ],
            [
                'key'         => 'family.max_children',
                'category'    => SystemSetting::CATEGORY_MEMBERSHIP,
                'label'       => 'Max barn per familj',
                'value'       => '5',
                'type'        => SystemSetting::TYPE_INTEGER,
                'description' => 'Maximalt antal barn i en familjegrupp.',
            ],
            [
                'key'         => 'family.max_child_age',
                'category'    => SystemSetting::CATEGORY_MEMBERSHIP,
                'label'       => 'Max ålder för barn (år)',
                'value'       => '18',
                'type'        => SystemSetting::TYPE_INTEGER,
                'description' => 'Upp till (men inte inkl.) denna ålder räknas en familjemedlem som barn.',
            ],
            [
                'key'         => 'membership.grace_period_months',
                'category'    => SystemSetting::CATEGORY_MEMBERSHIP,
                'label'       => 'Karenstid för förnyelse (månader)',
                'value'       => '1',
                'type'        => SystemSetting::TYPE_INTEGER,
                'description' => 'Antal månader från 1 januari som ett återkommande medlemskap har full access trots obetald avgift.',
            ],
            [
                'key'         => 'membership.renewal_visible_month',
                'category'    => SystemSetting::CATEGORY_MEMBERSHIP,
                'label'       => 'Förnyelse synlig från månad',
                'value'       => '12',
                'type'        => SystemSetting::TYPE_INTEGER,
                'description' => 'Från och med denna månad (1–12) visas CTA för att förnya kommande år.',
            ],
            [
                'key'         => 'membership.new_member_next_year_from_month',
                'category'    => SystemSetting::CATEGORY_MEMBERSHIP,
                'label'       => 'Ny medlem betalar för kommande år från månad',
                'value'       => '10',
                'type'        => SystemSetting::TYPE_INTEGER,
                'description' => 'Från och med denna månad (1–12) registreras nya förstagångsmedlemmar för kommande år istället för innevarande år. Bör vara ett högt värde (t.ex. 10–12) för att undvika att nya medlemmar missar hela innevarande år.',
            ],
            [
                'key'         => 'tags.price_ore',
                'category'    => SystemSetting::CATEGORY_MEMBERSHIP,
                'label'       => 'RFID-tagg pris (öre)',
                'value'       => '7500',
                'type'        => SystemSetting::TYPE_INTEGER,
                'description' => 'Pris för en RFID-tagg i öre (7500 = 75 kr). Ändras via Inställningar → Priser.',
            ],
        ];

        foreach ($defaults as $setting) {
            SystemSetting::firstOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
