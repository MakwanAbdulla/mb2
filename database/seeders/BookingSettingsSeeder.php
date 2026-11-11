<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class BookingSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'key'         => 'max_bookings_per_user',
                'category'    => 'booking',
                'label'       => 'Max bokningar per person',
                'value'       => '1',
                'type'        => 'integer',
                'description' => 'Hur många aktiva bokningar en vanlig användare kan ha samtidigt.',
            ],
            [
                'key'         => 'max_bookings_super_booker',
                'category'    => 'booking',
                'label'       => 'Max bokningar (superbokare)',
                'value'       => '3',
                'type'        => 'integer',
                'description' => 'Hur många aktiva bokningar en superbokare kan ha samtidigt.',
            ],
            [
                'key'         => 'open_bookings_exempt',
                'category'    => 'booking',
                'label'       => 'Öppna arrangemang räknas inte mot gränsen',
                'value'       => '0',
                'type'        => 'boolean',
                'description' => 'Om aktiverat räknas bokningar av typ "öppet" inte mot maxgränsen.',
            ],
            [
                'key'         => 'allow_same_day_booking',
                'category'    => 'booking',
                'label'       => 'Tillåt bokning samma dag',
                'value'       => '0',
                'type'        => 'boolean',
                'description' => 'Om aktiverat kan användare boka rum samma dag som bokningsdatumet.',
            ],
            [
                'key'         => 'user_max_months_ahead',
                'category'    => 'booking',
                'label'       => 'Max månader framåt (vanliga)',
                'value'       => '6',
                'type'        => 'integer',
                'description' => 'Hur många månader framåt vanliga användare kan boka.',
            ],
            [
                'key'         => 'admin_max_months_ahead',
                'category'    => 'booking',
                'label'       => 'Max månader framåt (admin)',
                'value'       => '11',
                'type'        => 'integer',
                'description' => 'Hur många månader framåt admins kan boka.',
            ],
            [
                'key'         => 'venue_max_months_ahead',
                'category'    => 'booking',
                'label'       => 'Max månader framåt (lokalansökningar)',
                'value'       => '11',
                'type'        => 'integer',
                'description' => 'Hur långt framåt lokalansökningar kan avse.',
            ],
            [
                'key'         => 'booking_opens_from',
                'category'    => 'booking',
                'label'       => 'Systemöppning (datum)',
                'value'       => null,
                'type'        => 'string',
                'description' => 'Bokningar kan inte avse datum före detta. Lämna tomt för ingen begränsning.',
            ],
            [
                'key'         => 'non_member_message',
                'category'    => 'booking',
                'label'       => 'Meddelande till icke-members',
                'value'       => 'Ditt konto är inaktiverat för bokningar tills årets medlemsavgift är betald.',
                'type'        => 'string',
                'description' => 'Visas för inloggade användare som inte är aktiva members.',
            ],
            [
                'key'         => 'reminder_email_message',
                'category'    => 'booking',
                'label'       => 'Extra text i påminnelse-epost',
                'value'       => '',
                'type'        => 'string',
                'description' => 'Valfri extra text som infogas i påminnelse-epost.',
            ],
        ];

        foreach ($defaults as $setting) {
            SystemSetting::firstOrCreate(
                ['key' => $setting['key']],
                $setting,
            );
        }
    }
}
