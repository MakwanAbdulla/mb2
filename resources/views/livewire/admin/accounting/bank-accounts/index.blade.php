<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\CostCenter;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Bankkonton');

new class extends Component {

    public bool $showCreateModal = false;
    public bool $showEditModal   = false;
    public ?int $editingId       = null;

    // Form fields
    public string $formName          = '';
    public string $formAccountId     = '';
    public string $formAccountNumber = '';
    public string $formClearingNumber = '';
    public string $formBankName      = 'Swedbank';
    public string $formCurrency      = 'SEK';
    public string $formBankgiro      = '';
    public string $formPlusgiro      = '';
    public string $formSwishNumber   = '';
    public string $formSwishType     = '';
    public ?int   $formCostCenterId  = null;
    public string $formIban          = '';
    public string $formNotes         = '';
    public bool   $formIsActive      = true;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    #[Computed]
    public function bankAccounts()
    {
        return BankAccount::with('account')->orderBy('name')->get();
    }

    #[Computed]
    public function costCenters()
    {
        return CostCenter::active()->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function ledgerAccounts()
    {
        // Only show asset-class accounts (1xxx) that are active — these are the balance-sheet accounts
        // that a bank account would map to (e.g. 1931 Swish, 1932 Kiosk, 1933 Intern)
        return Account::where('is_active', true)
            ->whereBetween('code', ['1000', '1999'])
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showCreateModal = true;
    }

    public function openEdit(int $id): void
    {
        $ba = BankAccount::findOrFail($id);

        $this->editingId           = $id;
        $this->formName            = $ba->name;
        $this->formAccountId       = (string) $ba->account_id;
        $this->formAccountNumber   = $ba->account_number ?? '';
        $this->formClearingNumber  = $ba->clearing_number ?? '';
        $this->formBankName        = $ba->bank_name ?? 'Swedbank';
        $this->formCurrency        = $ba->currency ?? 'SEK';
        $this->formBankgiro        = $ba->bankgiro ?? '';
        $this->formPlusgiro        = $ba->plusgiro ?? '';
        $this->formSwishNumber     = $ba->swish_number ?? '';
        $this->formSwishType       = $ba->swish_type ?? '';
        $this->formCostCenterId    = $ba->cost_center_id;
        $this->formIban            = $ba->iban ?? '';
        $this->formNotes           = $ba->notes ?? '';
        $this->formIsActive        = $ba->is_active;

        $this->showEditModal = true;
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $ba = BankAccount::create([
            'name'             => $this->formName,
            'account_id'       => $this->formAccountId,
            'account_number'   => $this->formAccountNumber ?: null,
            'clearing_number'  => $this->formClearingNumber ?: null,
            'bank_name'        => $this->formBankName ?: null,
            'currency'         => $this->formCurrency,
            'bankgiro'         => $this->formBankgiro ?: null,
            'plusgiro'         => $this->formPlusgiro ?: null,
            'swish_number'     => $this->formSwishNumber ?: null,
            'swish_type'       => $this->formSwishType ?: null,
            'cost_center_id'   => $this->formCostCenterId ?: null,
            'iban'             => $this->formIban ?: null,
            'notes'            => $this->formNotes ?: null,
            'is_active'        => true,
        ]);

        AuditLog::record('bank_account_created', 'accounting', $ba->name, $ba);

        $this->showCreateModal = false;
        $this->resetForm();
        unset($this->bankAccounts);
    }

    public function update(): void
    {
        $ba = BankAccount::findOrFail($this->editingId);
        $this->validate($this->rules($ba->id));

        $ba->update([
            'name'             => $this->formName,
            'account_id'       => $this->formAccountId,
            'account_number'   => $this->formAccountNumber ?: null,
            'clearing_number'  => $this->formClearingNumber ?: null,
            'bank_name'        => $this->formBankName ?: null,
            'currency'         => $this->formCurrency,
            'bankgiro'         => $this->formBankgiro ?: null,
            'plusgiro'         => $this->formPlusgiro ?: null,
            'swish_number'     => $this->formSwishNumber ?: null,
            'swish_type'       => $this->formSwishType ?: null,
            'cost_center_id'   => $this->formCostCenterId ?: null,
            'iban'             => $this->formIban ?: null,
            'notes'            => $this->formNotes ?: null,
            'is_active'        => $this->formIsActive,
        ]);

        AuditLog::record('bank_account_updated', 'accounting', $ba->name, $ba);

        $this->showEditModal = false;
        $this->resetForm();
        unset($this->bankAccounts);
    }

    public function activate(int $id): void
    {
        $ba = BankAccount::findOrFail($id);
        $ba->update(['is_active' => true]);
        AuditLog::record('bank_account_activated', 'accounting', $ba->name, $ba);
        unset($this->bankAccounts);
    }

    public function deactivate(int $id): void
    {
        $ba = BankAccount::findOrFail($id);
        $ba->update(['is_active' => false]);
        AuditLog::record('bank_account_deactivated', 'accounting', $ba->name, $ba);
        unset($this->bankAccounts);
    }

    private function rules(?int $ignoreId = null): array
    {
        return [
            'formName'          => 'required|string|max:100',
            'formAccountId'     => 'required|exists:accounts,id',
            'formAccountNumber' => 'nullable|string|max:50',
            'formClearingNumber'=> 'nullable|string|max:10',
            'formBankName'      => 'nullable|string|max:100',
            'formCurrency'      => 'required|string|size:3',
            'formBankgiro'      => 'nullable|string|max:20',
            'formPlusgiro'      => 'nullable|string|max:20',
            'formSwishNumber'   => 'nullable|string|max:20',
            'formSwishType'     => 'nullable|in:private,handel,företag',
            'formIban'          => 'nullable|string|max:34',
        ];
    }

    private function resetForm(): void
    {
        $this->editingId          = null;
        $this->formName           = '';
        $this->formAccountId      = '';
        $this->formAccountNumber  = '';
        $this->formClearingNumber = '';
        $this->formBankName       = 'Swedbank';
        $this->formCurrency       = 'SEK';
        $this->formBankgiro       = '';
        $this->formPlusgiro       = '';
        $this->formSwishNumber    = '';
        $this->formSwishType      = '';
        $this->formCostCenterId   = null;
        $this->formIban           = '';
        $this->formNotes          = '';
        $this->formIsActive       = true;
        $this->resetValidation();
    }
};
?>

<div>

    <div class="mb-4 flex items-center justify-end gap-2">
        <a href="{{ route('admin.accounting.bank-accounts.lb-return') }}" wire:navigate class="btn btn-sm">
            <x-bootstrap-icon name="file-earmark-check" class="size-3.5" />
            LB återredovisning
        </a>
        <a href="{{ route('admin.accounting.bank-accounts.swish-import') }}" wire:navigate class="btn btn-sm">
            <x-bootstrap-icon name="phone" class="size-3.5" />
            Importera Swish-rapport
        </a>
        <a href="{{ route('admin.accounting.bank-accounts.bank-fee-import') }}" wire:navigate class="btn btn-sm">
            <x-bootstrap-icon name="receipt" class="size-3.5" />
            Bankavgifter
        </a>
        <a href="{{ route('admin.accounting.bank-accounts.import') }}" wire:navigate class="btn btn-sm">
            <x-bootstrap-icon name="upload" class="size-3.5" />
            Importera kontoutdrag
        </a>
        <flux:button wire:click="openCreate" variant="primary" size="sm">
            Nytt bankkonto
        </flux:button>
    </div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3">Namn</th>
                    <th class="px-4 py-3 w-32">Konto</th>
                    <th class="px-4 py-3 w-36">Kontonummer</th>
                    <th class="px-4 py-3 w-32">Bankgiro</th>
                    <th class="px-4 py-3 w-32">Swish</th>
                    <th class="px-4 py-3 w-24 text-center">Status</th>
                    <th class="px-4 py-3 w-32"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->bankAccounts as $ba)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ $ba->is_active ? '' : 'opacity-60' }}">
                        <td class="px-4 py-3">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ $ba->name }}</div>
                            @if ($ba->bank_name)
                                <div class="text-xs text-zinc-400">{{ $ba->bank_name }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-sm">
                            @if ($ba->account)
                                <span class="font-semibold">{{ $ba->account->code }}</span>
                                <span class="text-xs text-zinc-400 block truncate max-w-[8rem]">{{ $ba->account->name }}</span>
                            @else
                                <span class="text-zinc-400">–</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-300">
                            @if ($ba->clearing_number && $ba->account_number)
                                {{ $ba->clearing_number }}-{{ $ba->account_number }}
                            @elseif ($ba->account_number)
                                {{ $ba->account_number }}
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600">–</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-300">
                            {{ $ba->bankgiro ?: '–' }}
                        </td>
                        <td class="px-4 py-3">
                            @if ($ba->swish_number)
                                <div class="font-mono text-xs">{{ $ba->swish_number }}</div>
                                @if ($ba->swish_type)
                                    <span class="text-xs text-zinc-400">
                                        {{ $ba->swish_type === 'handel' ? 'Handel' : 'Privat' }}
                                    </span>
                                @endif
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600 text-xs">–</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($ba->is_active)
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
                                @if ($ba->is_active)
                                    <button wire:click="deactivate({{ $ba->id }})"
                                        class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                        Inaktivera
                                    </button>
                                @else
                                    <button wire:click="activate({{ $ba->id }})"
                                        class="text-xs text-green-600 hover:text-green-700 dark:text-green-400">
                                        Aktivera
                                    </button>
                                @endif
                                <button wire:click="openEdit({{ $ba->id }})"
                                    class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                    Redigera
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-sm text-zinc-400">
                            Inga bankkonton konfigurerade ännu.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Create modal --}}
    <flux:modal wire:model="showCreateModal" class="max-w-2xl">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-5">Nytt bankkonto</h2>
            @include('livewire.admin.accounting.bank-accounts._form')
            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showCreateModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="save" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit modal --}}
    <flux:modal wire:model="showEditModal" class="max-w-2xl">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-5">Redigera bankkonto</h2>
            @include('livewire.admin.accounting.bank-accounts._form')
            <div class="mt-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" wire:model="formIsActive"
                           class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Aktivt</span>
                </label>
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showEditModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="update" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
