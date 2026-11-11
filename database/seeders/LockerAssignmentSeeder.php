<?php

namespace Database\Seeders;

use App\Models\Membership\Member;
use App\Models\Rental\Rental;
use App\Models\Rental\RentalItem;
use App\Models\Rental\RentalItemType;
use Illuminate\Database\Seeder;

/**
 * One-time seeder: imports locker and key assignments from CogWorks export.
 * Source: CogWorks "Egna fält" export, 2025-05-04.
 * Run once on fresh migration — safe to re-run (uses firstOrCreate).
 */
class LockerAssignmentSeeder extends Seeder
{
    // membership_number => [litet_skåp, stort_skåp, styrelsenyckel, kiosknyckel]
    // null = not assigned. Large locker labels normalized to strip leading zeros.
    private const ASSIGNMENTS = [
        // ── Från listan "Litet skåp" ──────────────────────────────────────────
        '901'  => ['litet' => '72', 'stort' => null, 'styrelse' => '15', 'kiosk' => null],
        '1387' => ['litet' => '74', 'stort' => null, 'styrelse' => '10', 'kiosk' => null],
        '1390' => ['litet' => '60', 'stort' => '15', 'styrelse' => null, 'kiosk' => null],
        '1'    => ['litet' => '65', 'stort' => '17', 'styrelse' => null, 'kiosk' => null],
        '3'    => ['litet' => '75', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '1620' => ['litet' => '85', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '235'  => ['litet' => '73', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '13'   => ['litet' => '62', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '57'   => ['litet' => '77', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '604'  => ['litet' => '82', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '254'  => ['litet' => '84', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '1444' => ['litet' => '61', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '1435' => ['litet' => '81', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '922'  => ['litet' => '58', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '1565' => ['litet' => '70', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '1366' => ['litet' => '69', 'stort' => null, 'styrelse' => null, 'kiosk' => '2'],
        '1367' => ['litet' => '68', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '1384' => ['litet' => '66', 'stort' => '11', 'styrelse' => null, 'kiosk' => null],
        '70'   => ['litet' => '67', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '1525' => ['litet' => '78', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '42'   => ['litet' => '71', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '1602' => ['litet' => '76', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '7'    => ['litet' => '63', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '23'   => ['litet' => '59', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '11'   => ['litet' => '57', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '1380' => ['litet' => '64', 'stort' => null, 'styrelse' => '4',  'kiosk' => null],
        '913'  => ['litet' => '79', 'stort' => null, 'styrelse' => null, 'kiosk' => null],
        '1571' => ['litet' => '56', 'stort' => null, 'styrelse' => null, 'kiosk' => null],

        // ── Från listan "Stort skåp" ──────────────────────────────────────────
        '268'  => ['litet' => null, 'stort' => '9',  'styrelse' => null, 'kiosk' => null],
        '161'  => ['litet' => null, 'stort' => '25', 'styrelse' => null, 'kiosk' => null],
        '60'   => ['litet' => null, 'stort' => '31', 'styrelse' => null, 'kiosk' => '4'],
        '44'   => ['litet' => null, 'stort' => '8',  'styrelse' => 'X',  'kiosk' => null],
        '1354' => ['litet' => null, 'stort' => '23', 'styrelse' => null, 'kiosk' => null],
        '238'  => ['litet' => null, 'stort' => '7',  'styrelse' => null, 'kiosk' => null],
        '567'  => ['litet' => null, 'stort' => '27', 'styrelse' => null, 'kiosk' => null],
        '549'  => ['litet' => null, 'stort' => '11', 'styrelse' => null, 'kiosk' => null],
        '65'   => ['litet' => null, 'stort' => '21', 'styrelse' => null, 'kiosk' => null],
        '264'  => ['litet' => null, 'stort' => '29', 'styrelse' => null, 'kiosk' => '3'],
        '9'    => ['litet' => null, 'stort' => '20', 'styrelse' => null, 'kiosk' => null],
        '554'  => ['litet' => null, 'stort' => '5',  'styrelse' => null, 'kiosk' => null],
        '29'   => ['litet' => null, 'stort' => '34', 'styrelse' => null, 'kiosk' => null],
        '194'  => ['litet' => null, 'stort' => '16', 'styrelse' => null, 'kiosk' => null],
        '944'  => ['litet' => null, 'stort' => '22', 'styrelse' => '9',  'kiosk' => null],
        '19'   => ['litet' => null, 'stort' => '12', 'styrelse' => null, 'kiosk' => null],
        '36'   => ['litet' => null, 'stort' => '32', 'styrelse' => null, 'kiosk' => null],
        '33'   => ['litet' => null, 'stort' => '33', 'styrelse' => null, 'kiosk' => null],
        '14'   => ['litet' => null, 'stort' => '18', 'styrelse' => null, 'kiosk' => null],
        '165'  => ['litet' => null, 'stort' => '35', 'styrelse' => null, 'kiosk' => null],
        '118'  => ['litet' => null, 'stort' => '14', 'styrelse' => null, 'kiosk' => null],
        '163'  => ['litet' => null, 'stort' => '1',  'styrelse' => null, 'kiosk' => '9'],
        '107'  => ['litet' => null, 'stort' => '2',  'styrelse' => null, 'kiosk' => null],
        '72'   => ['litet' => null, 'stort' => '19', 'styrelse' => null, 'kiosk' => null],
        '18'   => ['litet' => null, 'stort' => '28', 'styrelse' => null, 'kiosk' => null],
        '12'   => ['litet' => null, 'stort' => '6',  'styrelse' => '6',  'kiosk' => null],
        '61'   => ['litet' => null, 'stort' => '13', 'styrelse' => null, 'kiosk' => null],
        '28'   => ['litet' => null, 'stort' => '10', 'styrelse' => null, 'kiosk' => null],
        '77'   => ['litet' => null, 'stort' => '4',  'styrelse' => null, 'kiosk' => '5'],
        '50'   => ['litet' => null, 'stort' => '26', 'styrelse' => null, 'kiosk' => null],
        '197'  => ['litet' => null, 'stort' => '3',  'styrelse' => null, 'kiosk' => null],
        '30'   => ['litet' => null, 'stort' => '30', 'styrelse' => '2',  'kiosk' => null],
    ];

    public function run(): void
    {
        $types = RentalItemType::all()->keyBy('name');

        $typeMap = [
            'litet'    => $types->get('Litet skåp'),
            'stort'    => $types->get('Stort skåp'),
            'styrelse' => $types->get('Styrelsenyckel'),
            'kiosk'    => $types->get('Kiosknyckel'),
        ];

        $created  = 0;
        $skipped  = 0;
        $notFound = [];

        foreach (self::ASSIGNMENTS as $memberNumber => $assignment) {
            $member = Member::where('membership_number', (string) $memberNumber)->first();

            if (! $member) {
                $notFound[] = $memberNumber;
                continue;
            }

            foreach ($assignment as $key => $label) {
                if ($label === null) {
                    continue;
                }

                $type = $typeMap[$key];
                if (! $type) {
                    continue;
                }

                $item = RentalItem::firstOrCreate(
                    ['rental_item_type_id' => $type->id, 'label' => $label],
                );

                $existing = Rental::where('rental_item_id', $item->id)
                    ->whereNull('ended_at')
                    ->first();

                if ($existing) {
                    $skipped++;
                    continue;
                }

                Rental::create([
                    'rental_item_id' => $item->id,
                    'member_id'      => $member->id,
                    'price_per_year' => $type->price_per_year,
                    'started_at'     => '2025-01-01',
                    'ended_at'       => null,
                    'notes'          => 'Importerad från CogWorks.',
                ]);

                $created++;
            }
        }

        if ($notFound) {
            $this->command->warn('  Medlemsnummer ej funna: ' . implode(', ', $notFound));
        }

        $this->command->info("  Skåp/nycklar: {$created} skapade, {$skipped} redan tilldelade.");
    }
}
