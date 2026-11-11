<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'discord.guild_id'],
            [
                'category'    => 'discord',
                'label'       => 'Guild ID (Server ID)',
                'description' => 'Högerklicka på servern i Discord → Copy Server ID.',
                'value'       => '',
                'type'        => 'string',
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'discord.guild_id')->delete();
    }
};
