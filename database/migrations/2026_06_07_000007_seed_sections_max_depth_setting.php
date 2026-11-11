<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'sections.max_depth'],
            [
                'category'    => 'sections',
                'label'       => 'Max gruppdjup',
                'description' => 'Hur många nivåer grupper får nästlas. 1 = bara en nivå undergrupper, 0 = inga undergrupper tillåtna.',
                'value'       => '1',
                'type'        => 'integer',
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'sections.max_depth')->delete();
    }
};
