<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'discord.bookings_overview.message_id'],
            [
                'category' => 'discord',
                'label' => 'Meddelande-ID för bokningsöversikt',
                'description' => 'Sätts automatiskt. ID för Discord-meddelandet som visar bokningar för kommande 7 dagar och redigeras när bokningar ändras.',
                'value' => '',
                'type' => 'string',
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'discord.bookings_overview.message_id')->delete();
    }
};
