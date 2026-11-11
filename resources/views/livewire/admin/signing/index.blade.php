<?php

use App\Enums\SigningStatus;
use App\Models\AuditLog;
use App\Models\Signing\SigningRequest;
use App\Models\Signing\SigningSigner;
use App\Models\SystemSetting;
use App\Services\Signing\DocsignClient;
use App\Services\Signing\SigningService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('E-signering');

new class extends Component {
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    public ?string $balanceError = null;

    public ?string $connectionResult = null;

    public bool $connectionOk = false;

    public string $lowBalanceKr = '';

    public string $signerNotice = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('signing.view') || SigningRequest::canBeCreatedBy(Auth::user()), 403);

        $this->lowBalanceKr = (string) (SigningService::lowBalanceThreshold() / 100);
        $this->signerNotice = (string) SystemSetting::get(SigningService::SIGNER_NOTICE_SETTING, config('services.docsign.signer_notice'));

        // The balance call is free; refresh it if it's more than a few minutes old.
        $checked = SystemSetting::get(SigningService::BALANCE_CHECKED_SETTING);
        if ($this->configured && Auth::user()->can('signing.view') && (! $checked || Carbon::parse($checked)->lt(now()->subMinutes(5)))) {
            $this->refreshBalance();
        }
    }

    #[Computed]
    public function configured(): bool
    {
        return (bool) config('services.docsign.api_key');
    }

    #[Computed]
    public function balance(): ?int
    {
        $value = SystemSetting::get(SigningService::BALANCE_SETTING);

        return $value === null ? null : (int) $value;
    }

    #[Computed]
    public function balanceCheckedAt(): ?Carbon
    {
        $value = SystemSetting::get(SigningService::BALANCE_CHECKED_SETTING);

        return $value ? Carbon::parse($value)->timezone(config('app.timezone')) : null;
    }

    #[Computed]
    public function requests()
    {
        return SigningRequest::query()
            ->visibleTo(Auth::user())
            ->with(['signers', 'creator', 'events' => fn ($q) => $q->limit(1)])
            ->withCount(['signers', 'signers as signed_count' => fn ($q) => $q->whereNotNull('signed_at')])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('reference', ltrim(trim($this->search, " []\t"), '#'))))
            ->latest('id')
            ->paginate(25);
    }

    /** External signers anonymized here but still registered at DocSign. */
    #[Computed]
    public function pendingDocsignDeletion()
    {
        return SigningSigner::whereNotNull('anonymized_at')->whereNotNull('provider_party_id')->orderBy('anonymized_at')->get();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function refreshBalance(): void
    {
        abort_unless(Auth::user()->can('signing.view'), 403);
        $this->balanceError = null;

        try {
            app(SigningService::class)->refreshBalance();
        } catch (Throwable $e) {
            $this->balanceError = 'Kunde inte hämta saldot från DocSign: '.$e->getMessage();
        }

        unset($this->balance, $this->balanceCheckedAt);
    }

    public function testConnection(): void
    {
        abort_unless(Auth::user()->can('signing.manage'), 403);

        try {
            $account = app(DocsignClient::class)->account();
            $this->connectionOk = true;
            $this->connectionResult = 'Kopplingen fungerar. Konto: '.($account['email'] ?? 'okänt').', saldo '.format_ore($account['balance_ore']).'.';
        } catch (Throwable $e) {
            $this->connectionOk = false;
            $this->connectionResult = 'Kopplingen fungerar inte: '.$e->getMessage();
        }
    }

    public function saveSettings(): void
    {
        abort_unless(Auth::user()->can('signing.manage'), 403);

        $this->validate([
            'lowBalanceKr' => 'required|numeric|min:0|max:100000',
            'signerNotice' => 'nullable|string|max:1000',
        ], [
            'lowBalanceKr.required' => 'Ange en gräns i kronor.',
            'lowBalanceKr.numeric' => 'Gränsen måste vara ett belopp i kronor.',
        ]);

        SystemSetting::set(SigningService::LOW_BALANCE_SETTING, (int) round((float) $this->lowBalanceKr * 100), SystemSetting::TYPE_INTEGER);
        SystemSetting::set(SigningService::SIGNER_NOTICE_SETTING, trim($this->signerNotice));
        AuditLog::record('signing_settings_updated', 'signing', 'Inställningar för e-signering');

        $this->dispatch('notify', message: 'Inställningarna är sparade.');
    }

    public function deleteAtDocsign(int $signerId): void
    {
        abort_unless(Auth::user()->can('signing.manage'), 403);

        $signer = SigningSigner::whereNotNull('anonymized_at')->findOrFail($signerId);

        try {
            app(\App\Services\Signing\SigningService::class)->deleteSignerAtDocsign($signer);
            AuditLog::record('signing_signer_removed_at_docsign', 'signing', "Undertecknare {$signer->id} borttagen hos DocSign", $signer);
            $this->dispatch('notify', message: 'Undertecknaren är raderad hos DocSign.');
        } catch (Throwable $e) {
            $this->dispatch('notify', message: 'Kunde inte radera hos DocSign: '.$e->getMessage(), type: 'error');
        }

        unset($this->pendingDocsignDeletion);
    }

    public function statusClasses(SigningStatus $status): string
    {
        return match ($status->color()) {
            'amber' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
            'blue' => 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
            'green' => 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300',
            'red' => 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
            default => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300',
        };
    }
}; ?>

