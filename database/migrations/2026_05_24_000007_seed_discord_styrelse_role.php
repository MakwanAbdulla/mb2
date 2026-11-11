<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('organizational_roles')
            ->where('slug', 'styrelsen')
            ->whereNull('discord_role_id')
            ->update(['discord_role_id' => '1508033850146554027']);
    }

    public function down(): void
    {
        DB::table('organizational_roles')
            ->where('slug', 'styrelsen')
            ->where('discord_role_id', '1508033850146554027')
            ->update(['discord_role_id' => null]);
    }
};
