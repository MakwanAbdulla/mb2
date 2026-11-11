<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ownerType = 'App\\Models\\User';

        $members = DB::table('members')
            ->whereNotNull('google_workspace_email')
            ->whereNotNull('user_id')
            ->join('users', 'users.id', '=', 'members.user_id')
            ->select('members.user_id', 'members.google_workspace_email', 'users.name')
            ->get();

        foreach ($members as $m) {
            if (DB::table('inbox_mailboxes')->where('owner_type', $ownerType)->where('owner_id', $m->user_id)->exists()) {
                continue;
            }

            DB::table('inbox_mailboxes')->insert([
                'name'            => $m->name . 's inkorg',
                'driver'          => 'google_workspace',
                'mailbox'         => $m->google_workspace_email,
                'imap_port'       => 993,
                'smtp_port'       => 587,
                'imap_encryption' => 'ssl',
                'imap_folder'     => 'INBOX',
                'is_enabled'      => true,
                'owner_type'      => $ownerType,
                'owner_id'        => $m->user_id,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('inbox_mailboxes')
            ->where('owner_type', 'App\\Models\\User')
            ->delete();
    }
};
