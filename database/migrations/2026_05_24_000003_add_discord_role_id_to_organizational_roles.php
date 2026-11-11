<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizational_roles', function (Blueprint $table) {
            $table->string('discord_role_id')->nullable()->after('provision_google_workspace');
        });

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'discord.role_member'],
            [
                'category'    => 'discord',
                'label'       => 'Discord-roll för aktiva medlemmar',
                'description' => 'Discord Role ID som tilldelas alla aktiva medlemmar.',
                'value'       => '',
                'type'        => 'string',
            ]
        );
    }

    public function down(): void
    {
        Schema::table('organizational_roles', function (Blueprint $table) {
            $table->dropColumn('discord_role_id');
        });

        DB::table('system_settings')->where('key', 'discord.role_member')->delete();
    }
};
