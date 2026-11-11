<?php

use App\Models\ApiClient;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('API-klienter');

new class extends Component {

    public string  $name       = '';
    public string  $scopeInput = '';   // comma-separated, empty = unrestricted
    public bool    $showCreate = false;

    // Shown once after creation or secret regeneration
    public ?string $newClientId     = null;
    public ?string $newClientSecret = null;
    public ?string $newClientName   = null;

    public ?int  $confirmDeleteId     = null;
    public ?int  $confirmRegenerateId = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);
    }

    #[\Livewire\Attributes\Computed]
    public function clients()
    {
        return ApiClient::with('user')->latest()->get();
    }

    public function create(): void
    {
        $this->validate([
            'name' => 'required|string|max:100',
        ]);

        $scopes = $this->scopeInput
            ? array_values(array_filter(array_map('trim', explode(',', $this->scopeInput))))
            : null;

        if ($scopes) {
            $unknown = array_diff($scopes, \Spatie\Permission\Models\Permission::whereIn('name', $scopes)->pluck('name')->all());
            if ($unknown) {
                $this->addError('scopeInput', 'Okända behörigheter: '.implode(', ', $unknown));

                return;
            }
        }

        ['client' => $client, 'plain_secret' => $secret] = ApiClient::provision($this->name, $scopes);

        AuditLog::record('api_client_created', 'system', $client->name);

        $this->newClientId     = $client->client_id;
        $this->newClientSecret = $secret;
        $this->newClientName   = $client->name;
        $this->name            = '';
        $this->scopeInput      = '';
        $this->showCreate      = false;

        unset($this->clients);
    }

    public function toggleEnabled(int $id): void
    {
        $client = ApiClient::findOrFail($id);
        $client->update(['enabled' => ! $client->enabled]);

        if (! $client->enabled) {
            // Revoke tokens when disabling
            $client->user->tokens()->delete();
        }

        AuditLog::record(
            $client->enabled ? 'api_client_enabled' : 'api_client_disabled',
            'system',
            $client->name,
        );

        unset($this->clients);
    }

    public function confirmRegenerate(int $id): void
    {
        $this->confirmRegenerateId = $id;
    }

    public function regenerate(): void
    {
        $client = ApiClient::findOrFail($this->confirmRegenerateId);
        $secret = $client->regenerateSecret();

        AuditLog::record('api_client_secret_regenerated', 'system', $client->name);

        $this->newClientId         = $client->client_id;
        $this->newClientSecret     = $secret;
        $this->newClientName       = $client->name;
        $this->confirmRegenerateId = null;
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmDeleteId = $id;
    }

    public function delete(): void
    {
        $client = ApiClient::with('user')->findOrFail($this->confirmDeleteId);
        $name   = $client->name;

        $client->user->tokens()->delete();
        $client->user->delete();   // cascades to api_clients via FK

        AuditLog::record('api_client_deleted', 'system', $name);

        $this->confirmDeleteId = null;
        unset($this->clients);
    }

    public function dismissSecret(): void
    {
        $this->newClientId     = null;
        $this->newClientSecret = null;
        $this->newClientName   = null;
    }
};
?>

