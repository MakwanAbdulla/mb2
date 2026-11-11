<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Creates inbox mailboxes for org roles and users with Google Workspace email.
 * The purpose-based mailboxes (office/accounting) are seeded via migration
 * 2026_05_05_000008, which runs before this seeder. This seeder handles the
 * rows that depend on data created by other seeders (org roles, members).
 */
class InboxMailboxSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedOrgRoleMailboxes();
        $this->seedUserMailboxes();
    }

    private function seedOrgRoleMailboxes(): void
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

    private function seedUserMailboxes(): void
    {
        $ownerType = 'App\\Models\\User';

        $members = DB::table('workspace_accounts')
            ->where('workspace_accounts.accountable_type', 'member')
            ->join('members', 'members.id', '=', 'workspace_accounts.accountable_id')
            ->whereNotNull('members.user_id')
            ->join('users', 'users.id', '=', 'members.user_id')
            ->select('members.user_id', 'workspace_accounts.email as google_workspace_email', 'users.name')
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
}
