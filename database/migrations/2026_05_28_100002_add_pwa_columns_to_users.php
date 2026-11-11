<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('pwa_first_seen_at')->nullable()->after('remember_token');
            $table->timestamp('pwa_last_seen_at')->nullable()->after('pwa_first_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pwa_first_seen_at', 'pwa_last_seen_at']);
        });
    }
};
