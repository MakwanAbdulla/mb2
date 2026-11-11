<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // JSON array of field definitions: [{key, label, type: text|select|textarea, required, options: []}]
            $table->json('registration_fields')->nullable()->after('is_public');
        });

        Schema::table('tournament_participants', function (Blueprint $table) {
            // Stores the participant's submitted registration data keyed by field key
            $table->json('registration_data')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tournament_participants', fn (Blueprint $t) => $t->dropColumn('registration_data'));
        Schema::table('tournaments', fn (Blueprint $t) => $t->dropColumn('registration_fields'));
    }
};
