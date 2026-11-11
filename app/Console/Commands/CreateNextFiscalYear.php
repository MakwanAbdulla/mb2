<?php

namespace App\Console\Commands;

use App\Models\Accounting\FiscalYear;
use Illuminate\Console\Command;

class CreateNextFiscalYear extends Command
{
    protected $signature = 'accounting:create-fiscal-year
                            {year? : År att skapa (standard: innevarande år + 1)}
                            {--force : Skapa även om det redan finns}';

    protected $description = 'Skapar nästa räkenskapsår om det inte redan finns';

    public function handle(): int
    {
        $year = (int) ($this->argument('year') ?? now()->year + 1);

        $existing = FiscalYear::where('name', (string) $year)->first();

        if ($existing && ! $this->option('force')) {
            $locked = $existing->is_locked ? 'låst' : 'öppet';
            $this->info("Räkenskapsår {$year} finns redan ({$locked}).");
            return self::SUCCESS;
        }

        if ($existing) {
            $this->warn("Räkenskapsår {$year} finns redan — hoppar över.");
            return self::SUCCESS;
        }

        FiscalYear::create([
            'name'      => (string) $year,
            'starts_at' => "{$year}-01-01",
            'ends_at'   => "{$year}-12-31",
            'is_locked' => false,
        ]);

        $this->info("Räkenskapsår {$year} skapat ({$year}-01-01 – {$year}-12-31).");

        return self::SUCCESS;
    }
}
