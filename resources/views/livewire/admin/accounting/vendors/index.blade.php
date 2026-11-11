<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\PostingRule;
use App\Models\Accounting\Vendor;
use App\Models\AuditLog;
use App\Models\Membership\Member;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Leverantörer');

new class extends Component {

    public string $search       = '';
    public string $filterActive = 'active';

    public bool  $showCreateModal = false;
    public bool  $showEditModal   = false;
    public ?int  $editingId       = null;

    // Form fields
    public string $formCode              = '';
    public string $formName              = '';
    public string $formType              = 'external';
    public string $formMemberId          = '';
    public string $formOrgNumber         = '';
    public string $formBankgiro          = '';
    public string $formPlusgiro          = '';
    public string $formIban              = '';
    public string $formBankClearingNumber = '';
    public string $formEmail             = '';
    public string $formPhone             = '';
    public string $formDefaultAccountId  = '';
    public string $formDefaultVatRate    = '0';
    public string $formNotes             = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    #[Computed]
    public function vendors()
    {
        return Vendor::query()
            ->when($this->search, fn ($q) => $q->where(fn ($q) =>
                $q->where('name', 'like', "%{$this->search}%")
                  ->orWhere('code', 'like', "%{$this->search}%")
                  ->orWhere('org_number', 'like', "%{$this->search}%")
            ))
            ->when($this->filterActive === 'active',   fn ($q) => $q->where('is_active', true))
            ->when($this->filterActive === 'inactive', fn ($q) => $q->where('is_active', false))
            ->with('defaultAccount')
            ->withCount('postingRules')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function expenseAccounts()
    {
        return Account::where('is_active', true)
            ->whereIn('type', ['expense', 'liability', 'asset'])
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

        $max = Vendor::pluck('code')
            ->filter(fn($c) => ctype_digit((string) $c))
            ->map(fn($c) => (int) $c)
            ->max() ?? 0;
        $this->formCode = (string) ($max + 1);

        $this->showCreateModal = true;
    }

    public function openEdit(int $id): void
    {
        $v = Vendor::findOrFail($id);

        $this->editingId              = $id;
        $this->formCode               = $v->code;
        $this->formName               = $v->name;
        $this->formType               = $v->type;
        $this->formMemberId           = (string) ($v->member_id ?? '');
        $this->formOrgNumber          = $v->org_number ?? '';
        $this->formBankgiro           = $v->bankgiro ?? '';
        $this->formPlusgiro           = $v->plusgiro ?? '';
        $this->formIban               = $v->iban ?? '';
        $this->formBankClearingNumber = $v->bank_clearing_number ?? '';
        $this->formEmail              = $v->email ?? '';
        $this->formPhone              = $v->phone ?? '';
        $this->formDefaultAccountId   = (string) ($v->default_account_id ?? '');
        $this->formDefaultVatRate     = (string) $v->default_vat_rate;
        $this->formNotes              = $v->notes ?? '';

        $this->showEditModal = true;
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $v = Vendor::create($this->formData() + ['is_active' => true]);

        AuditLog::record('vendor_created', 'accounting', $v->name, $v);

        $this->showCreateModal = false;
        $this->resetForm();
        unset($this->vendors);
    }

    public function update(): void
    {
        $v = Vendor::findOrFail($this->editingId);
        $this->validate($this->rules($v->id));

        $v->update($this->formData());

        AuditLog::record('vendor_updated', 'accounting', $v->name, $v);

        $this->showEditModal = false;
        $this->resetForm();
        unset($this->vendors);
    }

    public function activate(int $id): void
    {
        $v = Vendor::findOrFail($id);
        $v->update(['is_active' => true]);
        AuditLog::record('vendor_activated', 'accounting', $v->name, $v);
        unset($this->vendors);
    }

    public function deactivate(int $id): void
    {
        $v = Vendor::findOrFail($id);
        $v->update(['is_active' => false]);
        AuditLog::record('vendor_deactivated', 'accounting', $v->name, $v);
        unset($this->vendors);
    }

    private function rules(?int $ignoreId = null): array
    {
        return [
            'formCode'    => 'required|string|max:20|unique:vendors,code' . ($ignoreId ? ",{$ignoreId}" : ''),
            'formName'    => 'required|string|max:255',
            'formType'    => 'required|in:external,member',
            'formMemberId'=> 'nullable|exists:members,id',
            'formEmail'   => 'nullable|email|max:255',
            'formDefaultVatRate' => 'required|integer|in:0,6,12,25',
            'formDefaultAccountId' => 'nullable|exists:accounts,id',
        ];
    }

    private function formData(): array
    {
        return [
            'code'                 => strtoupper($this->formCode),
            'name'                 => $this->formName,
            'type'                 => $this->formType,
            'member_id'            => $this->formMemberId ?: null,
            'org_number'           => $this->formOrgNumber ?: null,
            'bankgiro'             => $this->formBankgiro ?: null,
            'plusgiro'             => $this->formPlusgiro ?: null,
            'iban'                 => $this->formIban ?: null,
            'bank_clearing_number' => $this->formBankClearingNumber ?: null,
            'email'                => $this->formEmail ?: null,
            'phone'                => $this->formPhone ?: null,
            'default_account_id'   => $this->formDefaultAccountId ?: null,
            'default_vat_rate'     => (int) $this->formDefaultVatRate,
            'notes'                => $this->formNotes ?: null,
        ];
    }

    private function resetForm(): void
    {
        $this->editingId              = null;
        $this->formCode               = '';
        $this->formName               = '';
        $this->formType               = 'external';
        $this->formMemberId           = '';
        $this->formOrgNumber          = '';
        $this->formBankgiro           = '';
        $this->formPlusgiro           = '';
        $this->formIban               = '';
        $this->formBankClearingNumber = '';
        $this->formEmail              = '';
        $this->formPhone              = '';
        $this->formDefaultAccountId   = '';
        $this->formDefaultVatRate     = '0';
        $this->formNotes              = '';
        $this->resetValidation();
    }
};
?>

<div>

    <div class="mb-4 flex justify-end">
        <flux:button wire:click="openCreate" variant="primary" size="sm">
                Ny leverantör
            </flux:button>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex gap-3">
        <flux:input wire:model.live="search" placeholder="Sök namn, kod, org.nr…" class="w-64" />
        <flux:select wire:model.live="filterActive" class="w-36">
            <option value="active">Aktiva</option>
            <option value="inactive">Inaktiva</option>
            <option value="all">Alla</option>
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 w-24">Kod</th>
                    <th class="px-4 py-3">Namn</th>
                    <th class="px-4 py-3 w-24">Org.nr</th>
                    <th class="px-4 py-3 w-32">Bankgiro</th>
                    <th class="px-4 py-3 w-36">Std.konto</th>
                    <th class="px-4 py-3 w-16 text-center">Moms</th>
                    <th class="px-4 py-3 w-24 text-center">Status</th>
                    <th class="px-4 py-3 w-20 text-center">Regler</th>
                    <th class="px-4 py-3 w-24"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->vendors as $v)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ $v->is_active ? '' : 'opacity-60' }}">
                        <td class="px-4 py-3 font-mono font-semibold">{{ $v->code }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.accounting.vendors.show', $v) }}" wire:navigate
                               class="font-medium text-zinc-900 dark:text-white hover:text-brand-dark dark:hover:text-brand">
                                {{ $v->name }}
                            </a>
                            @if ($v->email)
                                <div class="text-xs text-zinc-400">{{ $v->email }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-500">{{ $v->org_number ?? '–' }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-500">{{ $v->bankgiro ?? '–' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">
                            @if ($v->defaultAccount)
                                <span class="font-semibold">{{ $v->defaultAccount->code }}</span>
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600">–</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-xs text-zinc-500">
                            {{ $v->default_vat_rate > 0 ? $v->default_vat_rate . '%' : '–' }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($v->is_active)
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
                            @if ($v->posting_rules_count > 0)
                                <a href="{{ route('admin.accounting.posting-rules.index') }}?from_vendor={{ $v->id }}"
                                   wire:navigate
                                   class="inline-flex items-center gap-1 text-xs text-brand-dark dark:text-brand bg-brand/20 hover:bg-brand/30 rounded px-1.5 py-0.5 font-medium tabular-nums">
                                    {{ $v->posting_rules_count }}
                                </a>
                            @else
                                <a href="{{ route('admin.accounting.posting-rules.index') }}?from_vendor={{ $v->id }}"
                                   wire:navigate
                                   class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                    + Ny
                                </a>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if ($v->is_active)
                                    <button wire:click="deactivate({{ $v->id }})"
                                        class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                        Inaktivera
                                    </button>
                                @else
                                    <button wire:click="activate({{ $v->id }})"
                                        class="text-xs text-green-600 hover:text-green-700 dark:text-green-400">
                                        Aktivera
                                    </button>
                                @endif
                                <button wire:click="openEdit({{ $v->id }})"
                                    class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                    Redigera
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-10 text-center text-sm text-zinc-400">
                            Inga leverantörer hittades.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Create modal --}}
    <flux:modal wire:model="showCreateModal" class="max-w-2xl">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-5">Ny leverantör</h2>
            @include('livewire.admin.accounting.vendors._form')
            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showCreateModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="save" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit modal --}}
    <flux:modal wire:model="showEditModal" class="max-w-2xl">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-5">Redigera leverantör</h2>
            @include('livewire.admin.accounting.vendors._form')
            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showEditModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="update" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
