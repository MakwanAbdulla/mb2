<?php

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Services\DiscordService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {

    public string $guildId       = '';
    public string $roleMember    = '';
    public string $webhookEvents   = '';
    public string $webhookBookings = '';
    public bool   $saved         = false;

    public ?string $metadataResult  = null;
    public bool    $metadataSuccess = false;
    public ?string $syncResult      = null;
    public bool    $syncSuccess     = false;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('settings.manage'), 403);

        $this->guildId       = (string) SystemSetting::get('discord.guild_id', '');
        $this->roleMember    = (string) SystemSetting::get('discord.role_member', '');
        $this->webhookEvents = (string) SystemSetting::get('discord.webhook.events', '');
        $this->webhookBookings = (string) SystemSetting::get('discord.webhook.bookings', '');
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);

        $this->validate([
            'guildId'       => ['nullable', 'string', 'max:30'],
            'roleMember'    => ['nullable', 'string', 'max:30'],
            'webhookEvents' => ['nullable', 'url', 'max:500'],
            'webhookBookings' => ['nullable', 'url', 'max:500'],
        ], [
            'webhookEvents.url' => 'Webhook-URL:en måste vara en giltig URL.',
            'webhookBookings.url' => 'Webhook-URL:en måste vara en giltig URL.',
        ]);

        $map = [
            'discord.guild_id'       => $this->guildId,
            'discord.role_member'    => $this->roleMember,
            'discord.webhook.events' => $this->webhookEvents,
            'discord.webhook.bookings' => $this->webhookBookings,
        ];

        foreach ($map as $key => $value) {
            $old = SystemSetting::get($key, '');
            SystemSetting::set($key, $value ?? '');
            if ($old !== $value) {
                AuditLog::record('settings_updated', 'system_settings', $key);
            }
        }

        $this->saved = true;
    }

    public function testWebhook(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);

        if (! $this->webhookEvents) {
            return;
        }

        app(DiscordService::class)->sendWebhook($this->webhookEvents, [
            'title'       => 'Testmeddelande från Armagedon',
            'description' => 'Webhook-konfigurationen fungerar.',
            'color'       => 0x5865F2,
            'footer'      => ['text' => 'Armagedon — Systeminställningar'],
        ]);

        $this->saved = true;
    }

    public function testBookingWebhook(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);

        if (! $this->webhookBookings) {
            return;
        }

        app(DiscordService::class)->sendWebhook($this->webhookBookings, [
            'title'       => 'Testmeddelande från Armagedon',
            'description' => 'Webhook för bokningssammanfattning fungerar.',
            'color'       => 0x5865F2,
            'footer'      => ['text' => 'Armagedon — Systeminställningar'],
        ]);

        $this->saved = true;
    }

    public function syncRoles(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);

        $discord = app(DiscordService::class);

        if (! $discord->isConfigured()) {
            $this->syncResult  = 'Discord är inte konfigurerat (Bot-token eller Guild ID saknas).';
            $this->syncSuccess = false;
            return;
        }

        $roles = $discord->syncAll();
        $meta  = $discord->pushMetadataAll();

        $this->syncResult  = "Synkade roller för {$roles} konton, uppdaterade metadata för {$meta} konton.";
        $this->syncSuccess = true;
        AuditLog::record('discord_roles_synced', 'system_settings', 'discord');
    }

    public function registerMetadata(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);

        $appId    = config('services.discord.client_id');
        $botToken = config('services.discord.bot_token');

        if (! $appId || ! $botToken) {
            $this->metadataResult  = 'DISCORD_CLIENT_ID eller DISCORD_BOT_TOKEN saknas i konfigurationen.';
            $this->metadataSuccess = false;
            return;
        }

        $response = \Illuminate\Support\Facades\Http::withToken($botToken, 'Bot')
            ->put("https://discord.com/api/v10/applications/{$appId}/role-connections/metadata", [
                [
                    'key'         => 'is_member',
                    'name'        => 'Aktiv medlem',
                    'description' => 'Är aktiv medlem i Armagedon',
                    'type'        => 7,
                ],
            ]);

        if ($response->successful()) {
            $this->metadataResult  = 'Metadata registrerat hos Discord.';
            $this->metadataSuccess = true;
            AuditLog::record('discord_metadata_registered', 'system_settings', 'discord');
        } else {
            $this->metadataResult  = 'Misslyckades: ' . $response->body();
            $this->metadataSuccess = false;
        }
    }
}; ?>

