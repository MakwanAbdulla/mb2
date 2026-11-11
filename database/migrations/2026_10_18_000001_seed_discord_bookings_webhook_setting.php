<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'discord.webhook.bookings'],
            [
                'category' => 'discord',
                'label' => 'Webhook-URL för bokningar',
                'description' => 'Discord-webhook dit sammanfattningen av dagens bokningar skickas. Lämna tomt för att inaktivera.',
                'value' => '',
                'type' => 'string',
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'discord.webhook.bookings')->delete();
    }
};
