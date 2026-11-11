<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            // Footer — description & contact
            ['key' => 'footer.description', 'group' => 'footer', 'field_label' => 'Beskrivning i sidfoten',
             'default_text' => 'Armagedon är Borås spelförening för brädspel, miniatyrspel och rollspel. Vi håller till på Katrinehillsgatan 7b, 504 52 Borås.',
             'url' => null],
            ['key' => 'footer.contact', 'group' => 'footer', 'field_label' => 'E-postadress i sidfoten',
             'default_text' => 'kansli@armagedon.se', 'url' => null],

            // Footer — links
            ['key' => 'footer.link1', 'group' => 'footer', 'field_label' => 'Länk 1', 'default_text' => 'Om oss',      'url' => '/om-oss'],
            ['key' => 'footer.link2', 'group' => 'footer', 'field_label' => 'Länk 2', 'default_text' => 'Aktiviteter', 'url' => '/aktiviteter'],
            ['key' => 'footer.link3', 'group' => 'footer', 'field_label' => 'Länk 3', 'default_text' => 'Bli medlem',  'url' => '/bli-medlem'],
            ['key' => 'footer.link4', 'group' => 'footer', 'field_label' => 'Länk 4', 'default_text' => 'Kontakt',     'url' => '/kontakt'],
            ['key' => 'footer.link5', 'group' => 'footer', 'field_label' => 'Länk 5', 'default_text' => '', 'url' => ''],
            ['key' => 'footer.link6', 'group' => 'footer', 'field_label' => 'Länk 6', 'default_text' => '', 'url' => ''],

            // Footer — social media
            ['key' => 'footer.social1', 'group' => 'footer', 'field_label' => 'Social media 1', 'default_text' => '', 'url' => ''],
            ['key' => 'footer.social2', 'group' => 'footer', 'field_label' => 'Social media 2', 'default_text' => '', 'url' => ''],
            ['key' => 'footer.social3', 'group' => 'footer', 'field_label' => 'Social media 3', 'default_text' => '', 'url' => ''],
            ['key' => 'footer.social4', 'group' => 'footer', 'field_label' => 'Social media 4', 'default_text' => '', 'url' => ''],
            ['key' => 'footer.social5', 'group' => 'footer', 'field_label' => 'Social media 5', 'default_text' => '', 'url' => ''],
            ['key' => 'footer.social6', 'group' => 'footer', 'field_label' => 'Social media 6', 'default_text' => '', 'url' => ''],
        ];

        foreach ($rows as $row) {
            DB::table('help_texts')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, ['updated_at' => now()])
                    + ['created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('help_texts')->whereIn('key', [
            'footer.description', 'footer.contact',
            'footer.link1', 'footer.link2', 'footer.link3', 'footer.link4', 'footer.link5', 'footer.link6',
            'footer.social1', 'footer.social2', 'footer.social3', 'footer.social4', 'footer.social5', 'footer.social6',
        ])->delete();
    }
};
