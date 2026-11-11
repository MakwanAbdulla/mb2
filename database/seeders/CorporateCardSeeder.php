<?php

namespace Database\Seeders;

use App\Models\Accounting\CorporateCard;
use App\Models\Membership\Member;
use Illuminate\Database\Seeder;

/**
 * Seeds Armagedon's corporate cards (föreningskort).
 *
 * Member IDs are stable CogWorks member numbers — seeder must run
 * after import:cogworks so the members already exist.
 *
 * Card references match the K-codes that appear in Swedbank's
 * transaction Text column, e.g. "SNABBGROSSCLUB BORAS K3491".
 */
class CorporateCardSeeder extends Seeder
{
    private const CARDS = [
        ['member_number' => 901,  'card_reference' => 'K8001', 'label' => null, 'issuer' => 'Swedbank'],
        ['member_number' => 1397, 'card_reference' => 'K3491', 'label' => null, 'issuer' => 'Swedbank'],
    ];

    public function run(): void
    {
        foreach (self::CARDS as $data) {
            $member = Member::where('membership_number', $data['member_number'])->first();

            if (! $member) {
                $this->command->warn(
                    "CorporateCardSeeder: medlem #{$data['member_number']} hittades inte — hoppar över kort {$data['card_reference']}."
                );
                continue;
            }

            CorporateCard::updateOrCreate(
                ['card_reference' => $data['card_reference']],
                [
                    'member_id' => $member->id,
                    'label'     => $data['label'],
                    'issuer'    => $data['issuer'],
                    'is_active' => true,
                ],
            );

            $this->command->info(
                "Kort {$data['card_reference']} → {$member->full_name} (#{$data['member_number']})"
            );
        }
    }
}
