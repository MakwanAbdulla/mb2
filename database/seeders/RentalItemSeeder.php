<?php

namespace Database\Seeders;

use App\Models\Rental\RentalItem;
use App\Models\Rental\RentalItemType;
use Illuminate\Database\Seeder;

class RentalItemSeeder extends Seeder
{
    public function run(): void
    {
        // ── Stora skåp (nummer 1–35) ──────────────────────────────────────────
        $large = RentalItemType::firstOrCreate(
            ['name' => 'Stort skåp'],
            [
                'description'    => 'Stort förvaringsskåp i föreningens lokaler.',
                'price_per_year' => 50000, // 500 kr/år
                'is_active'      => true,
                'sort_order'     => 1,
            ]
        );

        foreach (range(1, 35) as $number) {
            RentalItem::firstOrCreate(
                ['rental_item_type_id' => $large->id, 'label' => (string) $number],
                ['is_available' => true, 'sort_order' => $number]
            );
        }

        // ── Små skåp (nummer 56–85) ───────────────────────────────────────────
        $small = RentalItemType::firstOrCreate(
            ['name' => 'Litet skåp'],
            [
                'description'    => 'Litet förvaringsskåp i föreningens lokaler.',
                'price_per_year' => 20000, // 200 kr/år
                'is_active'      => true,
                'sort_order'     => 2,
            ]
        );

        foreach (range(56, 85) as $number) {
            RentalItem::firstOrCreate(
                ['rental_item_type_id' => $small->id, 'label' => (string) $number],
                ['is_available' => true, 'sort_order' => $number]
            );
        }

        // ── Styrelsenyckel (P1–P15) ───────────────────────────────────────────
        $styrelse = RentalItemType::firstOrCreate(
            ['name' => 'Styrelsenyckel'],
            [
                'price_per_year'      => 0,
                'is_active'           => true,
                'is_admin_controlled' => true,
                'sort_order'          => 3,
            ]
        );

        foreach (range(1, 15) as $n) {
            RentalItem::firstOrCreate(
                ['rental_item_type_id' => $styrelse->id, 'label' => 'P' . $n],
                ['is_available' => true, 'sort_order' => $n]
            );
        }

        // ── Kiosknyckel (K1–K10) ─────────────────────────────────────────────
        $kiosk = RentalItemType::firstOrCreate(
            ['name' => 'Kiosknyckel'],
            [
                'price_per_year'      => 0,
                'is_active'           => true,
                'is_admin_controlled' => true,
                'sort_order'          => 4,
            ]
        );

        foreach (range(1, 10) as $n) {
            RentalItem::firstOrCreate(
                ['rental_item_type_id' => $kiosk->id, 'label' => 'K' . $n],
                ['is_available' => true, 'sort_order' => $n]
            );
        }
    }
}
