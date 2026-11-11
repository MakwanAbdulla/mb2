<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index(['fiscal_year_id', 'date']);
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->index(['account_id', 'superseded_at']);
            $table->index(['cost_center_id', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['fiscal_year_id', 'date']);
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'superseded_at']);
            $table->dropIndex(['cost_center_id', 'superseded_at']);
        });
    }
};
