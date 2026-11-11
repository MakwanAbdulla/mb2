<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'discord.webhook.events'],
            [
                'category'    => 'discord',
                'label'       => 'Webhook-URL för evenemang',
                'description' => 'Discord-webhook dit notiser skickas när ett evenemang publiceras. Lämna tomt för att inaktivera.',
                'value'       => '',
                'type'        => 'string',
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'discord.webhook.events')->delete();
    }
};
