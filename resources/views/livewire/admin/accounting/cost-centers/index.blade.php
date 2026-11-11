<?php

use App\Models\Accounting\CostCenter;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Kostnadsställen');

new class extends Component {

    public string $filterActive = 'active';

    public bool  $showCreateModal = false;
    public bool  $showEditModal   = false;
    public ?int  $editingId       = null;

    public string $formCode     = '';
    public string $formName     = '';
    public string $formType     = 'cost_center';
    public string $formNotes    = '';
    public string $formStartsAt = '';
    public string $formEndsAt   = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    #[Computed]
    public function costCenters()
    {
        return CostCenter::query()
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
        $cc = CostCenter::findOrFail($id);
        $this->editingId    = $id;
        $this->formCode     = $cc->code;
        $this->formName     = $cc->name;
        $this->formType     = $cc->type;
        $this->formNotes    = $cc->notes ?? '';
        $this->formStartsAt = $cc->starts_at?->format('Y-m-d') ?? '';
        $this->formEndsAt   = $cc->ends_at?->format('Y-m-d') ?? '';
        $this->showEditModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'formCode' => 'required|string|max:20|unique:cost_centers,code',
            'formName' => 'required|string|max:100',
            'formType' => 'required|in:cost_center,project',
            'formStartsAt' => 'nullable|date',
            'formEndsAt'   => 'nullable|date|after_or_equal:formStartsAt',
        ]);

        $cc = CostCenter::create([
            'code'      => strtoupper($this->formCode),
            'name'      => $this->formName,
            'type'      => $this->formType,
            'notes'     => $this->formNotes ?: null,
            'starts_at' => $this->formStartsAt ?: null,
            'ends_at'   => $this->formEndsAt ?: null,
            'is_active' => true,
        ]);

        AuditLog::record('cost_center_created', 'accounting', $cc->code . ' ' . $cc->name, $cc);

        $this->showCreateModal = false;
        $this->resetForm();
        unset($this->costCenters);
    }

    public function update(): void
    {
        $cc = CostCenter::findOrFail($this->editingId);

        $this->validate([
            'formCode' => 'required|string|max:20|unique:cost_centers,code,' . $cc->id,
            'formName' => 'required|string|max:100',
            'formType' => 'required|in:cost_center,project',
            'formStartsAt' => 'nullable|date',
            'formEndsAt'   => 'nullable|date|after_or_equal:formStartsAt',
        ]);

        $cc->update([
            'code'      => strtoupper($this->formCode),
            'name'      => $this->formName,
            'type'      => $this->formType,
            'notes'     => $this->formNotes ?: null,
            'starts_at' => $this->formStartsAt ?: null,
            'ends_at'   => $this->formEndsAt ?: null,
        ]);

        AuditLog::record('cost_center_updated', 'accounting', $cc->code . ' ' . $cc->name, $cc);

        $this->showEditModal = false;
        $this->resetForm();
        unset($this->costCenters);
    }

    public function activate(int $id): void
    {
        $cc = CostCenter::findOrFail($id);
        $cc->update(['is_active' => true]);
        AuditLog::record('cost_center_activated', 'accounting', $cc->code . ' ' . $cc->name, $cc);
        unset($this->costCenters);
    }

    public function deactivate(int $id): void
    {
        $cc = CostCenter::findOrFail($id);
        $cc->update(['is_active' => false]);
        AuditLog::record('cost_center_deactivated', 'accounting', $cc->code . ' ' . $cc->name, $cc);
        unset($this->costCenters);
    }

    private function resetForm(): void
    {
        $this->formCode     = '';
        $this->formName     = '';
        $this->formType     = 'cost_center';
        $this->formNotes    = '';
        $this->formStartsAt = '';
        $this->formEndsAt   = '';
        $this->editingId    = null;
        $this->resetValidation();
    }
};
?>

