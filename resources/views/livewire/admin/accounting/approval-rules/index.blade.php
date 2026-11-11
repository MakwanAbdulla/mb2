<?php

use App\Models\Accounting\ApprovalRule;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Attestregler');

new class extends Component {

    public bool $showForm   = false;
    public ?int $editingId  = null;

    public string $formName               = '';
    public string $formInvoiceType        = 'any';
    public string $formAmountMin          = '';
    public string $formAmountMax          = '';
    public int    $formRequiredApprovals  = 1;
    public string $formApproverPermission = 'accounting.approve';
    public bool   $formBlockSubmitter     = true;
    public bool   $formBlockBeneficiary   = true;
    public bool   $formIsActive           = true;
    public int    $formSortOrder          = 0;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.admin'), 403);
    }

    #[Computed]
    public function rules()
    {
        return ApprovalRule::orderBy('sort_order')->orderBy('name')->get();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->editingId = null;
        $this->showForm  = true;
    }

    public function openEdit(int $id): void
    {
        $rule = ApprovalRule::findOrFail($id);

        $this->editingId              = $id;
        $this->formName               = $rule->name;
        $this->formInvoiceType        = $rule->invoice_type ?? 'any';
        $this->formAmountMin          = $rule->amount_min !== null ? number_format($rule->amount_min / 100, 2, ',', '') : '';
        $this->formAmountMax          = $rule->amount_max !== null ? number_format($rule->amount_max / 100, 2, ',', '') : '';
        $this->formRequiredApprovals  = $rule->required_approvals;
        $this->formApproverPermission = $rule->approver_permission;
        $this->formBlockSubmitter     = $rule->block_submitter;
        $this->formBlockBeneficiary   = $rule->block_beneficiary;
        $this->formIsActive           = $rule->is_active;
        $this->formSortOrder          = $rule->sort_order;
        $this->showForm               = true;
    }

    public function save(): void
    {
        abort_unless(Auth::user()->can('accounting.admin'), 403);

        $this->validate([
            'formName'               => 'required|string|max:100',
            'formInvoiceType'        => 'required|in:payable,receivable,expense,any',
            'formAmountMin'          => 'nullable|numeric|min:0',
            'formAmountMax'          => 'nullable|numeric|min:0',
            'formRequiredApprovals'  => 'required|integer|min:1|max:10',
            'formApproverPermission' => 'required|string|max:100',
            'formSortOrder'          => 'required|integer|min:0',
        ]);

        $data = [
            'name'                => $this->formName,
            'invoice_type'        => $this->formInvoiceType === 'any' ? null : $this->formInvoiceType,
            'amount_min'          => $this->formAmountMin !== ''
                ? (int) round((float) str_replace(',', '.', $this->formAmountMin) * 100)
                : null,
            'amount_max'          => $this->formAmountMax !== ''
                ? (int) round((float) str_replace(',', '.', $this->formAmountMax) * 100)
                : null,
            'required_approvals'  => $this->formRequiredApprovals,
            'approver_permission' => $this->formApproverPermission,
            'block_submitter'     => $this->formBlockSubmitter,
            'block_beneficiary'   => $this->formBlockBeneficiary,
            'is_active'           => $this->formIsActive,
            'sort_order'          => $this->formSortOrder,
        ];

        if ($this->editingId) {
            $rule = ApprovalRule::findOrFail($this->editingId);
            $rule->update($data);
        } else {
            $rule = ApprovalRule::create($data);
        }
        AuditLog::record('approval_rule_created', 'accounting', $rule->name, $rule);

        $this->showForm = false;
        unset($this->rules);
        $this->dispatch('notify', message: $this->editingId ? 'Attestregel uppdaterad.' : 'Attestregel skapad.');
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        abort_unless(Auth::user()->can('accounting.admin'), 403);
        $rule = ApprovalRule::findOrFail($id);
        $rule->update(['is_active' => ! $rule->is_active]);
        AuditLog::record('approval_rule_toggled', 'accounting', $rule->name, $rule);
        unset($this->rules);
    }

    public function delete(int $id): void
    {
        abort_unless(Auth::user()->can('accounting.admin'), 403);
        $rule = ApprovalRule::findOrFail($id);
        $description = $rule->name;
        $rule->delete();
        AuditLog::record('approval_rule_deleted', 'accounting', $description, null);
        unset($this->rules);
        $this->dispatch('notify', message: 'Attestregel borttagen.');
    }

    private function resetForm(): void
    {
        $this->formName               = '';
        $this->formInvoiceType        = 'any';
        $this->formAmountMin          = '';
        $this->formAmountMax          = '';
        $this->formRequiredApprovals  = 1;
        $this->formApproverPermission = 'accounting.approve';
        $this->formBlockSubmitter     = true;
        $this->formBlockBeneficiary   = true;
        $this->formIsActive           = true;
        $this->formSortOrder          = 0;
        $this->editingId              = null;
        $this->resetErrorBag();
    }
};
?>

