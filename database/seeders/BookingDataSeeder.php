<?php

namespace Database\Seeders;

use App\Models\Booking\BookingActivity;
use App\Models\Booking\BookingCategory;
use App\Models\Booking\BookingResource;
use App\Models\Booking\RecurringBooking;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BookingDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSystemUser();
        $this->seedActivities();
        $this->seedRooms();
        $this->seedRecurringBookings();
    }

    private function seedSystemUser(): void
    {
        User::firstOrCreate(
            ['email' => 'foreningen@armagedon.local'],
            [
                'name'     => 'Armagedon',
                'password' => Hash::make(Str::random(32)),
            ]
        );

        $this->command?->info('Systemanvändare skapad: foreningen@armagedon.local');
    }

    private function seedActivities(): void
    {
        $activities = [
            'Brädspel',
            'Cosplay',
            'Figurspel',
            'Kortspel',
            'Krigsspel',
            'Måleri & Modellbygge',
            'Möten & Organisationsutveckling',
            'Rollspel',
            'Häng på Geddon',
            'Annat',
        ];

        foreach ($activities as $i => $name) {
            BookingActivity::firstOrCreate(
                ['name' => $name],
                ['sort_order' => $i, 'active' => true]
            );
        }

        $this->command?->info('Aktiviteter seedade (' . count($activities) . ' st).');
    }

    private function seedRooms(): void
    {
        $category = BookingCategory::firstOrCreate(
            ['slug' => 'rum'],
            [
                'name'        => 'Rum',
                'description' => 'Bokningsbara rum i föreningens lokal',
                'color'       => '#6366f1',
                'active'      => true,
            ]
        );

        $rooms = [
            ['name' => 'Vita rummet',  'slug' => 'vita-rummet',  'color' => '#94a3b8'],
            ['name' => 'Blåa rummet', 'slug' => 'blaa-rummet',  'color' => '#60a5fa'],
            ['name' => 'Gröna rummet','slug' => 'grona-rummet', 'color' => '#4ade80'],
            ['name' => 'Röda rummet', 'slug' => 'roda-rummet',  'color' => '#f87171'],
            ['name' => 'Bruna rummet','slug' => 'bruna-rummet', 'color' => '#c4a882'],
        ];

        foreach ($rooms as $room) {
            BookingResource::firstOrCreate(
                ['slug' => $room['slug']],
                [
                    'category_id'       => $category->id,
                    'name'              => $room['name'],
                    'color'             => $room['color'],
                    'capacity'          => 1,
                    'min_duration'      => 30,
                    'requires_approval' => false,
                    'active'            => true,
                ]
            );
        }

        $this->command?->info('Rum seedade (' . count($rooms) . ' st).');
    }

    private function seedRecurringBookings(): void
    {
        $vitaRummet = BookingResource::where('slug', 'vita-rummet')->first();
        $blaRummet  = BookingResource::where('slug', 'blaa-rummet')->first();
        $armagedon  = User::where('email', 'foreningen@armagedon.local')->first();

        if (! $vitaRummet || ! $blaRummet || ! $armagedon) {
            $this->command?->warn('Kunde inte skapa upprepande bokningar – rum eller systemanvändare saknas.');
            return;
        }

        $series = [
            [
                'title'          => 'Brädspelskväll',
                'activity'       => 'Brädspel',
                'booking_type'   => 'open',
                'start_time'     => '18:00',
                'end_time'       => '00:00',
                'frequency_type' => 'weekly',
                'weekday'        => 4, // Torsdag
                'week_interval'  => 1,
                'month_week'     => null,
                'resource_id'    => $vitaRummet->id,
            ],
            [
                'title'          => 'Figurspelskväll',
                'activity'       => 'Figurspel',
                'booking_type'   => 'open',
                'start_time'     => '16:00',
                'end_time'       => '00:00',
                'frequency_type' => 'weekly',
                'weekday'        => 2, // Tisdag
                'week_interval'  => 1,
                'month_week'     => null,
                'resource_id'    => $blaRummet->id,
            ],
            [
                'title'          => '40k Games Day',
                'activity'       => 'Figurspel',
                'booking_type'   => 'open',
                'start_time'     => '10:00',
                'end_time'       => '18:00',
                'frequency_type' => 'monthly_weekday',
                'weekday'        => 6, // Lördag
                'week_interval'  => 1,
                'month_week'     => 2, // Andra lördagen
                'resource_id'    => $vitaRummet->id,
            ],
            [
                'title'          => '40k Games Day',
                'activity'       => 'Figurspel',
                'booking_type'   => 'open',
                'start_time'     => '10:00',
                'end_time'       => '18:00',
                'frequency_type' => 'monthly_weekday',
                'weekday'        => 6,
                'week_interval'  => 1,
                'month_week'     => 2,
                'resource_id'    => $blaRummet->id,
            ],
        ];

        foreach ($series as $data) {
            $exists = RecurringBooking::where('resource_id', $data['resource_id'])
                ->where('title', $data['title'])
                ->exists();

            if ($exists) {
                continue;
            }

            $rec = RecurringBooking::create([
                'resource_id'    => $data['resource_id'],
                'user_id'        => $armagedon->id,
                'title'          => $data['title'],
                'activity'       => $data['activity'],
                'booking_type'   => $data['booking_type'],
                'start_time'     => $data['start_time'],
                'end_time'       => $data['end_time'],
                'frequency_type' => $data['frequency_type'],
                'weekday'        => $data['weekday'],
                'week_interval'  => $data['week_interval'],
                'month_week'     => $data['month_week'],
            ]);

            $room  = BookingResource::find($data['resource_id']);
            $count = $rec->generateUntil(now()->addYear());
            $this->command?->line("  \"{$data['title']}\" ({$room->name}): {$count} tillfällen");
        }
    }
}