<div>

    <div class="mb-4 flex justify-end">
        <flux:button wire:click="openCreate" variant="primary" size="sm">
                Nytt kostnadsställe
            </flux:button>
    </div>

    {{-- Filter --}}
    <div class="mb-6">
        <flux:select wire:model.live="filterActive" class="w-40">
            <option value="active">Aktiva</option>
            <option value="inactive">Inaktiva</option>
            <option value="all">Alla</option>
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 w-28">Kod</th>
                    <th class="px-4 py-3">Benämning</th>
                    <th class="px-4 py-3 w-28">Typ</th>
                    <th class="px-4 py-3 w-28">Fr.o.m.</th>
                    <th class="px-4 py-3 w-28">T.o.m.</th>
                    <th class="px-4 py-3 w-20 text-center">Status</th>
                    <th class="px-4 py-3 w-28"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->costCenters as $cc)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ $cc->is_active ? '' : 'opacity-60' }}">
                        <td class="px-4 py-3 font-mono font-medium">{{ $cc->code }}</td>
                        <td class="px-4 py-3">{{ $cc->name }}</td>
                        <td class="px-4 py-3">
                            @if ($cc->type === 'project')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                                    Projekt
                                </span>
                            @else
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">Kostnadsst.</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-zinc-500">
                            {{ $cc->starts_at?->format('d/m Y') ?? '–' }}
                        </td>
                        <td class="px-4 py-3 text-xs text-zinc-500">
                            {{ $cc->ends_at?->format('d/m Y') ?? '–' }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($cc->is_active)
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
                                @if ($cc->is_active)
                                    <button wire:click="deactivate({{ $cc->id }})"
                                        class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                        Inaktivera
                                    </button>
                                @else
                                    <button wire:click="activate({{ $cc->id }})"
                                        class="text-xs text-green-600 hover:text-green-700 dark:text-green-400">
                                        Aktivera
                                    </button>
                                @endif
                                <button wire:click="openEdit({{ $cc->id }})"
                                    class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                    Redigera
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-400">
                            Inga kostnadsställen.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Create modal --}}
    <flux:modal wire:model="showCreateModal" class="max-w-lg">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-4">Nytt kostnadsställe</h2>
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:label for="ccCode">Kod</flux:label>
                        <flux:input id="ccCode" wire:model="formCode" placeholder="t.ex. BSK" class="font-mono uppercase" />
                        @error('formCode') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:label for="ccType">Typ</flux:label>
                        <flux:select id="ccType" wire:model="formType">
                            <option value="cost_center">Kostnadsställe</option>
                            <option value="project">Projekt</option>
                        </flux:select>
                    </div>
                </div>
                <div>
                    <flux:label for="ccName">Benämning</flux:label>
                    <flux:input id="ccName" wire:model="formName" placeholder="t.ex. Borås Spelkonvent 2027" />
                    @error('formName') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:label for="ccStartsAt">Fr.o.m. (valfri)</flux:label>
                        <flux:input id="ccStartsAt" type="date" wire:model="formStartsAt" />
                    </div>
                    <div>
                        <flux:label for="ccEndsAt">T.o.m. (valfri)</flux:label>
                        <flux:input id="ccEndsAt" type="date" wire:model="formEndsAt" />
                    </div>
                </div>
                <div>
                    <flux:label for="ccNotes">Anteckning (valfri)</flux:label>
                    <flux:textarea id="ccNotes" wire:model="formNotes" rows="2" />
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
            <h2 class="text-lg font-semibold mb-4">Redigera kostnadsställe</h2>
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:label for="editCcCode">Kod</flux:label>
                        <flux:input id="editCcCode" wire:model="formCode" class="font-mono uppercase" />
                        @error('formCode') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:label for="editCcType">Typ</flux:label>
                        <flux:select id="editCcType" wire:model="formType">
                            <option value="cost_center">Kostnadsställe</option>
                            <option value="project">Projekt</option>
                        </flux:select>
                    </div>
                </div>
                <div>
                    <flux:label for="editCcName">Benämning</flux:label>
                    <flux:input id="editCcName" wire:model="formName" />
                    @error('formName') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:label for="editCcStartsAt">Fr.o.m. (valfri)</flux:label>
                        <flux:input id="editCcStartsAt" type="date" wire:model="formStartsAt" />
                    </div>
                    <div>
                        <flux:label for="editCcEndsAt">T.o.m. (valfri)</flux:label>
                        <flux:input id="editCcEndsAt" type="date" wire:model="formEndsAt" />
                    </div>
                </div>
                <div>
                    <flux:label for="editCcNotes">Anteckning (valfri)</flux:label>
                    <flux:textarea id="editCcNotes" wire:model="formNotes" rows="2" />
                </div>
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showEditModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="update" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
