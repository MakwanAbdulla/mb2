<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('mail_templates')->updateOrInsert(
            ['key' => 'tournament_round_start'],
            [
                'key'         => 'tournament_round_start',
                'label'       => 'Turnering — ny runda',
                'description' => 'Skickas när en ny turnerings­runda genereras. Innehåller bordsplacering och eventuell starttid.',
                'subject'     => 'Turnering {{turnering_namn}} — Runda {{runda_nummer}} startar',
                'body'        => implode("\n", [
                    'Hej {{fornamn}}!',
                    '',
                    '{{turnering_namn}} — Runda {{runda_nummer}} är nu klar.',
                    '',
                    '**Din matchplacering:**',
                    '{{match_info}}',
                    '',
                    '{{slot_info}}',
                    '',
                    'Lycka till!',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        DB::table('mail_templates')->where('key', 'tournament_round_start')->delete();
    }
};
