<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Services\BookingOverviewService;
use Illuminate\Console\Command;

class SendDiscordBookingOverview extends Command
{
    protected $signature = 'discord:booking-overview';

    protected $description = 'Uppdaterar Discord-meddelandet med bokningar för kommande 7 dagar';

    public function handle(BookingOverviewService $overview): int
    {
        if (! SystemSetting::get('discord.webhook.bookings')) {
            $this->warn('Ingen webhook konfigurerad för bokningar.');

            return self::SUCCESS;
        }

        if (! $overview->publish()) {
            $this->error('Discord tog inte emot översikten. Se loggen för detaljer.');

            return self::FAILURE;
        }

        $this->info('Bokningsöversikten är uppdaterad.');

        return self::SUCCESS;
    }
}
