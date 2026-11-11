<?php

namespace Database\Seeders;

use App\Models\Membership\FamilyGroup;
use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fix family account assignments and create FamilyGroup records.
 *
 * CogWorks returns family members in unpredictable order, so sometimes
 * a child gets the user account instead of the parent. This seeder:
 *  1. Re-links the correct member to each shared email's User account.
 *  2. Creates FamilyGroup records with all members + pivot data.
 *
 * Must run AFTER import:cogworks.
 */
class FamilyAccountSeeder extends Seeder
{
    /**
     * Families identified from shared-email CogWorks import.
     *
     * Format: [email, admin_cogworks_id, [all_cogworks_ids_in_family]]
     *
     * admin_cogworks_id = billing contact + gets the user account.
     * All adults can manage the family.
     * Role (adult/child) is determined by date_of_birth at runtime.
     */
    private array $families = [
        ['henrik.ammilon@gmail.com',      1215731, [1215808, 1215731, 1215809]],
        ['rexregis.peter@gmail.com',       1215725, [1215750, 1215725, 1215749, 1215748]],
        ['robert@forslindberg.se',         1215866, [1215874, 1215866, 1215875]],
        ['matildagustafsson93@gmail.com',  1174536, [1215898, 1174536, 1215841]],
        ['robinschelin@hotmail.com',       1215802, [1215876, 1215802, 1215804, 1215897]],
        ['phreddp@gmail.com',             1204541, [1215670, 1204541]],
        ['u99marj@hotmail.com',            1215228, [1215823, 1215228, 1215824]],
        // Already correct — account went to right person, still need FamilyGroup
        ['mellqvist@outlook.com',          1205055, [1205055, 1215800, 1215671]],
        ['anders_land@yahoo.com',          645576,  [645576, 645574]],
        ['david.ljungquist@gmail.com',     1215643, [1215643, 1217229]],
        ['epiphe@gmail.com',              1215794, [1215794, 1215795]],
        ['diswrool1@hotmail.com',          1215818, [1215818, 1215817]],
    ];

    /** CogWorks IDs that need user account re-assignment (wrong person got account) */
    private array $accountFixes = [
        // [email, correct_cw_id, wrong_cw_ids[]]
        ['henrik.ammilon@gmail.com',      1215731, [1215808, 1215809]],
        ['rexregis.peter@gmail.com',       1215725, [1215750, 1215749, 1215748]],
        ['robert@forslindberg.se',         1215866, [1215874, 1215875]],
        ['matildagustafsson93@gmail.com',  1174536, [1215898, 1215841]],
        ['robinschelin@hotmail.com',       1215802, [1215876, 1215804, 1215897]],
        ['phreddp@gmail.com',             1204541, [1215670]],
        ['u99marj@hotmail.com',            1215228, [1215823, 1215824]],
    ];

    public function run(): void
    {
        $this->fixAccounts();
        $this->createFamilyGroups();
    }

    private function fixAccounts(): void
    {
        foreach ($this->accountFixes as [$email, $correctCwId, $wrongCwIds]) {
            $correctMember = Member::where('cogworks_id', $correctCwId)->first();
            if (! $correctMember) {
                $this->command->warn("  FamilyAccountSeeder: hittade inte member cw:{$correctCwId} — hoppar över.");
                continue;
            }

            $user = User::where('email', $email)->first();
            if (! $user) {
                $this->command->warn("  FamilyAccountSeeder: hittade inte user för {$email} — hoppar över.");
                continue;
            }

            // Clear cogworks_id from any other user that currently holds it
            User::where('cogworks_id', $correctMember->cogworks_id)
                ->where('id', '!=', $user->id)
                ->update(['cogworks_id' => null, 'cogworks_username' => null]);

            $user->update([
                'cogworks_id'       => $correctMember->cogworks_id,
                'cogworks_username' => $correctMember->cogworks_username,
                'name'              => $correctMember->full_name,
            ]);

            $correctMember->update(['user_id' => $user->id]);

            Member::whereIn('cogworks_id', $wrongCwIds)->update(['user_id' => null]);

            $this->command->line("  ✓ {$correctMember->full_name} länkad till {$email}");
        }
    }

    private function createFamilyGroups(): void
    {
        $maxChildAge = (int) \App\Models\SystemSetting::get('family.max_child_age', 18);
        $adultCutoff = now()->subYears($maxChildAge);

        foreach ($this->families as [$email, $adminCwId, $allCwIds]) {
            $members = Member::whereIn('cogworks_id', $allCwIds)->get()->keyBy('cogworks_id');

            if ($members->isEmpty()) {
                continue;
            }

            $adminMember = $members->get($adminCwId);
            if (! $adminMember) {
                continue;
            }

            // Create or find the family group
            $group = FamilyGroup::create(['administrator_member_id' => $adminMember->id]);

            // Attach all members with role + has_own_account
            foreach ($members as $member) {
                $isChild = $member->date_of_birth && $member->date_of_birth->gt($adultCutoff);

                // Update member's family_group_id
                $member->update(['family_group_id' => $group->id]);

                // Insert pivot record
                DB::table('family_members')->insertOrIgnore([
                    'family_group_id' => $group->id,
                    'member_id'       => $member->id,
                    'role'            => $isChild ? FamilyGroup::ROLE_CHILD : FamilyGroup::ROLE_ADULT,
                    'has_own_account' => $member->user_id !== null ? 1 : 0,
                ]);
            }

            $this->command->line("  ✓ Familjegrupp skapad för {$email} ({$members->count()} medlemmar)");
        }
    }
}
