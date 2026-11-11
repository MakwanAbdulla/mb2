<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('discord_access_token')->nullable()->after('discord_linked_at');
            $table->text('discord_refresh_token')->nullable()->after('discord_access_token');
            $table->timestamp('discord_token_expires_at')->nullable()->after('discord_refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['discord_access_token', 'discord_refresh_token', 'discord_token_expires_at']);
        });
    }
};
