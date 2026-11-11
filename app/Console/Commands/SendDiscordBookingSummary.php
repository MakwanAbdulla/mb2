<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Services\BookingDaySummary;
use App\Services\DiscordService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendDiscordBookingSummary extends Command
{
    protected $signature = 'discord:booking-summary {--date= : Datum att sammanfatta (Y-m-d), standard är idag}';

    protected $description = 'Skickar dagens bokningar, lediga rum och evenemang till Discord';

    public function handle(DiscordService $discord, BookingDaySummary $summary): int
    {
        $webhookUrl = SystemSetting::get('discord.webhook.bookings');

        if (! $webhookUrl) {
            $this->warn('Ingen webhook konfigurerad för bokningar.');

            return self::SUCCESS;
        }

        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now();

        if (! $discord->sendWebhook($webhookUrl, $summary->dayEmbed($date))) {
            $this->error('Discord tog inte emot meddelandet. Se loggen för detaljer.');

            return self::FAILURE;
        }

        $this->info('Skickade sammanfattning för '.$date->format('d/m Y').'.');

        return self::SUCCESS;
    }
}
