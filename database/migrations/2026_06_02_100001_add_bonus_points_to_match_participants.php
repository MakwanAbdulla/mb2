<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_match_participants', function (Blueprint $table) {
            $table->smallInteger('bonus_points')->default(0)->after('is_winner')
                ->comment('Extra match points earned through in-game achievements');
        });
    }

    public function down(): void
    {
        Schema::table('tournament_match_participants', fn (Blueprint $t) => $t->dropColumn('bonus_points'));
    }
};
