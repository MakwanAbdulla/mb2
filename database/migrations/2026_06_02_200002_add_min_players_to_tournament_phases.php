<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_phases', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_players_per_match')->default(2)->after('players_per_match')
                ->comment('Minimum players per table. Distribution will not create tables below this size.');
        });
    }

    public function down(): void
    {
        Schema::table('tournament_phases', fn (Blueprint $t) => $t->dropColumn('min_players_per_match'));
    }
};
