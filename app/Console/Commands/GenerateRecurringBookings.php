<?php

namespace App\Console\Commands;

use App\Models\Booking\RecurringBooking;
use Illuminate\Console\Command;

class GenerateRecurringBookings extends Command
{
    protected $signature   = 'bookings:generate-recurring';
    protected $description = 'Genererar instanser för återkommande bokningar ett år framåt';

    public function handle(): int
    {
        $until  = now()->addYear();
        $series = RecurringBooking::query()
            ->where(function ($q) {
                $q->whereNull('ends_on')->orWhere('ends_on', '>=', today());
            })
            ->get();

        $total = 0;
        foreach ($series as $recurring) {
            $created = $recurring->generateUntil($until);
            $total  += $created;

            if ($created > 0) {
                $this->line("  [{$recurring->id}] {$recurring->title}: +{$created} bokningar");
            }
        }

        $this->info("Klart. {$total} nya bokningar skapades från {$series->count()} serier.");

        return self::SUCCESS;
    }
}
