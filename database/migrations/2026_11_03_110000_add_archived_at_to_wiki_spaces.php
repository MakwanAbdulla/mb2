<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // An archived space disappears from lists and becomes read-only, but its
    // pages stay readable through their links.
    public function up(): void
    {
        Schema::table('wiki_spaces', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('read_access');
        });
    }

    public function down(): void
    {
        Schema::table('wiki_spaces', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
