<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $domain = config('services.google_workspace.domain', 'armagedon.se');

        // OrganizationalRole
        DB::table('organizational_roles')->updateOrInsert(
            ['slug' => 'ekonomi'],
            [
                'name'                     => 'Ekonomi',
                'slug'                     => 'ekonomi',
                'description'              => 'Hanterar föreningens ekonomi och fakturor.',
                'cross_period_access'      => false,
                'provision_google_workspace' => false,
                'discord_role_id'          => null,
                'created_at'               => now(),
                'updated_at'               => now(),
            ]
        );

        $roleId = DB::table('organizational_roles')->where('slug', 'ekonomi')->value('id');

        // Active period (permanent / custom)
        DB::table('organizational_role_periods')->updateOrInsert(
            ['organizational_role_id' => $roleId, 'name' => 'Ekonomi'],
            [
                'organizational_role_id' => $roleId,
                'name'                   => 'Ekonomi',
                'period_type'            => 'custom',
                'year'                   => null,
                'custom_start'           => '2026-01-01',
                'custom_end'             => '2099-12-31',
                'forming_start'          => null,
                'active_start'           => '2026-01-01',
                'active_end'             => '2099-12-31',
                'reporting_end'          => '2099-12-31',
                'status'                 => 'active',
                'created_at'             => now(),
                'updated_at'             => now(),
            ]
        );

        $periodId = DB::table('organizational_role_periods')
            ->where('organizational_role_id', $roleId)
            ->where('name', 'Ekonomi')
            ->value('id');

        // Add Örjan Almén (member_id=442) as current member
        $memberId = DB::table('members')->where('id', 442)->value('id');
        if ($memberId) {
            $member = DB::table('members')->where('id', $memberId)->first();
            DB::table('organizational_role_members')->updateOrInsert(
                ['organizational_role_period_id' => $periodId, 'member_id' => $memberId],
                [
                    'organizational_role_period_id' => $periodId,
                    'member_id'                     => $memberId,
                    'name'                          => trim(($member->first_name ?? '') . ' ' . ($member->last_name ?? '')),
                    'email'                         => DB::table('users')->where('id', $member->user_id ?? 0)->value('email') ?? '',
                    'title'                         => null,
                    'transition_role'               => 'current',
                    'active_from'                   => '2026-01-01',
                    'active_until'                  => null,
                    'created_at'                    => now(),
                    'updated_at'                    => now(),
                ]
            );
        }

        // Link workspace accounts to the Ekonomi role
        foreach (["fakturor@{$domain}", "ekonomi@{$domain}"] as $email) {
            DB::table('workspace_shared_accounts')->updateOrInsert(
                ['email' => $email],
                [
                    'email'                  => $email,
                    'display_name'           => match ($email) {
                        "fakturor@{$domain}" => 'Fakturor Armagedon',
                        default              => 'Ekonomi Armagedon',
                    },
                    'description'            => null,
                    'section_id'             => null,
                    'organizational_role_id' => $roleId,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        $roleId = DB::table('organizational_roles')->where('slug', 'ekonomi')->value('id');

        if ($roleId) {
            $periodIds = DB::table('organizational_role_periods')
                ->where('organizational_role_id', $roleId)
                ->pluck('id');

            DB::table('organizational_role_members')->whereIn('organizational_role_period_id', $periodIds)->delete();
            DB::table('organizational_role_periods')->where('organizational_role_id', $roleId)->delete();
            DB::table('organizational_roles')->where('id', $roleId)->delete();
        }

        $domain = config('services.google_workspace.domain', 'armagedon.se');
        DB::table('workspace_shared_accounts')
            ->whereIn('email', ["fakturor@{$domain}", "ekonomi@{$domain}"])
            ->delete();
    }
};
