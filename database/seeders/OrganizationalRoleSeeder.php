<?php

namespace Database\Seeders;

use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\OrganizationalRolePeriod;
use App\Models\Organization\OrganizationalRoleMember;
use App\Models\Membership\Member;
use Illuminate\Database\Seeder;

class OrganizationalRoleSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRoles();
        $this->seedStyrelsenPeriods();
    }

    private function seedRoles(): void
    {
        $roles = [
            [
                'name'                => 'Styrelsen',
                'slug'                => 'styrelsen',
                'description'         => 'Föreningens styrelse, vald på årsmötet.',
                'cross_period_access' => true,
            ],
            [
                'name'                => 'Revisorer',
                'slug'                => 'revisorer',
                'description'         => 'Granskar räkenskaperna. Ser enbart sin egen period.',
                'cross_period_access' => false,
            ],
            [
                'name'                => 'Valberedningen',
                'slug'                => 'valberedningen',
                'description'         => 'Bereder val av styrelse och revisorer.',
                'cross_period_access' => false,
            ],
            [
                'name'                => 'BSK-kommittén',
                'slug'                => 'bsk-kommitten',
                'description'         => 'Ansvarar för Borås Spelkonvent.',
                'cross_period_access' => true,
            ],
        ];

        foreach ($roles as $data) {
            OrganizationalRole::firstOrCreate(['slug' => $data['slug']], $data);
        }
    }

    private function seedStyrelsenPeriods(): void
    {
        $styrelsen = OrganizationalRole::where('slug', 'styrelsen')->first();

        // Styrelsen 2026 — aktiv period
        $period2026 = OrganizationalRolePeriod::firstOrCreate(
            ['organizational_role_id' => $styrelsen->id, 'name' => 'Styrelsen 2026'],
            [
                'status'        => 'active',
                'forming_start' => '2025-10-01',
                'active_start'  => '2026-01-01',
                'active_end'    => '2026-12-31',
                'reporting_end' => '2027-03-31',
            ],
        );

        $members2026 = [
            ['email' => 'matildagustafsson93@gmail.com', 'title' => 'Ordförande',      'transition_role' => 'current'],
            ['email' => 'aggraae@hotmail.com',           'title' => 'Vice ordförande', 'transition_role' => 'current'],
            ['email' => 'niklas@mardby.se',              'title' => 'Sekreterare',     'transition_role' => 'current'],
            ['email' => 'frb.bjorklund@gmail.com',       'title' => 'Kassör',          'transition_role' => 'current'],
            ['email' => 'erikalfredberggren@icloud.com', 'title' => 'Suppleant',       'transition_role' => 'current'],
            ['email' => 'hannes.nederstedt@gmail.com',   'title' => 'Ledamot',         'transition_role' => 'current'],
            ['email' => 'wilma@forslindberg.se',         'title' => 'Suppleant',       'transition_role' => 'current'],
            ['email' => 'korvmakaroner@gmail.com',       'title' => 'Ledamot',         'transition_role' => 'current'],
        ];

        foreach ($members2026 as $data) {
            $member = Member::where('email', $data['email'])->first();
            if (! $member) {
                continue;
            }
            OrganizationalRoleMember::firstOrCreate(
                ['organizational_role_period_id' => $period2026->id, 'member_id' => $member->id],
                ['title' => $data['title'], 'transition_role' => $data['transition_role']],
            );
        }

        // Styrelsen 2027 — under bildning
        OrganizationalRolePeriod::firstOrCreate(
            ['organizational_role_id' => $styrelsen->id, 'name' => 'Styrelsen 2027'],
            [
                'status'        => 'forming',
                'forming_start' => '2026-10-01',
                'active_start'  => '2027-01-01',
                'active_end'    => '2027-12-31',
                'reporting_end' => '2028-03-31',
            ],
        );
    }
}
