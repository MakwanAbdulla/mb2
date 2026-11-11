<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizational_role_members', function (Blueprint $table) {
            $table->date('access_until')->nullable()->after('active_until')
                ->comment('Overrides grace period — keeps permissions until this date regardless of period status.');
        });

        Schema::table('section_memberships', function (Blueprint $table) {
            $table->foreignId('synced_from_org_role_id')
                ->nullable()
                ->after('status')
                ->constrained('organizational_roles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizational_role_members', function (Blueprint $table) {
            $table->dropColumn('access_until');
        });
        Schema::table('section_memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('synced_from_org_role_id');
        });
    }
};
