<?php

use App\Models\Accounting\Account;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Kontoplan');

new class extends Component {

    public string $search      = '';
    public string $filterType  = '';
    public string $filterActive = 'active'; // active | inactive | all

    public bool $showCreateModal = false;
    public bool $showEditModal   = false;

    // Create / edit form fields
    public string $formCode      = '';
    public string $formName      = '';
    public string $formType      = 'expense';
    public string $formVatCode   = '';
    public bool   $formIsGroup   = false;
    public ?int   $editingId     = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    #[Computed]
    public function accounts()
    {
        return Account::query()
            ->when($this->search, fn ($q) =>
                $q->where('code', 'like', "%{$this->search}%")
                  ->orWhere('name', 'like', "%{$this->search}%")
            )
            ->when($this->filterType, fn ($q) => $q->where('type', $this->filterType))
            ->when($this->filterActive === 'active',   fn ($q) => $q->where('is_active', true))
            ->when($this->filterActive === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('code')
            ->get();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showCreateModal = true;
    }

    public function openEdit(int $id): void
    {
        $account = Account::findOrFail($id);
        $this->editingId   = $id;
        $this->formCode    = $account->code;
        $this->formName    = $account->name;
        $this->formType    = $account->type;
        $this->formVatCode = $account->vat_code ?? '';
        $this->formIsGroup = $account->is_group;
        $this->showEditModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'formCode' => 'required|string|max:10|unique:accounts,code',
            'formName' => 'required|string|max:255',
            'formType' => 'required|in:asset,liability,equity,income,expense',
            'formVatCode' => 'nullable|string|max:10',
        ]);

        $account = Account::create([
            'code'     => $this->formCode,
            'name'     => $this->formName,
            'type'     => $this->formType,
            'vat_code' => $this->formVatCode ?: null,
            'is_group' => $this->formIsGroup,
            'is_active' => true,
        ]);

        AuditLog::record('account_created', 'accounting', $account->code . ' ' . $account->name, $account);
        $this->showCreateModal = false;
        $this->resetForm();
        unset($this->accounts);
    }

    public function update(): void
    {
        $account = Account::findOrFail($this->editingId);

        $this->validate([
            'formCode' => 'required|string|max:10|unique:accounts,code,' . $account->id,
            'formName' => 'required|string|max:255',
            'formType' => 'required|in:asset,liability,equity,income,expense',
            'formVatCode' => 'nullable|string|max:10',
        ]);

        $account->update([
            'code'     => $this->formCode,
            'name'     => $this->formName,
            'type'     => $this->formType,
            'vat_code' => $this->formVatCode ?: null,
            'is_group' => $this->formIsGroup,
        ]);

        AuditLog::record('account_updated', 'accounting', $account->code . ' ' . $account->name, $account);
        $this->showEditModal = false;
        $this->resetForm();
        unset($this->accounts);
    }

    public function activate(int $id): void
    {
        $account = Account::findOrFail($id);
        $account->update(['is_active' => true]);
        AuditLog::record('account_activated', 'accounting', $account->code . ' ' . $account->name, $account);
        unset($this->accounts);
    }

    public function deactivate(int $id): void
    {
        $account = Account::findOrFail($id);
        $account->update(['is_active' => false]);
        AuditLog::record('account_deactivated', 'accounting', $account->code . ' ' . $account->name, $account);
        unset($this->accounts);
    }

    private function resetForm(): void
    {
        $this->formCode    = '';
        $this->formName    = '';
        $this->formType    = 'expense';
        $this->formVatCode = '';
        $this->formIsGroup = false;
        $this->editingId   = null;
        $this->resetValidation();
    }
};
?>

