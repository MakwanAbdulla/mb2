<?php

use App\Models\Accounting\PaymentTerm;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Betalningsvillkor');

new class extends Component {

    public bool $showCreateModal = false;
    public bool $showEditModal   = false;
    public ?int $editingId       = null;

    public string $formCode      = '';
    public string $formName      = '';
    public string $formDueDays   = '';
    public string $formSortOrder = '';
    public bool   $formIsActive  = true;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    #[Computed]
    public function terms()
    {
        return PaymentTerm::orderBy('sort_order')->orderBy('due_days')->get();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showCreateModal = true;
    }

    public function openEdit(int $id): void
    {
        $term = PaymentTerm::findOrFail($id);
        $this->editingId     = $id;
        $this->formCode      = $term->code;
        $this->formName      = $term->name;
        $this->formDueDays   = (string) $term->due_days;
        $this->formSortOrder = (string) $term->sort_order;
        $this->formIsActive  = $term->is_active;
        $this->showEditModal = true;
    }

    public function save(): void
    {
        $this->validate($this->rules());
        $term = PaymentTerm::create([
            'code'       => $this->formCode,
            'name'       => $this->formName,
            'due_days'   => (int) $this->formDueDays,
            'sort_order' => $this->formSortOrder !== '' ? (int) $this->formSortOrder : 0,
            'is_active'  => true,
        ]);
        AuditLog::record('payment_term_created', 'accounting', $term->name, $term);
        $this->showCreateModal = false;
        $this->resetForm();
        unset($this->terms);
    }

    public function update(): void
    {
        $this->validate($this->rules($this->editingId));
        $term = PaymentTerm::findOrFail($this->editingId);
        $term->update([
            'code'       => $this->formCode,
            'name'       => $this->formName,
            'due_days'   => (int) $this->formDueDays,
            'sort_order' => $this->formSortOrder !== '' ? (int) $this->formSortOrder : 0,
            'is_active'  => $this->formIsActive,
        ]);
        AuditLog::record('payment_term_updated', 'accounting', $term->name, $term);
        $this->showEditModal = false;
        $this->resetForm();
        unset($this->terms);
    }

    public function delete(int $id): void
    {
        $term = PaymentTerm::findOrFail($id);
        $name = $term->name;
        $term->delete();
        AuditLog::record('payment_term_deleted', 'accounting', $name, null);
        unset($this->terms);
    }

    private function rules(?int $ignoreId = null): array
    {
        $unique = 'unique:payment_terms,code' . ($ignoreId ? ",{$ignoreId}" : '');
        return [
            'formCode'      => ['required', 'string', 'max:20', $unique],
            'formName'      => 'required|string|max:100',
            'formDueDays'   => 'required|integer|min:0|max:365',
            'formSortOrder' => 'nullable|integer|min:0',
        ];
    }

    private function resetForm(): void
    {
        $this->editingId     = null;
        $this->formCode      = '';
        $this->formName      = '';
        $this->formDueDays   = '';
        $this->formSortOrder = '';
        $this->formIsActive  = true;
        $this->resetValidation();
    }
};
?>

<div>

    <div class="mb-4 flex justify-end">
        <flux:button wire:click="openCreate" variant="primary" size="sm">
            Nytt villkor
        </flux:button>
    </div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 w-20">Kod</th>
                    <th class="px-4 py-3">Namn</th>
                    <th class="px-4 py-3 w-32 text-right">Dagar</th>
                    <th class="px-4 py-3 w-24 text-center">Status</th>
                    <th class="px-4 py-3 w-32"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->terms as $term)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ $term->is_active ? '' : 'opacity-60' }}">
                        <td class="px-4 py-3 font-mono text-xs font-semibold text-zinc-500">{{ $term->code }}</td>
                        <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">{{ $term->name }}</td>
                        <td class="px-4 py-3 text-right font-mono text-zinc-600 dark:text-zinc-300">
                            {{ $term->due_days === 0 ? 'Kontant' : $term->due_days . ' dagar' }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($term->is_active)
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
                                <button wire:click="openEdit({{ $term->id }})" class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">Redigera</button>
                                <button wire:click="delete({{ $term->id }})" wire:confirm="Ta bort '{{ $term->name }}'?" class="text-xs text-red-500 hover:text-red-700">Ta bort</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-zinc-400">
                            Inga betalningsvillkor. Kör seedern eller skapa nya manuellt.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Create modal --}}
    <flux:modal wire:model="showCreateModal" class="max-w-md">
        <div class="p-6 space-y-4">
            <h2 class="text-lg font-semibold">Nytt betalningsvillkor</h2>
            @include('livewire.admin.accounting.payment-terms._form')
            <div class="flex justify-end gap-3 pt-2">
                <flux:button wire:click="$set('showCreateModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="save" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit modal --}}
    <flux:modal wire:model="showEditModal" class="max-w-md">
        <div class="p-6 space-y-4">
            <h2 class="text-lg font-semibold">Redigera villkor</h2>
            @include('livewire.admin.accounting.payment-terms._form')
            <div class="mt-2">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" wire:model="formIsActive"
                           class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Aktiv</span>
                </label>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <flux:button wire:click="$set('showEditModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="update" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
