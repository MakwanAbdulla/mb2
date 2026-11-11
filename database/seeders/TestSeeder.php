<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TestSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TestBookingSeeder::class,
            TestVenueApplicationSeeder::class,
            DevDataSeeder::class,
        ]);
    }
}
