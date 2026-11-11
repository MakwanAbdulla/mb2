<?php

namespace Database\Seeders;

use App\Models\Membership\Member;
use App\Models\User;
use App\Services\CogWorksService;
use Illuminate\Database\Seeder;

class TestUserSeeder extends Seeder
{
    public function run(): void
    {
        $cogworks = app(CogWorksService::class);

        // ── Orjan — admin ────────────────────────────────────────────────────
        // CogWorks import creates both the User and Member records.
        // We just ensure the admin role is assigned afterwards.
        $orjan = User::where('email', 'orjan.almen@gmail.com')->first();
        if ($orjan) {
            $orjan->assignRole('admin');
            $this->syncCogworksUsername($orjan, $cogworks);
        }

        $niklas = User::where('email', 'niklas@mardby.se')->first();
        if ($niklas) {
            $niklas->assignRole('admin');
            $this->syncCogworksUsername($niklas, $cogworks);
        }

        $matilda = User::where('email', 'matildagustafsson93@gmail.com')->first();
        if ($matilda) {
            $matilda->assignRole('admin');
            $this->syncCogworksUsername($matilda, $cogworks);
        }

        // ── Test accounts (no CogWorks equivalent) ───────────────────────────

        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'              => 'Anna Admin',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $admin->assignRole('admin');

        Member::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'user_id'         => $admin->id,
                'first_name'      => 'Anna',
                'last_name'       => 'Admin',
                'membership_type' => Member::TYPE_SINGLE,
                'status'          => Member::STATUS_ACTIVE,
            ]
        );

        $treasurer = User::firstOrCreate(
            ['email' => 'kassaansvarig@example.com'],
            [
                'name'              => 'Kim Kassaansvarig',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $treasurer->assignRole('treasurer');

        Member::firstOrCreate(
            ['email' => 'kassaansvarig@example.com'],
            [
                'user_id'         => $treasurer->id,
                'first_name'      => 'Kim',
                'last_name'       => 'Kassaansvarig',
                'membership_type' => Member::TYPE_SINGLE,
                'status'          => Member::STATUS_ACTIVE,
            ]
        );

        $editor = User::firstOrCreate(
            ['email' => 'redaktor@example.com'],
            [
                'name'              => 'Rebecka Redaktör',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $editor->assignRole('website-editor');

        Member::firstOrCreate(
            ['email' => 'redaktor@example.com'],
            [
                'user_id'         => $editor->id,
                'first_name'      => 'Rebecka',
                'last_name'       => 'Redaktör',
                'membership_type' => Member::TYPE_SINGLE,
                'status'          => Member::STATUS_ACTIVE,
            ]
        );

        $manager = User::firstOrCreate(
            ['email' => 'kassor@example.com'],
            [
                'name'              => 'Karl Kassör',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $manager->assignRole('member-manager');

        Member::firstOrCreate(
            ['email' => 'kassor@example.com'],
            [
                'user_id'         => $manager->id,
                'first_name'      => 'Karl',
                'last_name'       => 'Kassör',
                'membership_type' => Member::TYPE_SINGLE,
                'status'          => Member::STATUS_ACTIVE,
            ]
        );

        $memberUser = User::firstOrCreate(
            ['email' => 'medlem@example.com'],
            [
                'name'              => 'Maja Medlem',
                'password'          => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        Member::firstOrCreate(
            ['email' => 'medlem@example.com'],
            [
                'user_id'         => $memberUser->id,
                'first_name'      => 'Maja',
                'last_name'       => 'Medlem',
                'membership_type' => Member::TYPE_SINGLE,
                'status'          => Member::STATUS_ACTIVE,
            ]
        );

        Member::firstOrCreate(
            ['email' => 'ny@example.com'],
            [
                'user_id'         => null,
                'first_name'      => 'Nils',
                'last_name'       => 'Nybörjare',
                'membership_type' => Member::TYPE_SINGLE,
                'status'          => Member::STATUS_PENDING,
            ]
        );
    }

    private function syncCogworksUsername(User $user, CogWorksService $cogworks): void
    {
        if ($user->cogworks_username !== null) {
            return;
        }

        $cwUser = $cogworks->findByEmail($user->email);
        if ($cwUser && ! empty($cwUser['username'])) {
            $user->update(['cogworks_username' => $cwUser['username']]);
        }
    }
}
