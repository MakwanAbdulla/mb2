<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\PaymentProvider;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Betaltjänstleverantörer');

new class extends Component {

    public bool $showCreateModal = false;
    public bool $showEditModal   = false;
    public ?int $editingId       = null;

    // Form fields
    public string $formName                   = '';
    public string $formType                   = 'online';
    public string $formAccountId              = '';
    public string $formSettlementBankAccountId = '';
    public string $formNotes                  = '';
    public bool   $formIsActive               = true;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    #[Computed]
    public function providers()
    {
        return PaymentProvider::with(['account', 'settlementBankAccount'])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function ledgerAccounts()
    {
        // Clearing accounts for PSPs typically live in 19xx range
        return Account::where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function bankAccounts()
    {
        return BankAccount::active()->orderBy('name')->get(['id', 'name']);
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showCreateModal = true;
    }

    public function openEdit(int $id): void
    {
        $provider = PaymentProvider::findOrFail($id);

        $this->editingId                    = $id;
        $this->formName                     = $provider->name;
        $this->formType                     = $provider->type;
        $this->formAccountId                = (string) $provider->account_id;
        $this->formSettlementBankAccountId  = (string) ($provider->settlement_bank_account_id ?? '');
        $this->formNotes                    = $provider->notes ?? '';
        $this->formIsActive                 = $provider->is_active;

        $this->showEditModal = true;
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $provider = PaymentProvider::create([
            'name'                       => $this->formName,
            'type'                       => $this->formType,
            'account_id'                 => $this->formAccountId,
            'settlement_bank_account_id' => $this->formSettlementBankAccountId ?: null,
            'notes'                      => $this->formNotes ?: null,
            'is_active'                  => true,
        ]);
        AuditLog::record('payment_provider_created', 'accounting', $provider->name, $provider);

        $this->showCreateModal = false;
        $this->resetForm();
        unset($this->providers);
    }

    public function update(): void
    {
        $provider = PaymentProvider::findOrFail($this->editingId);
        $this->validate($this->rules());

        $provider->update([
            'name'                       => $this->formName,
            'type'                       => $this->formType,
            'account_id'                 => $this->formAccountId,
            'settlement_bank_account_id' => $this->formSettlementBankAccountId ?: null,
            'notes'                      => $this->formNotes ?: null,
            'is_active'                  => $this->formIsActive,
        ]);
        AuditLog::record('payment_provider_updated', 'accounting', $provider->name, $provider);

        $this->showEditModal = false;
        $this->resetForm();
        unset($this->providers);
    }

    public function activate(int $id): void
    {
        $provider = PaymentProvider::findOrFail($id);
        $provider->update(['is_active' => true]);
        AuditLog::record('payment_provider_activated', 'accounting', $provider->name, $provider);
        unset($this->providers);
    }

    public function deactivate(int $id): void
    {
        $provider = PaymentProvider::findOrFail($id);
        $provider->update(['is_active' => false]);
        AuditLog::record('payment_provider_deactivated', 'accounting', $provider->name, $provider);
        unset($this->providers);
    }

    private function rules(): array
    {
        return [
            'formName'    => 'required|string|max:100',
            'formType'    => 'required|in:online,card_terminal',
            'formAccountId' => 'required|exists:accounts,id',
            'formSettlementBankAccountId' => 'nullable|exists:bank_accounts,id',
        ];
    }

    private function resetForm(): void
    {
        $this->editingId                   = null;
        $this->formName                    = '';
        $this->formType                    = 'online';
        $this->formAccountId               = '';
        $this->formSettlementBankAccountId = '';
        $this->formNotes                   = '';
        $this->formIsActive                = true;
        $this->resetValidation();
    }
};
?>

<div>

    <div class="mb-4 flex justify-end">
        <flux:button wire:click="openCreate" variant="primary" size="sm">
                Ny betaltjänst
            </flux:button>
    </div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3">Namn</th>
                    <th class="px-4 py-3 w-36">Typ</th>
                    <th class="px-4 py-3 w-40">Clearingkonto</th>
                    <th class="px-4 py-3 w-44">Utbetalningskonto</th>
                    <th class="px-4 py-3 w-24 text-center">Status</th>
                    <th class="px-4 py-3 w-32"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->providers as $provider)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ $provider->is_active ? '' : 'opacity-60' }}">
                        <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">
                            {{ $provider->name }}
                        </td>
                        <td class="px-4 py-3">
                            @if ($provider->type === 'card_terminal')
                                <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">
                                    <x-bootstrap-icon name="credit-card" class="size-3" /> Kortterminal
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                                    <x-bootstrap-icon name="globe" class="size-3" /> Online
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            @if ($provider->account)
                                <span class="font-semibold">{{ $provider->account->code }}</span>
                                <span class="text-zinc-400 block truncate max-w-[9rem]">{{ $provider->account->name }}</span>
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600">–</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300">
                            {{ $provider->settlementBankAccount?->name ?? '–' }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($provider->is_active)
                                <span class="inline-flex items-center gap-1 text-xs text-green-700 dark:text-green-400">
                                    <x-bootstrap-icon name="check-circle-fill" class="size-3.5" /> Aktiv
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs text-zinc-400">
                                    <x-bootstrap-icon name="dash-circle" class="size-3.5" /> Inaktiv
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if ($provider->is_active)
                                    <button wire:click="deactivate({{ $provider->id }})"
                                        class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                        Inaktivera
                                    </button>
                                @else
                                    <button wire:click="activate({{ $provider->id }})"
                                        class="text-xs text-green-600 hover:text-green-700 dark:text-green-400">
                                        Aktivera
                                    </button>
                                @endif
                                <button wire:click="openEdit({{ $provider->id }})"
                                    class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                    Redigera
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-zinc-400">
                            Inga betaltjänster konfigurerade ännu.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Create modal --}}
    <flux:modal wire:model="showCreateModal" class="max-w-lg">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-5">Ny betaltjänst</h2>
            @include('livewire.admin.accounting.payment-providers._form')
            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showCreateModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="save" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit modal --}}
    <flux:modal wire:model="showEditModal" class="max-w-lg">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-5">Redigera betaltjänst</h2>
            @include('livewire.admin.accounting.payment-providers._form')
            <div class="mt-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" wire:model="formIsActive"
                           class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Aktiv</span>
                </label>
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showEditModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="update" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