<div class="space-y-6">
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <div class="flex items-center gap-2">
                <x-bootstrap-icon name="discord" class="size-4 text-indigo-500" />
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Discord</h2>
            </div>
        </div>
        <div class="p-6 space-y-5">
            @if($saved)
                <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                    Sparades.
                </div>
            @endif

            {{-- Server --}}
            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Server</p>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Guild ID (Server ID)</label>
                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Högerklicka på servern i Discord → Copy Server ID (kräver Developer Mode).</p>
                <input wire:model="guildId" type="text"
                       class="inp mt-2 font-mono text-sm" placeholder="t.ex. 1234567890123456789" />
                @error('guildId') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            {{-- Roller --}}
            <div class="border-t border-zinc-100 pt-5 dark:border-zinc-700">
                <p class="mb-4 text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Roller</p>

                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Discord-roll för aktiva medlemmar</label>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Högerklicka på rollen i Discord → Copy Role ID. Tilldelas alla med aktivt medlemskap.</p>
                    <input wire:model="roleMember" type="text"
                           class="inp mt-2 font-mono text-sm" placeholder="t.ex. 1234567890123456789" />
                    @error('roleMember') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                    Org-rollspecifika Discord-roller (Styrelsen, Revisorer osv.) konfigureras under
                    <a href="{{ route('admin.org-roles.index') }}" wire:navigate class="text-complement hover:underline">Organisationsroller</a>.
                    Rollsynk körs automatiskt dagligen 04:15.
                </p>

                <div class="mt-4 flex items-center gap-3">
                    <flux:button wire:click="syncRoles" wire:loading.attr="disabled" wire:target="syncRoles" size="sm">
                        Synkronisera roller nu
                    </flux:button>
                    <x-bootstrap-icon wire:loading wire:target="syncRoles" name="arrow-repeat" class="size-4 animate-spin text-zinc-400 dark:text-zinc-500" />

                    @if($syncResult)
                        <span @class([
                            'text-sm',
                            'text-green-700 dark:text-green-400' => $syncSuccess,
                            'text-red-700 dark:text-red-400'     => !$syncSuccess,
                        ])>{{ $syncResult }}</span>
                    @endif
                </div>
            </div>

            {{-- Webhooks --}}
            <div class="border-t border-zinc-100 pt-5 dark:border-zinc-700">
                <p class="mb-4 text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Webhooks</p>

                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Evenemang — webhook-URL</label>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                        Skickar ett meddelande när ett evenemang publiceras. Skapa under Kanalinställningar → Integrations → Webhooks i Discord.
                    </p>
                    <div class="mt-2 flex gap-2">
                        <input wire:model="webhookEvents" type="url"
                               placeholder="https://discord.com/api/webhooks/..."
                               class="flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        <button wire:click="testWebhook" type="button"
                                class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">
                            Testa
                        </button>
                    </div>
                    @error('webhookEvents') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                
                <div class="mt-5">
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Bokningssammanfattning — webhook-URL</label>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                        Skickar dagens bokningar och lediga rum till kanalen. Lämna tomt för att inaktivera.
                    </p>
                    <div class="mt-2 flex gap-2">
                        <input wire:model="webhookBookings" type="url"
                               placeholder="https://discord.com/api/webhooks/..."
                               class="flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        <button wire:click="testBookingWebhook" type="button"
                                class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">
                            Testa
                        </button>
                    </div>
                    @error('webhookBookings') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Linked Roles --}}
            <div class="border-t border-zinc-100 pt-5 dark:border-zinc-700">
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Länkade roller</p>
                <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
                    Med Länkade roller kan Discord-användare verifiera sin medlemsstatus direkt i Discord.
                    Steg 1: lägg till callback-URL:en i
                    <a href="https://discord.com/developers/applications" target="_blank" rel="noopener" class="text-complement hover:underline">Discord Developer Portal</a>
                    (OAuth2 → Redirects). Steg 2: klicka på knappen nedan för att registrera metadata hos Discord.
                    Steg 3: skapa en Länkad roll i serverinställningarna som kräver "Aktiv medlem = sant".
                </p>

                <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <p class="mb-1 text-xs font-medium text-zinc-500 dark:text-zinc-400">Callback-URL (klistra in i Developer Portal)</p>
                    <code class="block break-all text-xs text-zinc-700 dark:text-zinc-300">{{ route('discord.linked-role.callback') }}</code>
                </div>

                <div class="mt-4 flex items-center gap-3">
                    <flux:button wire:click="registerMetadata" wire:loading.attr="disabled" wire:target="registerMetadata" size="sm">
                        Registrera metadata hos Discord
                    </flux:button>
                    <x-bootstrap-icon wire:loading wire:target="registerMetadata" name="arrow-repeat" class="size-4 animate-spin text-zinc-400 dark:text-zinc-500" />

                    @if($metadataResult)
                        <span @class([
                            'text-sm',
                            'text-green-700 dark:text-green-400' => $metadataSuccess,
                            'text-red-700 dark:text-red-400'     => !$metadataSuccess,
                        ])>{{ $metadataResult }}</span>
                    @endif
                </div>
            </div>

            <div class="flex justify-end border-t border-zinc-100 pt-4 dark:border-zinc-700">
                <flux:button wire:click="save" variant="primary" size="sm">Spara</flux:button>
            </div>
        </div>
    </div>
</div>
