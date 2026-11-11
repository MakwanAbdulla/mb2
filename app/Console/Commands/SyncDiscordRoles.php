<?php

namespace App\Console\Commands;

use App\Services\DiscordService;
use Illuminate\Console\Command;

class SyncDiscordRoles extends Command
{
    protected $signature = 'discord:sync-roles {--user= : Synka en enskild användare (ID)}';
    protected $description = 'Synkronisera Discord-roller för alla länkade konton';

    public function handle(DiscordService $discord): int
    {
        if (! $discord->isConfigured()) {
            $this->warn('Discord är inte konfigurerat (DISCORD_BOT_TOKEN eller DISCORD_GUILD_ID saknas).');
            return self::SUCCESS;
        }

        if ($userId = $this->option('user')) {
            $user = \App\Models\User::find($userId);
            if (! $user) {
                $this->error("Användare {$userId} hittades inte.");
                return self::FAILURE;
            }
            if (! $user->discord_id) {
                $this->warn("Användare {$userId} har inget kopplat Discord-konto.");
                return self::SUCCESS;
            }
            $discord->syncUser($user);
            $this->info("Synkroniserade Discord-roller för {$user->name}.");
            return self::SUCCESS;
        }

        $count = $discord->syncAll();
        $this->info("Synkroniserade Discord-roller för {$count} användare.");

        $metaCount = $discord->pushMetadataAll();
        $this->info("Uppdaterade Linked Role-metadata för {$metaCount} användare.");

        return self::SUCCESS;
    }
}
