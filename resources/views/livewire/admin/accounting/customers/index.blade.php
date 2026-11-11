<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\Customer;
use App\Models\AuditLog;
use App\Models\Membership\Member;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Kunder');

new class extends Component {

    public string $search       = '';
    public string $filterActive = 'active';

    public bool $showCreateModal = false;
    public bool $showEditModal   = false;
    public ?int $editingId       = null;

    // Form fields
    public string $formName             = '';
    public string $formOrgNumber        = '';
    public string $formEmail            = '';
    public string $formMemberId         = '';
    public string $formDefaultAccountId = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    #[Computed]
    public function customers()
    {
        return Customer::query()
            ->when($this->search, fn ($q) => $q->where(fn ($q) =>
                $q->where('name', 'like', "%{$this->search}%")
                  ->orWhere('org_number', 'like', "%{$this->search}%")
                  ->orWhere('email', 'like', "%{$this->search}%")
            ))
            ->when($this->filterActive === 'active',   fn ($q) => $q->where('is_active', true))
            ->when($this->filterActive === 'inactive', fn ($q) => $q->where('is_active', false))
            ->with(['defaultAccount', 'member'])
            ->withCount('invoices')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function incomeAccounts()
    {
        return Account::where('is_active', true)
            ->whereIn('type', ['income', 'asset'])
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function members()
    {
        return Member::orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showCreateModal = true;
    }

    public function openEdit(int $id): void
    {
        $c = Customer::findOrFail($id);

        $this->editingId            = $id;
        $this->formName             = $c->name;
        $this->formOrgNumber        = $c->org_number ?? '';
        $this->formEmail            = $c->email ?? '';
        $this->formMemberId         = (string) ($c->member_id ?? '');
        $this->formDefaultAccountId = (string) ($c->default_account_id ?? '');

        $this->showEditModal = true;
    }

    public function save(): void
    {
        $this->validate($this->rules());
        $c = Customer::create($this->formData() + ['is_active' => true]);
        AuditLog::record('customer_created', 'accounting', $c->name, $c);
        $this->showCreateModal = false;
        $this->resetForm();
        unset($this->customers);
    }

    public function update(): void
    {
        $this->validate($this->rules());
        $c = Customer::findOrFail($this->editingId);
        $c->update($this->formData());
        AuditLog::record('customer_updated', 'accounting', $c->name, $c);
        $this->showEditModal = false;
        $this->resetForm();
        unset($this->customers);
    }

    public function activate(int $id): void
    {
        $c = Customer::findOrFail($id);
        $c->update(['is_active' => true]);
        AuditLog::record('customer_activated', 'accounting', $c->name, $c);
        unset($this->customers);
    }

    public function deactivate(int $id): void
    {
        $c = Customer::findOrFail($id);
        $c->update(['is_active' => false]);
        AuditLog::record('customer_deactivated', 'accounting', $c->name, $c);
        unset($this->customers);
    }

    private function rules(): array
    {
        return [
            'formName'             => 'required|string|max:255',
            'formOrgNumber'        => 'nullable|string|max:20',
            'formEmail'            => 'nullable|email|max:255',
            'formMemberId'         => 'nullable|exists:members,id',
            'formDefaultAccountId' => 'nullable|exists:accounts,id',
        ];
    }

    private function formData(): array
    {
        return [
            'name'               => $this->formName,
            'org_number'         => $this->formOrgNumber ?: null,
            'email'              => $this->formEmail ?: null,
            'member_id'          => $this->formMemberId ?: null,
            'default_account_id' => $this->formDefaultAccountId ?: null,
        ];
    }

    private function resetForm(): void
    {
        $this->editingId            = null;
        $this->formName             = '';
        $this->formOrgNumber        = '';
        $this->formEmail            = '';
        $this->formMemberId         = '';
        $this->formDefaultAccountId = '';
        $this->resetValidation();
    }
};
?>

<div>

    {{-- Filters + action --}}
    <div class="flex items-center gap-3 mb-6">
        <div class="flex-1 min-w-0">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Sök namn, org.nr, e-post…" />
        </div>
        <flux:select wire:model.live="filterActive" class="w-36 shrink-0">
            <option value="active">Aktiva</option>
            <option value="inactive">Inaktiva</option>
            <option value="all">Alla</option>
        </flux:select>
        <flux:button wire:click="openCreate" variant="primary" size="sm" class="shrink-0">
            <x-bootstrap-icon name="plus-lg" class="size-3.5" />
            Ny kund
        </flux:button>
    </div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3">Namn</th>
                    <th class="px-4 py-3 w-32">Org.nr</th>
                    <th class="px-4 py-3 w-48">E-post</th>
                    <th class="px-4 py-3 w-36">Std.konto</th>
                    <th class="px-4 py-3 w-24 text-center">Status</th>
                    <th class="px-4 py-3 w-20 text-center">Fakturor</th>
                    <th class="px-4 py-3 w-24"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->customers as $c)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ $c->is_active ? '' : 'opacity-60' }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.accounting.customers.show', $c) }}" wire:navigate
                               class="font-medium text-zinc-900 dark:text-white hover:text-brand-dark dark:hover:text-brand">
                                {{ $c->name }}
                            </a>
                            @if ($c->member)
                                <div class="text-xs text-zinc-400">{{ $c->member->first_name }} {{ $c->member->last_name }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-500">{{ $c->org_number ?? '–' }}</td>
                        <td class="px-4 py-3 text-xs text-zinc-500">{{ $c->email ?? '–' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">
                            @if ($c->defaultAccount)
                                <span class="font-semibold">{{ $c->defaultAccount->code }}</span>
                                <span class="text-zinc-400 ml-1">{{ $c->defaultAccount->name }}</span>
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600">–</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($c->is_active)
                                <span class="inline-flex items-center gap-1 text-xs text-green-700 dark:text-green-400">
                                    <x-bootstrap-icon name="check-circle-fill" class="size-3.5" /> Aktiv
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs text-zinc-400">
                                    <x-bootstrap-icon name="dash-circle" class="size-3.5" /> Inaktiv
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($c->invoices_count > 0)
                                <a href="{{ route('admin.accounting.invoices.receivable.index') }}?customer_id={{ $c->id }}"
                                   wire:navigate
                                   class="inline-flex items-center gap-1 text-xs text-brand-dark dark:text-brand bg-brand/20 hover:bg-brand/30 rounded px-1.5 py-0.5 font-medium tabular-nums">
                                    {{ $c->invoices_count }}
                                </a>
                            @else
                                <span class="text-xs text-zinc-300 dark:text-zinc-600">–</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if ($c->is_active)
                                    <button wire:click="deactivate({{ $c->id }})"
                                        class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                        Inaktivera
                                    </button>
                                @else
                                    <button wire:click="activate({{ $c->id }})"
                                        class="text-xs text-green-600 hover:text-green-700 dark:text-green-400">
                                        Aktivera
                                    </button>
                                @endif
                                <button wire:click="openEdit({{ $c->id }})"
                                    class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                    Redigera
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-400">
                            Inga kunder hittades.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Create modal --}}
    <flux:modal wire:model="showCreateModal" class="max-w-lg">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-5">Ny kund</h2>
            @include('livewire.admin.accounting.customers._form')
            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showCreateModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="save" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit modal --}}
    <flux:modal wire:model="showEditModal" class="max-w-lg">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-5">Redigera kund</h2>
            @include('livewire.admin.accounting.customers._form')
            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showEditModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="update" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