<x-slot:header>
    <x-page-header title="E-signering" subtitle="Dokument som skickats för signering med BankID via DocSign." permission="signing.view" />
</x-slot:header>

<div class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">

    {{-- Balance (signing.view); signing.create only sees their own requests --}}
    <div class="grid gap-4 md:grid-cols-3">
        @can('signing.view')
        @php
            $threshold = \App\Services\Signing\SigningService::lowBalanceThreshold();
            $low = $this->balance !== null && $this->balance < $threshold;
        @endphp
        <div @class([
            'rounded-xl border p-5 md:col-span-2',
            'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10' => $low,
            'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900' => ! $low,
        ])>
            @if (! $this->configured)
                <p class="text-sm font-medium text-zinc-900 dark:text-white">DocSign är inte konfigurerat</p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Lägg in <code>DOCSIGN_API_KEY</code> i miljöns <code>.env</code> för att kunna skicka dokument för signering.</p>
            @else
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Saldo hos DocSign</p>
                        <p class="mt-1 text-3xl font-bold text-zinc-900 dark:text-white">
                            {{ $this->balance !== null ? format_ore($this->balance) : '–' }}
                        </p>
                        @if ($this->balance !== null)
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                Räcker till {{ intdiv($this->balance, \App\Services\Signing\SigningService::PRICE_PER_SIGNATURE_ORE) }} signaturer (1,25 kr st).
                                @if ($this->balanceCheckedAt) Hämtat {{ $this->balanceCheckedAt->format('d/m Y H:i') }}. @endif
                            </p>
                        @endif
                        @if ($low)
                            <p class="mt-2 text-sm font-medium text-amber-800 dark:text-amber-300">
                                Under gränsen {{ format_ore($threshold) }}. Fyll på hos Inleed.
                            </p>
                        @endif
                        @if ($balanceError)
                            <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $balanceError }}</p>
                        @endif
                    </div>
                    <flux:button wire:click="refreshBalance" size="sm" variant="ghost">
                        <x-bootstrap-icon name="arrow-clockwise" class="size-4" /> Uppdatera
                    </flux:button>
                </div>
            @endif
        </div>
        @endcan

        <div class="flex flex-col justify-center gap-2 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            @if (SigningRequest::canBeCreatedBy(Auth::user()))
                <flux:button href="{{ route('admin.signing.create') }}" wire:navigate variant="primary" :disabled="! $this->configured">
                    <x-bootstrap-icon name="pen" class="size-4" /> Nytt signeringsärende
                </flux:button>
            @endif
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                @if (config('services.docsign.demo'))
                    <strong>Demoläge:</strong> dokument skickas som gratis testdokument utan riktig signering.
                @else
                    Dokument skickas på riktigt. En signatur kostar 1,25 kr.
                @endif
            </p>
        </div>
    </div>

    {{-- Anonymized signers still registered at DocSign --}}
    @if (Auth::user()->can('signing.view') && $this->pendingDocsignDeletion->isNotEmpty())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-5 dark:border-amber-500/40 dark:bg-amber-500/10">
            <p class="text-sm font-medium text-amber-900 dark:text-amber-200">Radera hos DocSign</p>
            <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">
                Följande externa undertecknare är anonymiserade i Membrum, men det gick inte att radera dem hos DocSign.
                Gallringen försöker igen varje månad, men du kan också försöka nu.
            </p>
            <ul class="mt-3 divide-y divide-amber-200 dark:divide-amber-500/30">
                @foreach ($this->pendingDocsignDeletion as $signer)
                    <li class="flex items-center justify-between py-2 text-sm text-amber-900 dark:text-amber-200">
                        <span>DocSign-id <code>{{ $signer->provider_party_id }}</code> · anonymiserad {{ $signer->anonymized_at->format('d/m Y') }}</span>
                        @can('signing.manage')
                            <flux:button wire:click="deleteAtDocsign({{ $signer->id }})" size="xs" variant="ghost">Radera hos DocSign</flux:button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-3">
        <div class="w-48">
            <flux:label>Status</flux:label>
            <flux:select wire:model.live="status" size="sm">
                <option value="">Alla</option>
                @foreach (SigningStatus::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-64">
            <flux:label>Sök</flux:label>
            <flux:input wire:model.live.debounce.300ms="search" size="sm" placeholder="Namn eller referens" />
        </div>
    </div>

    {{-- Requests --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        @if ($this->requests->isEmpty())
            <p class="p-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Inga signeringsärenden ännu.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3">Ärende</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Förlopp</th>
                            <th class="hidden px-4 py-3 md:table-cell">Skapad av</th>
                            <th class="hidden px-4 py-3 md:table-cell">Skickad</th>
                            <th class="hidden px-4 py-3 lg:table-cell">Senaste händelse</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($this->requests as $request)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.signing.show', $request) }}" wire:navigate class="font-medium text-zinc-900 hover:underline dark:text-white">{{ $request->name }}</a>
                                    @if ($request->reference)
                                        <span class="ml-1 font-mono text-xs text-zinc-400 dark:text-zinc-500">#{{ $request->reference }}</span>
                                    @endif
                                    @if ($request->is_demo)
                                        <span class="ml-1 rounded bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">demo</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $this->statusClasses($request->status) }}">{{ $request->status->label() }}</span>
                                </td>
                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $request->signed_count }} av {{ $request->signers_count }}</td>
                                <td class="hidden px-4 py-3 text-zinc-600 dark:text-zinc-300 md:table-cell">{{ $request->creator?->name ?? '–' }}</td>
                                <td class="hidden px-4 py-3 text-zinc-600 dark:text-zinc-300 md:table-cell">{{ $request->sent_at?->format('d/m Y H:i') ?? '–' }}</td>
                                <td class="hidden px-4 py-3 text-zinc-500 dark:text-zinc-400 lg:table-cell">
                                    @if ($event = $request->events->first())
                                        {{ $event->type->label() }} · {{ $event->created_at->format('d/m H:i') }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-zinc-100 px-4 py-3 dark:border-zinc-800">{{ $this->requests->links() }}</div>
        @endif
    </div>

    {{-- Settings --}}
    @can('signing.manage')
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Inställningar</h2>

            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                <div><dt class="text-zinc-500 dark:text-zinc-400">API-nyckel</dt><dd class="text-zinc-900 dark:text-white">{{ $this->configured ? 'Konfigurerad' : 'Saknas' }}</dd></div>
                <div><dt class="text-zinc-500 dark:text-zinc-400">Demoläge</dt><dd class="text-zinc-900 dark:text-white">{{ config('services.docsign.demo') ? 'På' : 'Av' }}</dd></div>
                <div><dt class="text-zinc-500 dark:text-zinc-400">Prefix för undertecknare</dt><dd class="font-mono text-zinc-900 dark:text-white">{{ config('services.docsign.external_id_prefix') }}</dd></div>
            </dl>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">API-nyckel och demoläge styrs av <code>.env</code> (<code>DOCSIGN_API_KEY</code>, <code>DOCSIGN_DEMO</code>).</p>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <flux:button wire:click="testConnection" size="sm" :disabled="! $this->configured">Testa kopplingen</flux:button>
                @if ($connectionResult)
                    <span @class(['text-sm', 'text-green-700 dark:text-green-400' => $connectionOk, 'text-red-600 dark:text-red-400' => ! $connectionOk])>{{ $connectionResult }}</span>
                @endif
            </div>

            <form wire:submit="saveSettings" class="mt-5 grid gap-4 sm:grid-cols-3">
                <div>
                    <flux:label>Varna vid saldo under (kr)</flux:label>
                    <flux:input wire:model="lowBalanceKr" type="number" step="1" min="0" />
                    @error('lowBalanceKr') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <flux:label>Information till undertecknare</flux:label>
                    <flux:textarea wire:model="signerNotice" rows="3" />
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Läggs till sist i meddelandet till alla undertecknare, t.ex. om hur föreningen hanterar personuppgifter.</p>
                    @error('signerNotice') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <flux:button type="submit" variant="primary" size="sm">Spara</flux:button>
                </div>
            </form>
        </div>
    @endcan
</div>
