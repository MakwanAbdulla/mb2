<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\JournalSeries;
use App\Models\Accounting\JournalTemplate;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Konteringsmallar');

new class extends Component {

    public bool $showCreateModal = false;
    public bool $showEditModal   = false;
    public ?int $editingId       = null;

    // Header fields
    public string $formName        = '';
    public string $formDescription = '';
    public string $formSeries      = 'A';

    // Lines: [{account_id, cost_center_id, description, side, formula}]
    public array $formLines = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    #[Computed]
    public function templates()
    {
        return JournalTemplate::orderBy('name')->get();
    }

    #[Computed]
    public function accounts()
    {
        return Account::where('is_active', true)->where('is_group', false)
            ->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function costCenters()
    {
        return CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function series()
    {
        return JournalSeries::orderBy('code')->get(['code', 'name']);
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->formLines = $this->emptyLines(2);
        $this->showCreateModal = true;
    }

    public function openEdit(int $id): void
    {
        $tpl = JournalTemplate::findOrFail($id);

        $this->editingId       = $id;
        $this->formName        = $tpl->name;
        $this->formDescription = $tpl->description ?? '';
        $this->formSeries      = $tpl->series ?? 'A';
        $this->formLines       = array_map(fn ($l) => [
            'account_id'     => (string) ($l['account_id'] ?? ''),
            'cost_center_id' => (string) ($l['cost_center_id'] ?? ''),
            'description'    => $l['description'] ?? '',
            'side'           => $l['side'] ?? 'debit',
            'formula'        => $l['formula'] ?? '',
        ], $tpl->lines ?? []);

        if (count($this->formLines) < 2) {
            $this->formLines = array_merge($this->formLines, $this->emptyLines(2 - count($this->formLines)));
        }

        $this->showEditModal = true;
    }

    public function addLine(): void
    {
        $this->formLines[] = ['account_id' => '', 'cost_center_id' => '', 'description' => '', 'side' => 'debit', 'formula' => ''];
    }

    public function removeLine(int $index): void
    {
        if (count($this->formLines) > 2) {
            array_splice($this->formLines, $index, 1);
            $this->formLines = array_values($this->formLines);
        }
    }

    public function save(): void
    {
        $this->validate($this->validationRules());

        $tpl = JournalTemplate::create([
            'name'        => $this->formName,
            'description' => $this->formDescription ?: null,
            'series'      => $this->formSeries,
            'lines'       => $this->cleanLines(),
            'is_active'   => true,
        ]);
        AuditLog::record('journal_template_created', 'accounting', $tpl->name, $tpl);

        $this->showCreateModal = false;
        $this->resetForm();
        unset($this->templates);
    }

    public function update(): void
    {
        $this->validate($this->validationRules());

        $tpl = JournalTemplate::findOrFail($this->editingId);
        $tpl->update([
            'name'        => $this->formName,
            'description' => $this->formDescription ?: null,
            'series'      => $this->formSeries,
            'lines'       => $this->cleanLines(),
        ]);
        AuditLog::record('journal_template_updated', 'accounting', $tpl->name, $tpl);

        $this->showEditModal = false;
        $this->resetForm();
        unset($this->templates);
    }

    public function delete(int $id): void
    {
        $tpl  = JournalTemplate::findOrFail($id);
        $name = $tpl->name;
        $tpl->delete();
        AuditLog::record('journal_template_deleted', 'accounting', $name, null);
        unset($this->templates);
    }

    public function activate(int $id): void
    {
        $tpl = JournalTemplate::findOrFail($id);
        $tpl->update(['is_active' => true]);
        AuditLog::record('journal_template_activated', 'accounting', $tpl->name, $tpl);
        unset($this->templates);
    }

    public function deactivate(int $id): void
    {
        $tpl = JournalTemplate::findOrFail($id);
        $tpl->update(['is_active' => false]);
        AuditLog::record('journal_template_deactivated', 'accounting', $tpl->name, $tpl);
        unset($this->templates);
    }

    private function cleanLines(): array
    {
        return array_values(array_map(fn ($l) => [
            'account_id'     => $l['account_id'] ? (int) $l['account_id'] : null,
            'cost_center_id' => $l['cost_center_id'] ? (int) $l['cost_center_id'] : null,
            'description'    => $l['description'] ?: null,
            'side'           => $l['side'],
            'formula'        => $l['formula'] ?: null,
        ], $this->formLines));
    }

    private function validationRules(): array
    {
        return [
            'formName'              => 'required|string|max:100',
            'formSeries'            => 'required|exists:journal_series,code',
            'formLines'             => 'required|array|min:2',
            'formLines.*.account_id'=> 'required|exists:accounts,id',
            'formLines.*.side'      => 'required|in:debit,credit',
            'formLines.*.formula'   => ['nullable', 'string', 'max:100', function ($attr, $val, $fail) {
                if ($val && ! preg_match('/^[\d\s\+\-\*\/\(\)\.Tt otalOTAL]+$/', $val)) {
                    $fail('Formeln innehåller ogiltiga tecken. Använd siffror, +−×÷() och ordet "Total".');
                }
            }],
        ];
    }

    private function emptyLines(int $count): array
    {
        return array_fill(0, $count, [
            'account_id' => '', 'cost_center_id' => '', 'description' => '', 'side' => 'debit', 'formula' => '',
        ]);
    }

    private function resetForm(): void
    {
        $this->editingId       = null;
        $this->formName        = '';
        $this->formDescription = '';
        $this->formSeries      = 'A';
        $this->formLines       = [];
        $this->resetValidation();
    }
};
?>

<div>

    <div class="mb-4 flex justify-end">
        <flux:button wire:click="openCreate" variant="primary" size="sm">
            Ny mall
        </flux:button>
    </div>

    {{-- Template list --}}
    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3">Namn</th>
                    <th class="px-4 py-3 w-16 text-center">Serie</th>
                    <th class="px-4 py-3">Rader</th>
                    <th class="px-4 py-3 w-24 text-center">Status</th>
                    <th class="px-4 py-3 w-40"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->templates as $tpl)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ $tpl->is_active ? '' : 'opacity-60' }}">
                        <td class="px-4 py-3">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ $tpl->name }}</div>
                            @if ($tpl->description)
                                <div class="text-xs text-zinc-400">{{ $tpl->description }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center font-mono font-semibold text-zinc-500 dark:text-zinc-400">
                            {{ $tpl->series }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-col gap-0.5">
                                @foreach ($tpl->lines ?? [] as $line)
                                    @php
                                        $acc = $this->accounts->firstWhere('id', $line['account_id'] ?? null);
                                    @endphp
                                    <div class="text-xs font-mono text-zinc-500 dark:text-zinc-400 flex items-center gap-2">
                                        <span @class([
                                            'w-12 text-right font-semibold',
                                            'text-blue-600 dark:text-blue-400' => ($line['side'] ?? '') === 'debit',
                                            'text-purple-600 dark:text-purple-400' => ($line['side'] ?? '') === 'credit',
                                        ])>
                                            {{ ($line['side'] ?? '') === 'debit' ? 'D' : 'K' }}
                                        </span>
                                        <span>{{ $acc ? $acc->code . ' ' . $acc->name : '–' }}</span>
                                        @if (!empty($line['formula']))
                                            <span class="text-zinc-300 dark:text-zinc-600">= {{ $line['formula'] }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($tpl->is_active)
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
                                @if ($tpl->is_active)
                                    <button wire:click="deactivate({{ $tpl->id }})" class="text-xs text-zinc-500 hover:text-zinc-700">Inaktivera</button>
                                @else
                                    <button wire:click="activate({{ $tpl->id }})" class="text-xs text-green-600 hover:text-green-700">Aktivera</button>
                                @endif
                                <button wire:click="openEdit({{ $tpl->id }})" class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">Redigera</button>
                                <button wire:click="delete({{ $tpl->id }})" wire:confirm="Ta bort mallen '{{ $tpl->name }}'?" class="text-xs text-red-500 hover:text-red-700">Ta bort</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-zinc-400">
                            Inga mallar skapade. Mallar används när du skapar ett verifikat manuellt.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Formula help --}}
    <div class="mt-4 rounded-lg border border-zinc-100 dark:border-zinc-800 px-4 py-3 text-xs text-zinc-400 dark:text-zinc-500">
        <p class="font-medium text-zinc-500 dark:text-zinc-400 mb-1">Formler</p>
        <p>
            Varje rad kan ha en formel som beräknar beloppet automatiskt när du anger ett totalbelopp vid bokning.
            Använd <code class="bg-zinc-100 dark:bg-zinc-700 rounded px-1">Total</code> som variabel.
            Exempel: <code class="bg-zinc-100 dark:bg-zinc-700 rounded px-1">Total / 1.25</code> (netto exkl. 25% moms),
            <code class="bg-zinc-100 dark:bg-zinc-700 rounded px-1">Total - Total / 1.25</code> (momsdelen),
            <code class="bg-zinc-100 dark:bg-zinc-700 rounded px-1">Total * 0.5</code> (halvparten).
            Tom formel = beloppet fylls i manuellt vid bokning.
        </p>
    </div>

    {{-- Create modal --}}
    <flux:modal wire:model="showCreateModal" class="max-w-3xl">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-5">Ny konteringsmall</h2>
            @include('livewire.admin.accounting.journal-templates._form')
            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showCreateModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="save" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit modal --}}
    <flux:modal wire:model="showEditModal" class="max-w-3xl">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-5">Redigera mall</h2>
            @include('livewire.admin.accounting.journal-templates._form')
            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showEditModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="update" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
