<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Temporary: rewrite @armagedon.se → @armagedon.se.test-google-a.com in outgoing inbox mail
// while MX records still point to the web host instead of Google Workspace.
// Remove this setting (or clear 'value') once MX is updated.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'mail.domain_rewrite'],
            [
                'value'    => json_encode(['from' => 'armagedon.se', 'to' => 'armagedon.se.test-google-a.com']),
                'type'     => 'json',
                'category' => 'mail',
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'mail.domain_rewrite')->delete();
    }
};
