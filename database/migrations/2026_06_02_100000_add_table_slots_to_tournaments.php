<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_phases', function (Blueprint $table) {
            $table->unsignedTinyInteger('tables_available')->nullable()->after('group_count')
                ->comment('How many physical tables are available. Null = unlimited.');
        });

        Schema::table('tournament_rounds', function (Blueprint $table) {
            $table->json('slot_times')->nullable()->after('status')
                ->comment('{"1": "14:00", "2": "15:30"} — start times per time slot');
        });

        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->unsignedTinyInteger('table_number')->nullable()->after('status');
            $table->unsignedTinyInteger('time_slot')->default(1)->after('table_number')
                ->comment('Which pass/session within the round this match belongs to (1, 2, ...)');
        });
    }

    public function down(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->dropColumn(['table_number', 'time_slot']);
        });
        Schema::table('tournament_rounds', function (Blueprint $table) {
            $table->dropColumn('slot_times');
        });
        Schema::table('tournament_phases', function (Blueprint $table) {
            $table->dropColumn('tables_available');
        });
    }
};