<div>

    <div class="mb-4 flex justify-end">
        <flux:button wire:click="openCreate" variant="primary" size="sm">
                Nytt konto
            </flux:button>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3 mb-6">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="Sök konto eller benämning…"
            class="w-64"
        />

        <flux:select wire:model.live="filterType" class="w-44">
            <option value="">Alla typer</option>
            <option value="asset">Tillgång</option>
            <option value="liability">Skuld</option>
            <option value="equity">Eget kapital</option>
            <option value="income">Intäkt</option>
            <option value="expense">Kostnad</option>
        </flux:select>

        <flux:select wire:model.live="filterActive" class="w-40">
            <option value="active">Aktiva</option>
            <option value="inactive">Inaktiva</option>
            <option value="all">Alla</option>
        </flux:select>
    </div>

    {{-- Account table --}}
    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 w-24">Konto</th>
                    <th class="px-4 py-3">Benämning</th>
                    <th class="px-4 py-3 w-36">Typ</th>
                    <th class="px-4 py-3 w-24">Momskod</th>
                    <th class="px-4 py-3 w-20 text-center">Grupp</th>
                    <th class="px-4 py-3 w-20 text-center">Status</th>
                    <th class="px-4 py-3 w-28"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->accounts as $account)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ $account->is_active ? '' : 'opacity-60' }}">
                        <td class="px-4 py-3 font-mono font-medium">{{ $account->code }}</td>
                        <td class="px-4 py-3">{{ $account->name }}</td>
                        <td class="px-4 py-3">
                            @php
                                $typeLabels = [
                                    'asset'     => ['text' => 'Tillgång',      'class' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300'],
                                    'liability' => ['text' => 'Skuld',         'class' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'],
                                    'equity'    => ['text' => 'Eget kapital',  'class' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300'],
                                    'income'    => ['text' => 'Intäkt',        'class' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300'],
                                    'expense'   => ['text' => 'Kostnad',       'class' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'],
                                ];
                                $t = $typeLabels[$account->type] ?? ['text' => $account->type, 'class' => 'bg-zinc-100 text-zinc-700'];
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $t['class'] }}">
                                {{ $t['text'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $account->vat_code ?? '–' }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($account->is_group)
                                <span class="text-xs text-zinc-400">ja</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($account->is_active)
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
                                @if ($account->is_active)
                                    <button wire:click="deactivate({{ $account->id }})"
                                        class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300"
                                        title="Inaktivera">
                                        Inaktivera
                                    </button>
                                @else
                                    <button wire:click="activate({{ $account->id }})"
                                        class="text-xs text-green-600 hover:text-green-700 dark:text-green-400"
                                        title="Aktivera">
                                        Aktivera
                                    </button>
                                @endif
                                <button wire:click="openEdit({{ $account->id }})"
                                    class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                    Redigera
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-400">
                            Inga konton matchar sökningen.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Create modal --}}
    <flux:modal wire:model="showCreateModal" class="max-w-lg">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-4">Nytt konto</h2>

            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:label for="formCode">Kontonummer</flux:label>
                        <flux:input id="formCode" wire:model="formCode" placeholder="t.ex. 6990" class="font-mono" />
                        @error('formCode') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:label for="formType">Typ</flux:label>
                        <flux:select id="formType" wire:model="formType">
                            <option value="asset">Tillgång</option>
                            <option value="liability">Skuld</option>
                            <option value="equity">Eget kapital</option>
                            <option value="income">Intäkt</option>
                            <option value="expense">Kostnad</option>
                        </flux:select>
                    </div>
                </div>

                <div>
                    <flux:label for="formName">Benämning</flux:label>
                    <flux:input id="formName" wire:model="formName" placeholder="t.ex. Övriga externa tjänster" />
                    @error('formName') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:label for="formVatCode">Momskod (valfri)</flux:label>
                        <flux:input id="formVatCode" wire:model="formVatCode" placeholder="t.ex. MP1" class="font-mono" />
                    </div>
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <flux:checkbox wire:model="formIsGroup" id="formIsGroup" />
                            Gruppkonto
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showCreateModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="save" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit modal --}}
    <flux:modal wire:model="showEditModal" class="max-w-lg">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-4">Redigera konto</h2>

            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:label for="editCode">Kontonummer</flux:label>
                        <flux:input id="editCode" wire:model="formCode" placeholder="t.ex. 6990" class="font-mono" />
                        @error('formCode') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:label for="editType">Typ</flux:label>
                        <flux:select id="editType" wire:model="formType">
                            <option value="asset">Tillgång</option>
                            <option value="liability">Skuld</option>
                            <option value="equity">Eget kapital</option>
                            <option value="income">Intäkt</option>
                            <option value="expense">Kostnad</option>
                        </flux:select>
                    </div>
                </div>

                <div>
                    <flux:label for="editName">Benämning</flux:label>
                    <flux:input id="editName" wire:model="formName" />
                    @error('formName') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:label for="editVatCode">Momskod (valfri)</flux:label>
                        <flux:input id="editVatCode" wire:model="formVatCode" class="font-mono" />
                    </div>
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <flux:checkbox wire:model="formIsGroup" id="editIsGroup" />
                            Gruppkonto
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showEditModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="update" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
