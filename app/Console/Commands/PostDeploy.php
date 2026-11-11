<?php

namespace App\Console\Commands;

use App\Services\DiscordService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Everything that should run after every deploy, after migrations and after
 * the config cache is rebuilt. Each step must be safe to run again and again.
 *
 * - One-time data changes do not belong here: write a migration.
 * - Manual steps (e.g. settings in an external portal) go under
 *   "Efter deploy" in the PR description.
 *
 * Every step runs even if an earlier one fails, so one broken integration does
 * not stop the rest. The command fails at the end if any step failed.
 */
class PostDeploy extends Command
{
    protected $signature = 'app:post-deploy';

    protected $description = 'Kör allt som ska köras efter varje deploy (behörigheter, ikoner, Discord)';

    public function handle(DiscordService $discord): int
    {
        $failed = [];

        foreach ($this->steps($discord) as $label => [$command, $skipReason]) {
            $this->newLine();
            $this->info("==> {$label}");

            if ($skipReason !== null) {
                $this->line("  Hoppar över: {$skipReason}");

                continue;
            }

            try {
                $exitCode = $this->call($command);
            } catch (Throwable $e) {
                $this->error("  {$e->getMessage()}");
                $exitCode = self::FAILURE;
            }

            if ($exitCode !== self::SUCCESS) {
                $failed[] = $label;
            }
        }

        $this->newLine();

        if ($failed !== []) {
            $this->error('Misslyckades: '.implode(', ', $failed));

            return self::FAILURE;
        }

        $this->info('Alla steg efter deploy är klara.');

        return self::SUCCESS;
    }

    /**
     * label => [artisan command, reason to skip or null].
     *
     * @return array<string, array{0: string, 1: ?string}>
     */
    private function steps(DiscordService $discord): array
    {
        $discordApp = $discord->isConfigured() && config('services.discord.client_id');

        return [
            'Behörigheter och roller' => ['permissions:add-missing', null],

            'Bootstrap Icons' => ['icons:sync', null],

            'Discord: slash-kommandon' => ['discord:register-commands', match (true) {
                ! $discordApp => 'DISCORD_CLIENT_ID, DISCORD_BOT_TOKEN eller guild-id saknas',
                ! config('services.discord.public_key') => 'DISCORD_PUBLIC_KEY saknas',
                default => null,
            }],

            'Discord: Linked Roles-metadata' => ['discord:register-metadata',
                config('services.discord.client_id') && config('services.discord.bot_token')
                    ? null
                    : 'DISCORD_CLIENT_ID eller DISCORD_BOT_TOKEN saknas',
            ],
        ];
    }
}
