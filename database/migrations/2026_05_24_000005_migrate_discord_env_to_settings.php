<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $map = [
            'discord.guild_id'    => '1508029509314674758',
            'discord.role_member' => '1508033739735957555',
        ];

        foreach ($map as $key => $value) {
            DB::table('system_settings')
                ->where('key', $key)
                ->whereRaw("(value IS NULL OR value = '')")
                ->update(['value' => $value]);
        }

        DB::table('organizational_roles')
            ->where('slug', 'styrelsen')
            ->whereNull('discord_role_id')
            ->update(['discord_role_id' => '1508033850146554027']);
    }

    public function down(): void {}
};