<div class="py-8">
<div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-zinc-900 dark:text-white">API-klienter</h1>
            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Maskin-till-maskin-integrationer med client_id / client_secret.</p>
        </div>
        <button wire:click="$set('showCreate', true)" class="btn btn-primary">
            <x-bootstrap-icon name="plus-lg" class="size-4" />
            Ny klient
        </button>
    </div>

    {{-- ── Ny hemlig nyckel (visas bara en gång) ── --}}
    @if ($newClientSecret)
        <div class="mb-6 rounded-xl border border-green-300 bg-green-50 p-5 dark:border-green-700/40 dark:bg-green-900/20">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-semibold text-green-800 dark:text-green-300">
                    Klient skapad: {{ $newClientName }}
                </h2>
                <button wire:click="dismissSecret" class="text-xs text-green-600 hover:underline dark:text-green-400">Stäng</button>
            </div>
            <p class="mb-3 text-xs text-green-700 dark:text-green-400">
                Kopiera värden nedan — <strong>client_secret visas aldrig igen.</strong>
            </p>
            <div class="space-y-2 font-mono text-sm">
                <div class="flex items-center gap-2">
                    <span class="w-28 shrink-0 text-xs text-zinc-500 dark:text-zinc-400">client_id</span>
                    <code class="flex-1 rounded bg-white px-3 py-1.5 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100">{{ $newClientId }}</code>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-28 shrink-0 text-xs text-zinc-500 dark:text-zinc-400">client_secret</span>
                    <code class="flex-1 rounded bg-white px-3 py-1.5 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100">{{ $newClientSecret }}</code>
                </div>
            </div>
            <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                Hämta token: <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-700">POST {{ url('/api/v1/auth/client-tokens') }}</code>
                med <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-700">client_id</code> och <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-700">client_secret</code> i JSON-body.
            </p>
        </div>
    @endif

    {{-- ── Skapa-formulär ── --}}
    @if ($showCreate)
        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="mb-4 font-semibold text-zinc-900 dark:text-zinc-100">Ny API-klient</h2>
            <div class="space-y-4">
                <div>
                    <label class="lbl">Namn <span class="text-red-500">*</span></label>
                    <input wire:model="name" type="text" placeholder="t.ex. BSK-integration" class="inp mt-1 w-full" />
                    @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Scope (valfritt)</label>
                    <input wire:model="scopeInput" type="text"
                           placeholder="t.ex. members.view, events.view  (tomt = alla servicekontots behörigheter)"
                           class="inp mt-1 w-full" />
                    @error('scopeInput') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Komma-separerade behörighetsnamn som begränsar klienten. Klienten når bara det som både står här och som servicekontot har – ge servicekontot behörigheterna under Användare. Lämna tomt för att klienten ska få alla servicekontots behörigheter.</p>
                </div>
            </div>
            <div class="mt-4 flex items-center gap-3">
                <button wire:click="create" class="btn btn-primary">Skapa klient</button>
                <button wire:click="$set('showCreate', false)" class="btn btn-ghost">Avbryt</button>
            </div>
        </div>
    @endif

    {{-- ── Lista ── --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        @forelse ($this->clients as $client)
            <div class="flex flex-col gap-3 border-b border-zinc-100 px-5 py-4 last:border-0 dark:border-zinc-700/50
                        sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $client->name }}</span>
                        @if ($client->enabled)
                            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">Aktiv</span>
                        @else
                            <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400">Inaktiv</span>
                        @endif
                    </div>
                    <p class="mt-0.5 font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $client->client_id }}</p>
                    @if ($client->scopes)
                        <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                            Scope: {{ implode(', ', $client->scopes) }}
                        </p>
                    @endif
                    @if ($client->last_used_at)
                        <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                            Senast använd {{ $client->last_used_at->diffForHumans() }}
                        </p>
                    @endif
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <button wire:click="toggleEnabled({{ $client->id }})"
                            class="btn btn-ghost text-xs">
                        {{ $client->enabled ? 'Inaktivera' : 'Aktivera' }}
                    </button>
                    <button wire:click="confirmRegenerate({{ $client->id }})"
                            class="btn btn-ghost text-xs">
                        Ny nyckel
                    </button>
                    <button wire:click="confirmDelete({{ $client->id }})"
                            class="btn btn-danger text-xs">
                        Ta bort
                    </button>
                </div>
            </div>
        @empty
            <div class="p-10 text-center text-sm text-zinc-400 dark:text-zinc-500">
                Inga API-klienter konfigurerade.
            </div>
        @endforelse
    </div>

    {{-- ── Bekräfta: ny nyckel ── --}}
    @if ($confirmRegenerateId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-sm rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="mb-2 font-semibold text-zinc-900 dark:text-zinc-100">Generera ny nyckel?</h2>
                <p class="mb-5 text-sm text-zinc-500 dark:text-zinc-400">
                    Alla befintliga tokens för den här klienten upphör omedelbart. Integrationerna slutar fungera tills de uppdateras med den nya nyckeln.
                </p>
                <div class="flex gap-3">
                    <button wire:click="regenerate" class="btn btn-danger">Ja, generera ny</button>
                    <button wire:click="$set('confirmRegenerateId', null)" class="btn btn-ghost">Avbryt</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Bekräfta: ta bort ── --}}
    @if ($confirmDeleteId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-sm rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="mb-2 font-semibold text-zinc-900 dark:text-zinc-100">Ta bort klient?</h2>
                <p class="mb-5 text-sm text-zinc-500 dark:text-zinc-400">
                    Klienten, dess servicekonto och alla utfärdade tokens raderas permanent.
                </p>
                <div class="flex gap-3">
                    <button wire:click="delete" class="btn btn-danger">Ta bort</button>
                    <button wire:click="$set('confirmDeleteId', null)" class="btn btn-ghost">Avbryt</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Integrationsdokumentation ──────────────────────────────────────── --}}
    <div class="mt-8 rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-900/50">
        <h2 class="mb-3 font-semibold text-zinc-800 dark:text-zinc-200">Integrera mot API:et</h2>
        <ol class="space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
            <li><strong class="text-zinc-800 dark:text-zinc-200">1. Hämta token</strong><br>
                <code class="mt-1 block rounded bg-zinc-100 px-3 py-2 font-mono text-xs dark:bg-zinc-800">
                    POST {{ url('/api/v1/auth/client-tokens') }}<br>
                    { "client_id": "…", "client_secret": "…", "token_name": "valfritt" }
                </code>
            </li>
            <li class="pt-1"><strong class="text-zinc-800 dark:text-zinc-200">2. Använd token</strong><br>
                Skicka <code class="rounded bg-zinc-100 px-1 font-mono text-xs dark:bg-zinc-800">Authorization: Bearer &lt;token&gt;</code> i alla anrop.
            </li>
            <li class="pt-1"><strong class="text-zinc-800 dark:text-zinc-200">3. API-dokumentation</strong><br>
                <a href="{{ route('api-docs') }}" wire:navigate class="text-brand-dark underline dark:text-brand">
                    {{ url('/api-docs') }}
                </a>
            </li>
        </ol>
    </div>

</div>
</div>
