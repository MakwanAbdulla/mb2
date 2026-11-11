<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'sections.reaction_emojis'],
            [
                'category'    => 'sections',
                'label'       => 'Reaktions-emojis',
                'description' => 'Emojis tillgängliga för reaktioner på gruppinlägg och kommentarer.',
                'value'       => json_encode(['👍','❤️','😂','😮','😢','🔥','🎉','👀','💯','🤔']),
                'type'        => 'json',
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'sections.reaction_emojis')->delete();
    }
};
