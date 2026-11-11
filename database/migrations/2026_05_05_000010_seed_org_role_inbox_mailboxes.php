<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ownerType = 'App\\Models\\Organization\\OrganizationalRole';

        $mailboxes = [
            ['slug' => 'revisorer',      'name' => 'Revisorernas inkorg',    'mailbox' => 'revisor@armagedon.se'],
            ['slug' => 'valberedningen', 'name' => 'Valberedningens inkorg', 'mailbox' => 'valberedning@armagedon.se'],
        ];

        foreach ($mailboxes as $cfg) {
            $role = DB::table('organizational_roles')->where('slug', $cfg['slug'])->first();
            if (! $role) {
                continue;
            }

            if (DB::table('inbox_mailboxes')->where('owner_type', $ownerType)->where('owner_id', $role->id)->exists()) {
                continue;
            }

            DB::table('inbox_mailboxes')->insert([
                'name'            => $cfg['name'],
                'driver'          => 'google_workspace',
                'mailbox'         => $cfg['mailbox'],
                'imap_port'       => 993,
                'smtp_port'       => 587,
                'imap_encryption' => 'ssl',
                'imap_folder'     => 'INBOX',
                'is_enabled'      => true,
                'owner_type'      => $ownerType,
                'owner_id'        => $role->id,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        $ownerType = 'App\\Models\\Organization\\OrganizationalRole';

        foreach (['revisorer', 'valberedningen'] as $slug) {
            $role = DB::table('organizational_roles')->where('slug', $slug)->first();
            if ($role) {
                DB::table('inbox_mailboxes')
                    ->where('owner_type', $ownerType)
                    ->where('owner_id', $role->id)
                    ->delete();
            }
        }
    }
};
