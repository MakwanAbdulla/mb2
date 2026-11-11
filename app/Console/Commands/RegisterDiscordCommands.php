<?php

namespace App\Console\Commands;

use App\Services\DiscordCommandHandler;
use App\Services\DiscordService;
use Illuminate\Console\Command;

class RegisterDiscordCommands extends Command
{
    protected $signature = 'discord:register-commands';

    protected $description = 'Registrerar slash-kommandona (/idag, /vecka, /ledigt) på Discord-servern';

    public function handle(DiscordService $discord): int
    {
        if (! $discord->registerGuildCommands(DiscordCommandHandler::definitions())) {
            $this->error('Kunde inte registrera kommandona. Kontrollera bot-token, client ID och guild ID, och se loggen.');

            return self::FAILURE;
        }

        $this->info('Slash-kommandona är registrerade.');

        return self::SUCCESS;
    }
}
