<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RegisterDiscordMetadata extends Command
{
    protected $signature = 'discord:register-metadata';
    protected $description = 'Registrera Linked Role-metadata-schema hos Discord';

    public function handle(): int
    {
        $appId    = config('services.discord.client_id');
        $botToken = config('services.discord.bot_token');

        if (! $appId || ! $botToken) {
            $this->error('DISCORD_CLIENT_ID eller DISCORD_BOT_TOKEN saknas.');
            return self::FAILURE;
        }

        $metadata = [
            [
                'key'         => 'is_member',
                'name'        => 'Aktiv medlem',
                'description' => 'Är aktiv medlem i Armagedon',
                'type'        => 7, // BOOLEAN_EQUAL
            ],
        ];

        $response = Http::withToken($botToken, 'Bot')
            ->put("https://discord.com/api/v10/applications/{$appId}/role-connections/metadata", $metadata);

        if ($response->successful()) {
            $this->info('Metadata registrerat hos Discord.');
            return self::SUCCESS;
        }

        $this->error('Misslyckades: ' . $response->body());
        return self::FAILURE;
    }
}
