<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_match_participants', function (Blueprint $table) {
            $table->decimal('match_points', 8, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tournament_match_participants', function (Blueprint $table) {
            $table->unsignedTinyInteger('match_points')->nullable()->change();
        });
    }
};
