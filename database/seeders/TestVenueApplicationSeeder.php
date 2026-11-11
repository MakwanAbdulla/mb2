<?php

namespace Database\Seeders;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\User;
use App\Models\Booking\VenueApplication;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TestVenueApplicationSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::whereHas('member', fn ($q) => $q->where('status', 'active'))
            ->inRandomOrder()
            ->first();

        if (! $user) {
            $this->command->warn('Inga aktiva medlemmar hittades. Kör TestUserSeeder först.');
            return;
        }

        $resources = BookingResource::where('active', true)->get();

        if ($resources->isEmpty()) {
            $this->command->warn('Inga rum hittades. Kör BookingDataSeeder först.');
            return;
        }

        $allIds = $resources->pluck('id')->all();

        $applications = [
            // Väntande — framtida datum
            [
                'event_name'        => 'Rollspelshelg – Drakar & Demoner',
                'starts_at'         => Carbon::parse('next saturday')->setHour(10),
                'ends_at'           => Carbon::parse('next saturday')->addDay()->setHour(18),
                'participants'      => 12,
                'needs_overnight'   => true,
                'needs_key'         => 'styrelserum',
                'has_alcohol'       => false,
                'event_type'        => 'group',
                'event_type_reason' => 'Inbjudet sällskap, turneringsspel',
                'resource_ids'      => $allIds,
                'notes'             => 'Vi behöver tillgång till hela lokalen för att ställa upp bord.',
                'status'            => 'pending',
            ],
            [
                'event_name'        => 'Styrelsemöte – Warhammer-klubben',
                'starts_at'         => today()->addWeeks(2)->setHour(18),
                'ends_at'           => today()->addWeeks(2)->setHour(21),
                'participants'      => 8,
                'needs_overnight'   => false,
                'needs_key'         => 'none',
                'has_alcohol'       => false,
                'event_type'        => 'group',
                'event_type_reason' => 'Intern styrelsekonstellation',
                'resource_ids'      => [$resources->first()->id],
                'notes'             => null,
                'status'            => 'pending',
            ],
            [
                'event_name'        => 'Privat spelkväll med alkohol',
                'starts_at'         => today()->addWeeks(3)->setHour(17),
                'ends_at'           => today()->addWeeks(3)->setHour(23),
                'participants'      => 20,
                'needs_overnight'   => false,
                'needs_key'         => 'kiosk',
                'has_alcohol'       => true,
                'event_type'        => 'group',
                'event_type_reason' => null,
                'resource_ids'      => $allIds,
                'notes'             => 'Catering anordnas av arrangören.',
                'status'            => 'pending',
            ],

            // Godkänd — framtida datum
            [
                'event_name'        => 'Figurspelsturnering 2026',
                'starts_at'         => today()->addWeeks(5)->setHour(9),
                'ends_at'           => today()->addWeeks(5)->setHour(20),
                'participants'      => 30,
                'needs_overnight'   => false,
                'needs_key'         => 'styrelserum',
                'has_alcohol'       => false,
                'event_type'        => 'open',
                'event_type_reason' => null,
                'resource_ids'      => $allIds,
                'notes'             => 'Turneringen är öppen för alla.',
                'status'            => 'approved',
                'activity'          => 'Figurspel',
                'admin_notes'       => 'Ser bra ut. Godkänt.',
            ],

            // Godkänd — historik
            [
                'event_name'        => 'Julbord för spelgruppen',
                'starts_at'         => today()->subMonths(3)->setHour(18),
                'ends_at'           => today()->subMonths(3)->setHour(23),
                'participants'      => 15,
                'needs_overnight'   => false,
                'needs_key'         => 'none',
                'has_alcohol'       => true,
                'event_type'        => 'group',
                'event_type_reason' => null,
                'resource_ids'      => $allIds,
                'notes'             => null,
                'status'            => 'approved',
                'activity'          => 'Häng på Geddon',
                'admin_notes'       => 'OK, slutet sällskap bekräftat.',
            ],

            // Nekad
            [
                'event_name'        => 'Företagsevent – Teknikbolaget AB',
                'starts_at'         => today()->subMonth()->setHour(14),
                'ends_at'           => today()->subMonth()->setHour(22),
                'participants'      => 50,
                'needs_overnight'   => false,
                'needs_key'         => 'kiosk',
                'has_alcohol'       => false,
                'event_type'        => 'group',
                'event_type_reason' => 'Internt företagsevent',
                'resource_ids'      => $allIds,
                'notes'             => 'Vill hyra hela lokalen för teambuilding.',
                'status'            => 'rejected',
                'admin_notes'       => 'Vi hyr inte ut lokalen till icke-föreningar för kommersiella ändamål.',
            ],
            [
                'event_name'        => 'Bröllopsfest',
                'starts_at'         => today()->subWeeks(6)->setHour(16),
                'ends_at'           => today()->subWeeks(6)->addDay()->setHour(2),
                'participants'      => 60,
                'needs_overnight'   => true,
                'needs_key'         => 'styrelserum',
                'has_alcohol'       => true,
                'event_type'        => 'group',
                'event_type_reason' => null,
                'resource_ids'      => $allIds,
                'notes'             => 'Bröllopsfest med DJ och dans.',
                'status'            => 'rejected',
                'admin_notes'       => 'Lokalen är inte anpassad för denna typ av arrangemang.',
            ],
        ];

        $count = 0;
        foreach ($applications as $i => $data) {
            $bookingIds = null;

            if ($data['status'] === 'approved') {
                $bookingIds = [];
                foreach ($data['resource_ids'] as $resourceId) {
                    $booking = Booking::create([
                        'resource_id'       => $resourceId,
                        'user_id'           => $user->id,
                        'title'             => $data['event_name'],
                        'activity'          => $data['activity'] ?? 'Annat',
                        'booking_type'      => $data['event_type'],
                        'starts_at'         => $data['starts_at'],
                        'ends_at'           => $data['ends_at'],
                        'status'            => 'confirmed',
                        'exempt_from_limit' => true,
                        'notes'             => $data['notes'],
                    ]);
                    $bookingIds[] = $booking->id;
                }
            }

            VenueApplication::create([
                'user_id'           => $user->id,
                'event_name'        => $data['event_name'],
                'responsible_name'  => $user->name,
                'responsible_phone' => '070-000 00 ' . str_pad($i + 1, 2, '0', STR_PAD_LEFT),
                'responsible_email' => $user->email,
                'starts_at'         => $data['starts_at'],
                'ends_at'           => $data['ends_at'],
                'participants'      => $data['participants'],
                'needs_overnight'   => $data['needs_overnight'],
                'needs_key'         => $data['needs_key'],
                'has_alcohol'       => $data['has_alcohol'],
                'event_type'        => $data['event_type'],
                'event_type_reason' => $data['event_type_reason'],
                'resource_ids'      => $data['resource_ids'],
                'activity'          => $data['activity'] ?? null,
                'notes'             => $data['notes'],
                'status'            => $data['status'],
                'admin_notes'       => $data['admin_notes'] ?? null,
                'booking_ids'       => $bookingIds,
            ]);

            $count++;
        }

        $this->command->info("Skapade {$count} testansökningar (pending/approved/rejected).");
    }
}
