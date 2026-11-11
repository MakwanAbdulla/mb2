<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_match_participants', function (Blueprint $table) {
            $table->decimal('game_score', 10, 2)->nullable()->change();
            $table->decimal('bonus_points', 8, 2)->default(0)->change();
        });

        Schema::table('tournament_standings', function (Blueprint $table) {
            $table->decimal('match_points', 10, 2)->default(0)->change();
            $table->decimal('game_points', 10, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tournament_match_participants', function (Blueprint $table) {
            $table->integer('game_score')->nullable()->change();
            $table->smallInteger('bonus_points')->default(0)->change();
        });

        Schema::table('tournament_standings', function (Blueprint $table) {
            $table->integer('match_points')->default(0)->change();
            $table->integer('game_points')->default(0)->change();
        });
    }
};
