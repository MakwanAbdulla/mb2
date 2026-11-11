<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('gender', 10)->nullable()->after('date_of_birth');
        });

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'statistics.municipality_grant_age_min'],
            [
                'category'    => 'statistics',
                'value'       => '7',
                'type'        => 'integer',
                'label'       => 'Bidragsberättigad — lägsta ålder',
                'description' => 'Nedre åldersgräns för bidragsberättigade medlemmar i kommunrapporten.',
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'statistics.municipality_grant_age_max'],
            [
                'category'    => 'statistics',
                'value'       => '25',
                'type'        => 'integer',
                'label'       => 'Bidragsberättigad — högsta ålder',
                'description' => 'Övre åldersgräns för bidragsberättigade medlemmar i kommunrapporten.',
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'statistics.municipality_postal_min'],
            [
                'category'    => 'statistics',
                'value'       => '50100',
                'type'        => 'integer',
                'label'       => 'Kommunrapport — lägsta postnummer',
                'description' => 'Postnummerintervall för kommunen (nedre gräns, utan mellanslag).',
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'statistics.municipality_postal_max'],
            [
                'category'    => 'statistics',
                'value'       => '52499',
                'type'        => 'integer',
                'label'       => 'Kommunrapport — högsta postnummer',
                'description' => 'Postnummerintervall för kommunen (övre gräns, utan mellanslag).',
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'statistics.municipality_name'],
            [
                'category'    => 'statistics',
                'value'       => 'Borås Stad',
                'type'        => 'string',
                'label'       => 'Kommunrapport — kommunnamn',
                'description' => 'Kommunnamnet som visas i rapporthuvudet.',
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('gender');
        });

        DB::table('system_settings')->whereIn('key', [
            'statistics.municipality_grant_age_min',
            'statistics.municipality_grant_age_max',
            'statistics.municipality_postal_min',
            'statistics.municipality_postal_max',
            'statistics.municipality_name',
        ])->delete();
    }
};
