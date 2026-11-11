<?php

namespace App\Services;

use App\Models\Organization\OrganizationalRolePeriod;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DiscordService
{
    private const API = 'https://discord.com/api/v10';

    private function botToken(): ?string
    {
        return config('services.discord.bot_token') ?: null;
    }

    private function guildId(): ?string
    {
        return SystemSetting::get('discord.guild_id') ?: config('services.discord.guild_id') ?: null;
    }

    public function isConfigured(): bool
    {
        return (bool) $this->botToken() && (bool) $this->guildId();
    }

    /**
     * Resolve which Discord role IDs a user should have.
     */
    public function resolveRoles(User $user): array
    {
        $roles = [];

        $memberRoleId = SystemSetting::get('discord.role_member');
        if ($memberRoleId && $user->member?->isActive()) {
            $roles[] = $memberRoleId;
        }

        if ($user->member) {
            $orgRoleIds = OrganizationalRolePeriod::whereHas('members', fn ($q) => $q->where('member_id', $user->member->id)
                    ->where('transition_role', \App\Models\Organization\OrganizationalRoleMember::TRANSITION_CURRENT))
                ->where('status', OrganizationalRolePeriod::STATUS_ACTIVE)
                ->with('organizationalRole')
                ->get()
                ->pluck('organizationalRole.discord_role_id')
                ->filter()
                ->values()
                ->toArray();

            $roles = array_merge($roles, $orgRoleIds);
        }

        return array_values(array_unique(array_filter($roles)));
    }

    /**
     * Sync a single user's Discord roles. No-op if not configured or user has no discord_id.
     */
    public function syncUser(User $user): void
    {
        if (! $this->isConfigured() || ! $user->discord_id) {
            return;
        }

        $desiredRoles = $this->resolveRoles($user);
        $currentRoles = $this->getMemberRoles($user->discord_id);

        if ($currentRoles === null) {
            // User not in guild — nothing to do
            return;
        }

        $managedRoleIds = array_filter(array_merge(
            [SystemSetting::get('discord.role_member')],
            OrganizationalRolePeriod::query()
                ->whereHas('organizationalRole', fn ($q) => $q->whereNotNull('discord_role_id'))
                ->with('organizationalRole')
                ->get()
                ->pluck('organizationalRole.discord_role_id')
                ->unique()
                ->values()
                ->toArray(),
        ));

        // Keep any roles we don't manage, plus the ones we want
        $newRoles = array_values(array_unique(array_merge(
            array_diff($currentRoles, $managedRoleIds),
            $desiredRoles,
        )));

        if (array_diff($newRoles, $currentRoles) || array_diff($currentRoles, $newRoles)) {
            $this->patchMemberRoles($user->discord_id, $newRoles);
        }
    }

    /**
     * Sync all users who have linked their Discord account.
     */
    public function syncAll(): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        $count = 0;
        User::whereNotNull('discord_id')->with('member')->lazyById()->each(function (User $user) use (&$count) {
            $this->syncUser($user);
            $count++;
        });

        return $count;
    }

    /**
     * Returns the role IDs the user currently has in the guild, or null if not a member.
     */
    private function getMemberRoles(string $discordId): ?array
    {
        $response = Http::withToken($this->botToken(), 'Bot')
            ->get(self::API . "/guilds/{$this->guildId()}/members/{$discordId}");

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            Log::warning('Discord: could not fetch guild member', [
                'discord_id' => $discordId,
                'status'     => $response->status(),
            ]);
            return null;
        }

        return $response->json('roles', []);
    }

    private function patchMemberRoles(string $discordId, array $roleIds): void
    {
        $response = Http::withToken($this->botToken(), 'Bot')
            ->patch(self::API . "/guilds/{$this->guildId()}/members/{$discordId}", [
                'roles' => array_values($roleIds),
            ]);

        if (! $response->successful()) {
            Log::warning('Discord: could not patch member roles', [
                'discord_id' => $discordId,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
        }
    }

    /**
     * Push Linked Role metadata to Discord for a single user.
     */
    public function pushMetadata(User $user): void
    {
        if (! $user->discord_access_token) {
            return;
        }

        $this->refreshTokenIfNeeded($user);
        $user->refresh();

        if (! $user->discord_access_token) {
            return;
        }

        $appId = config('services.discord.client_id');

        $response = Http::withToken($user->discord_access_token)
            ->put("https://discord.com/api/v10/users/@me/applications/{$appId}/role-connection", [
                'platform_name' => 'Armagedon',
                'metadata'      => [
                    'is_member' => $user->member?->isActive() ? '1' : '0',
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('Discord: kunde inte pusha metadata', [
                'user_id' => $user->id,
                'status'  => $response->status(),
            ]);
        }
    }

    /**
     * Push updated metadata for all users with a stored access token.
     */
    public function pushMetadataAll(): int
    {
        $count = 0;
        User::whereNotNull('discord_access_token')->with('member')->lazyById()->each(function (User $user) use (&$count) {
            $this->pushMetadata($user);
            $count++;
        });
        return $count;
    }

    private function refreshTokenIfNeeded(User $user): void
    {
        if (! $user->discord_refresh_token) {
            return;
        }

        if ($user->discord_token_expires_at && $user->discord_token_expires_at->gt(now()->addMinutes(5))) {
            return;
        }

        $response = Http::asForm()->post('https://discord.com/api/v10/oauth2/token', [
            'client_id'     => config('services.discord.client_id'),
            'client_secret' => config('services.discord.client_secret'),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $user->discord_refresh_token,
        ]);

        if (! $response->successful()) {
            Log::warning('Discord: kunde inte förnya token', ['user_id' => $user->id]);
            $user->update(['discord_access_token' => null, 'discord_refresh_token' => null]);
            return;
        }

        $tokens = $response->json();
        $user->update([
            'discord_access_token'     => $tokens['access_token'],
            'discord_refresh_token'    => $tokens['refresh_token'] ?? $user->discord_refresh_token,
            'discord_token_expires_at' => now()->addSeconds((int) $tokens['expires_in']),
        ]);
    }

    /**
     * Send a Discord embed when an event is published.
     */
    public function notifyEventPublished(\App\Models\Events\Event $event): void
    {
        $webhookUrl = SystemSetting::get('discord.webhook.events');
        if (! $webhookUrl) {
            return;
        }

        $firstOccurrence = $event->occurrences()->whereNull('cancelled_at')->first();

        $fields = [];

        if ($firstOccurrence) {
            $fields[] = [
                'name'   => 'Datum',
                'value'  => $firstOccurrence->starts_at->locale('sv')->isoFormat('D MMM YYYY, HH:mm'),
                'inline' => true,
            ];
        }

        if ($event->location_name) {
            $fields[] = [
                'name'   => 'Plats',
                'value'  => $event->location_name,
                'inline' => true,
            ];
        }

        $description = $event->description
            ? Str::limit(strip_tags($event->description), 300)
            : '';

        $url = rtrim(config('app.url'), '/') . '/evenemang/' . $event->slug;

        $embed = [
            'title'       => $event->title,
            'description' => $description,
            'url'         => $url,
            'color'       => 0x5865F2, // Discord blurple
            'fields'      => $fields,
            'footer'      => ['text' => 'Armagedon — Evenemang'],
        ];

        $this->sendWebhook($webhookUrl, $embed);
    }

    /**
     * Send an embed to a Discord webhook URL.
     */
    public function sendWebhook(string $webhookUrl, array $embed): bool
    {
        $response = Http::timeout(5)->post($webhookUrl, ['embeds' => [$embed]]);

        if (! $response->successful()) {
            Log::warning('Discord: webhook delivery failed', [
                'url'    => $webhookUrl,
                'status' => $response->status(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Post an embed to a webhook and return the created message ID.
     */
    public function createWebhookMessage(string $webhookUrl, array $embed): ?string
    {
        $response = Http::timeout(5)
            ->post($this->webhookEndpoint($webhookUrl, '', ['wait' => 'true']), ['embeds' => [$embed]]);

        if (! $response->successful()) {
            Log::warning('Discord: webhook message could not be created', [
                'status' => $response->status(),
            ]);

            return null;
        }

        return $response->json('id');
    }

    /**
     * Replace the embed of a message previously posted by the same webhook.
     * Returns Discord's HTTP status; 404 means the message no longer exists.
     */
    public function editWebhookMessage(string $webhookUrl, string $messageId, array $embed): int
    {
        $response = Http::timeout(5)
            ->patch($this->webhookEndpoint($webhookUrl, '/messages/'.$messageId), ['embeds' => [$embed]]);

        if (! $response->successful()) {
            Log::warning('Discord: webhook message could not be edited', [
                'message_id' => $messageId,
                'status' => $response->status(),
            ]);
        }

        return $response->status();
    }

    /**
     * Replace the application's slash commands in the configured server.
     * Server commands are available immediately, unlike global ones.
     */
    public function registerGuildCommands(array $commands): bool
    {
        $applicationId = config('services.discord.client_id');

        if (! $this->isConfigured() || ! $applicationId) {
            return false;
        }

        $response = Http::withToken($this->botToken(), 'Bot')
            ->timeout(10)
            ->put(self::API."/applications/{$applicationId}/guilds/{$this->guildId()}/commands", $commands);

        if (! $response->successful()) {
            Log::warning('Discord: slash commands could not be registered', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Append a path and query parameters to a webhook URL while keeping any
     * query string it already has, such as thread_id.
     */
    private function webhookEndpoint(string $webhookUrl, string $path = '', array $query = []): string
    {
        $parts = parse_url($webhookUrl);
        parse_str($parts['query'] ?? '', $existingQuery);

        $url = $parts['scheme'].'://'.$parts['host'].rtrim($parts['path'] ?? '', '/').$path;
        $query = array_merge($existingQuery, $query);

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}
