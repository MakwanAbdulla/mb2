<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $mailboxes = [
            [
                'name'       => 'Kansliinkorg',
                'purpose'    => 'office',
                'driver'     => 'google_workspace',
                'mailbox'    => 'kansli@armagedon.se',
                'imap_port'  => 993,
                'smtp_port'  => 587,
                'imap_encryption' => 'ssl',
                'imap_folder'     => 'INBOX',
                'is_enabled' => true,
            ],
            [
                'name'       => 'Bokföringsinkorg',
                'purpose'    => 'accounting',
                'driver'     => 'google_workspace',
                'mailbox'    => 'fakturor@armagedon.se',
                'imap_port'  => 993,
                'smtp_port'  => 587,
                'imap_encryption' => 'ssl',
                'imap_folder'     => 'INBOX',
                'is_enabled' => true,
            ],
        ];

        foreach ($mailboxes as $mb) {
            if (DB::table('inbox_mailboxes')->where('purpose', $mb['purpose'])->exists()) {
                continue;
            }
            DB::table('inbox_mailboxes')->insert(array_merge($mb, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        DB::table('inbox_mailboxes')->whereIn('purpose', ['office', 'accounting'])->delete();
    }
};
