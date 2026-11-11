<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizational_roles', function (Blueprint $table) {
            $table->foreignId('section_id')
                ->nullable()
                ->after('discord_role_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizational_roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
        });
    }
};
