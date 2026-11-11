<?php

namespace Database\Seeders;

use App\Models\Membership\MembershipFee;
use Illuminate\Database\Seeder;

class MembershipFeeSeeder extends Seeder
{
    public function run(): void
    {
        $fees = [
            [
                'membership_type' => 'single',
                'amount'          => 90000, // 900 kr
                'min_age'         => null,
                'max_age'         => null,
                'sort_order'      => 1,
                'is_active'       => true,
            ],
            [
                'membership_type' => 'youth',
                'amount'          => 5000, // 50 kr
                'min_age'         => 0,
                'max_age'         => 20,
                'sort_order'      => 2,
                'is_active'       => true,
            ],
        ];

        foreach ($fees as $fee) {
            MembershipFee::firstOrCreate(
                ['year' => 2026, 'membership_type' => $fee['membership_type']],
                $fee + ['year' => 2026]
            );
        }
    }
}
