<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $role = fn (string $slug) => DB::table('organizational_roles')->where('slug', $slug)->value('id');

        $drives = [
            [
                'name'                   => 'Styrelsen',
                'google_drive_id'        => '0AHUwVhaPnWGsUk9PVA',
                'member_role'            => 'writer',
                'public_access'          => false,
                'organizational_role_id' => $role('styrelsen'),
                'section_id'             => null,
            ],
            [
                'name'                   => 'Publika protokoll och styrdokument',
                'google_drive_id'        => '0APEo8NE8vNRQUk9PVA',
                'member_role'            => 'writer',
                'public_access'          => true,
                'organizational_role_id' => $role('styrelsen'),
                'section_id'             => null,
            ],
            [
                'name'                   => 'Revisorer',
                'google_drive_id'        => '0AJ55G0M7bDUsUk9PVA',
                'member_role'            => 'writer',
                'public_access'          => false,
                'organizational_role_id' => $role('revisorer'),
                'section_id'             => null,
            ],
            [
                'name'                   => 'Valberedning',
                'google_drive_id'        => '0ACXgIyx6hVgrUk9PVA',
                'member_role'            => 'writer',
                'public_access'          => false,
                'organizational_role_id' => $role('valberedningen'),
                'section_id'             => null,
            ],
            [
                'name'                   => 'Borås Spelkonvent',
                'google_drive_id'        => '0AJpxbOL30sy5Uk9PVA',
                'member_role'            => 'writer',
                'public_access'          => false,
                'organizational_role_id' => $role('bsk-kommitten'),
                'section_id'             => null,
            ],
        ];

        foreach ($drives as $drive) {
            if (DB::table('workspace_shared_drives')->where('google_drive_id', $drive['google_drive_id'])->exists()) {
                continue;
            }
            DB::table('workspace_shared_drives')->insert(array_merge($drive, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        DB::table('workspace_shared_drives')->whereIn('google_drive_id', [
            '0AHUwVhaPnWGsUk9PVA',
            '0APEo8NE8vNRQUk9PVA',
            '0AJ55G0M7bDUsUk9PVA',
            '0ACXgIyx6hVgrUk9PVA',
            '0AJpxbOL30sy5Uk9PVA',
        ])->delete();
    }
};