<div class="space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Attestregler</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Regler som styr när fakturor kräver attest innan de kan bokföras.
            </p>
        </div>
        <flux:button wire:click="openCreate" variant="primary">
            Ny attestregel
        </flux:button>
    </div>

    {{-- Form --}}
    @if ($showForm)
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6 space-y-5">
        <h2 class="font-medium text-zinc-800 dark:text-zinc-200">
            {{ $editingId ? 'Redigera attestregel' : 'Ny attestregel' }}
        </h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
                <flux:label for="formName">Namn</flux:label>
                <flux:input id="formName" wire:model="formName" placeholder="t.ex. Alla leverantörsfakturor över 5 000 kr" />
                @error('formName') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <flux:label for="formInvoiceType">Fakturatyp</flux:label>
                <flux:select id="formInvoiceType" wire:model="formInvoiceType">
                    <option value="any">Alla typer</option>
                    <option value="payable">Leverantörsfakturor</option>
                    <option value="receivable">Kundfakturor</option>
                    <option value="expense">Utläggsanspråk</option>
                </flux:select>
            </div>

            <div>
                <flux:label for="formRequiredApprovals">Antal godkännanden</flux:label>
                <flux:input id="formRequiredApprovals" type="number" wire:model="formRequiredApprovals" min="1" max="10" />
                @error('formRequiredApprovals') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <flux:label for="formAmountMin">Beloppsundergräns (kr, tom = ingen)</flux:label>
                <flux:input id="formAmountMin" wire:model="formAmountMin" placeholder="t.ex. 1000" inputmode="decimal" />
                @error('formAmountMin') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <flux:label for="formAmountMax">Beloppsgräns (kr, tom = ingen)</flux:label>
                <flux:input id="formAmountMax" wire:model="formAmountMax" placeholder="t.ex. 50000" inputmode="decimal" />
                @error('formAmountMax') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <flux:label for="formApproverPermission">Behörighet som krävs för attest</flux:label>
                <flux:input id="formApproverPermission" wire:model="formApproverPermission" />
                <p class="text-xs text-zinc-400 mt-1">Standardvärde: <code>accounting.approve</code></p>
                @error('formApproverPermission') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <flux:label for="formSortOrder">Sorteringsordning</flux:label>
                <flux:input id="formSortOrder" type="number" wire:model="formSortOrder" min="0" />
            </div>

            <div class="flex flex-col gap-3 pt-5">
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <input type="checkbox" wire:model="formBlockSubmitter"
                        class="rounded border-zinc-300 text-brand focus:ring-brand" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">
                        Den som skickade fakturan får inte attestera
                    </span>
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <input type="checkbox" wire:model="formBlockBeneficiary"
                        class="rounded border-zinc-300 text-brand focus:ring-brand" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">
                        Fakturans mottagare/förmånstagare får inte attestera
                    </span>
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <input type="checkbox" wire:model="formIsActive"
                        class="rounded border-zinc-300 text-brand focus:ring-brand" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Aktiv</span>
                </label>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2 border-t border-zinc-100 dark:border-zinc-700">
            <flux:button wire:click="$set('showForm', false)" variant="ghost">Avbryt</flux:button>
            <flux:button wire:click="save" variant="primary">Spara</flux:button>
        </div>
    </div>
    @endif

    {{-- Rules table --}}
    @if ($this->rules->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-200 dark:border-zinc-700 py-12 text-center">
            <x-bootstrap-icon name="card-checklist" class="size-8 text-zinc-300 dark:text-zinc-600 mx-auto mb-2" />
            <p class="text-sm text-zinc-400">Inga attestregler konfigurerade.</p>
            <p class="text-xs text-zinc-400 mt-1">
                Utan regler kan alla fakturor bokföras direkt utan attest.
            </p>
        </div>
    @else
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800 text-xs font-medium text-zinc-500 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Namn</th>
                        <th class="px-4 py-2.5 text-left">Typ</th>
                        <th class="px-4 py-2.5 text-left">Belopp</th>
                        <th class="px-4 py-2.5 text-left">Godkännanden</th>
                        <th class="px-4 py-2.5 text-left">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($this->rules as $rule)
                    <tr class="{{ $rule->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3 font-medium text-zinc-800 dark:text-zinc-200">{{ $rule->name }}</td>
                        <td class="px-4 py-3 text-zinc-500">
                            @match($rule->invoice_type)
                                'payable'    => 'Leverantörsfaktura',
                                'receivable' => 'Kundfaktura',
                                'expense'    => 'Utlägg',
                                default      => 'Alla typer',
                            @endmatch
                        </td>
                        <td class="px-4 py-3 text-zinc-500 font-mono text-xs whitespace-nowrap">
                            @if ($rule->amount_min !== null || $rule->amount_max !== null)
                                {{ $rule->amount_min !== null ? number_format($rule->amount_min / 100, 0, ',', ' ') . ' kr' : '–' }}
                                –
                                {{ $rule->amount_max !== null ? number_format($rule->amount_max / 100, 0, ',', ' ') . ' kr' : '∞' }}
                            @else
                                Alla belopp
                            @endif
                        </td>
                        <td class="px-4 py-3 text-zinc-500">{{ $rule->required_approvals }} st</td>
                        <td class="px-4 py-3">
                            <button wire:click="toggleActive({{ $rule->id }})"
                                class="inline-flex items-center gap-1.5 text-xs rounded-full px-2.5 py-0.5 font-medium
                                    {{ $rule->is_active
                                        ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                        : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                {{ $rule->is_active ? 'Aktiv' : 'Inaktiv' }}
                            </button>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <button wire:click="openEdit({{ $rule->id }})"
                                    class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300">
                                    <x-bootstrap-icon name="pencil" class="size-4" />
                                </button>
                                <button wire:click="delete({{ $rule->id }})"
                                    wire:confirm="Ta bort attestregeln '{{ $rule->name }}'?"
                                    class="text-zinc-400 hover:text-red-500 dark:hover:text-red-400">
                                    <x-bootstrap-icon name="trash" class="size-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-xs text-zinc-400 dark:text-zinc-500">
            Regler matchas uppifrån och ner. En faktura som matchar en regel kräver attest oavsett om den matchar fler regler.
        </p>
    @endif

</div>
