<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\Budget;
use App\Models\Accounting\BudgetLine;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Redigera budget');

new class extends Component {

    public Budget $budget;

    // Keyed by account_id:
    // ['annual' => '0', 'jan_pct' => '833', 'feb_pct' => '833', ...]
    public array $rows = [];

    public function mount(Budget $budget): void
    {
        abort_unless(Auth::user()->canAny(['accounting.manage', 'accounting.admin']), 403);
        $this->budget = $budget;
        $this->loadRows();
    }

    private function loadRows(): void
    {
        $existing = BudgetLine::where('budget_id', $this->budget->id)
            ->get()
            ->keyBy('account_id');

        foreach ($this->accounts as $acc) {
            $line = $existing->get($acc->id);
            $row  = ['annual' => $line ? (string) ($line->annual_ore / 100) : ''];
            foreach (BudgetLine::MONTHS_PCT as $col) {
                $row[$col] = $line ? (string) ($line->$col / 100) : '';
            }
            $this->rows[$acc->id] = $row;
        }
    }

    #[Computed]
    public function accounts()
    {
        return Account::whereIn('type', ['income', 'expense'])
            ->where('is_active', true)
            ->where('is_group', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);
    }

    #[Computed]
    public function monthLabels(): array
    {
        return ['Jan','Feb','Mar','Apr','Maj','Jun','Jul','Aug','Sep','Okt','Nov','Dec'];
    }

    public function distributeEvenly(int $accountId): void
    {
        // Split 100% into 12 equal parts, put remainder in January
        $each      = (int) floor(10000 / 12);  // e.g. 833
        $remainder = 10000 - $each * 12;        // e.g. 4

        foreach (BudgetLine::MONTHS_PCT as $i => $col) {
            $val = $each + ($i === 0 ? $remainder : 0);
            $this->rows[$accountId][$col] = $i === 0
                ? number_format(($each + $remainder) / 100, 2, ',', '')
                : number_format($each / 100, 2, ',', '');
        }
    }

    public function save(): void
    {
        abort_unless(Auth::user()->canAny(['accounting.manage', 'accounting.admin']), 403);
        abort_unless(! $this->budget->is_locked, 403);

        foreach ($this->accounts as $acc) {
            $row       = $this->rows[$acc->id] ?? [];
            $annualOre = (int) round((float) str_replace(',', '.', $row['annual'] ?? '0') * 100);

            $pctData = [];
            foreach (BudgetLine::MONTHS_PCT as $col) {
                $pctData[$col] = (int) round((float) str_replace(',', '.', $row[$col] ?? '0') * 100);
            }

            if ($annualOre > 0) {
                BudgetLine::updateOrCreate(
                    ['budget_id' => $this->budget->id, 'account_id' => $acc->id],
                    array_merge(['annual_ore' => $annualOre], $pctData)
                );
            } else {
                BudgetLine::where('budget_id', $this->budget->id)
                    ->where('account_id', $acc->id)
                    ->delete();
            }
        }

        AuditLog::record('budget_saved', 'accounting', $this->budget->name, $this->budget);
        $this->dispatch('budget-saved');
    }

    public function toggleLock(): void
    {
        abort_unless(Auth::user()->can('accounting.admin'), 403);
        $this->budget->update(['is_locked' => ! $this->budget->is_locked]);
        $this->budget->refresh();
        AuditLog::record('budget_lock_toggled', 'accounting', $this->budget->name, $this->budget);
    }
};
?>

<div>

    <div class="mb-4 flex justify-end">
        @can('accounting.admin')
                    <flux:button wire:click="toggleLock" variant="ghost" size="sm">
                        <x-bootstrap-icon name="{{ $budget->is_locked ? 'unlock' : 'lock' }}" class="size-4" />
                        {{ $budget->is_locked ? 'Lås upp' : 'Lås budget' }}
                    </flux:button>@endcan
    </div>

    <div x-data>
        <div x-on:budget-saved.window="$dispatch('notify', { message: 'Budget sparad.' })"></div>

        @if ($budget->is_locked)
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800/40 dark:bg-amber-900/20 px-4 py-2 text-sm text-amber-700 dark:text-amber-400">
                Budgeten är låst och kan inte redigeras.
            </div>
        @else
            <div class="mb-4 flex items-center justify-between">
                <p class="text-xs text-zinc-400">
                    Ange årsbelopp i kr och fördelning per månad i procent (summa bör vara 100&nbsp;%).
                    Klicka <em>Fördela jämnt</em> för att dela lika på 12 månader.
                </p>
                <flux:button wire:click="save" variant="primary" size="sm">
                    Spara budget
                </flux:button>
            </div>
        @endif

        @foreach ([['type' => 'income', 'label' => 'Intäkter'], ['type' => 'expense', 'label' => 'Kostnader']] as $group)
            @php $groupAccounts = $this->accounts->where('type', $group['type']); @endphp
            @if ($groupAccounts->isNotEmpty())
                <div class="mb-6 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-xs">
                        <thead class="bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 uppercase tracking-wider font-medium">
                            <tr>
                                <th class="px-3 py-2 text-left sticky left-0 bg-zinc-50 dark:bg-zinc-800 z-10 w-52">
                                    {{ $group['label'] }}
                                </th>
                                <th class="px-2 py-2 text-right w-28">Årsbelopp (kr)</th>
                                @foreach ($this->monthLabels as $label)
                                    <th class="px-1 py-2 text-right w-16">{{ $label }}&nbsp;%</th>
                                @endforeach
                                <th class="px-2 py-2 text-right w-20">Summa&nbsp;%</th>
                                @if (! $budget->is_locked)
                                    <th class="px-2 py-2 w-24"></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($groupAccounts as $acc)
                                @php
                                    $pctSum = collect(BudgetLine::MONTHS_PCT)
                                        ->sum(fn($col) => (float) str_replace(',', '.', $this->rows[$acc->id][$col] ?? '0'));
                                    $pctOk  = abs($pctSum - 100) < 0.1;
                                    $annual = (float) str_replace(',', '.', $this->rows[$acc->id]['annual'] ?? '0');
                                @endphp
                                <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                                    <td class="px-3 py-1.5 sticky left-0 bg-white dark:bg-zinc-900 z-10">
                                        <span class="font-mono font-semibold text-zinc-700 dark:text-zinc-200">{{ $acc->code }}</span>
                                        <span class="text-zinc-400 ml-1">{{ Str::limit($acc->name, 22) }}</span>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        @if (! $budget->is_locked)
                                            <input type="text"
                                                   wire:model.lazy="rows.{{ $acc->id }}.annual"
                                                   class="w-full text-right font-mono bg-transparent border-b border-zinc-200 dark:border-zinc-700 focus:border-brand focus:outline-none py-0.5"
                                                   placeholder="0" />
                                        @else
                                            <div class="text-right font-mono py-0.5 text-zinc-600 dark:text-zinc-300">
                                                {{ $annual ? number_format($annual, 0, ',', ' ') : '–' }}
                                            </div>
                                        @endif
                                    </td>
                                    @foreach (BudgetLine::MONTHS_PCT as $col)
                                        <td class="px-1 py-1.5">
                                            @if (! $budget->is_locked)
                                                <input type="text"
                                                       wire:model.lazy="rows.{{ $acc->id }}.{{ $col }}"
                                                       class="w-full text-right font-mono bg-transparent border-b border-zinc-200 dark:border-zinc-700 focus:border-brand focus:outline-none py-0.5"
                                                       placeholder="0" />
                                            @else
                                                <div class="text-right font-mono py-0.5 text-zinc-500">
                                                    {{ ($v = (float)str_replace(',','.',$this->rows[$acc->id][$col] ?? '0')) ? number_format($v, 2, ',', '') : '' }}
                                                </div>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="px-2 py-1.5 text-right font-mono font-medium
                                        {{ $annual > 0 && ! $pctOk ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-400' }}">
                                        {{ $annual > 0 ? number_format($pctSum, 2, ',', '') : '–' }}
                                    </td>
                                    @if (! $budget->is_locked)
                                        <td class="px-2 py-1.5 text-right">
                                            <button wire:click="distributeEvenly({{ $acc->id }})" type="button"
                                                    class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 whitespace-nowrap">
                                                Fördela jämnt
                                            </button>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endforeach

        @if (! $budget->is_locked)
            <div class="flex justify-end">
                <flux:button wire:click="save" variant="primary">Spara budget</flux:button>
            </div>
        @endif
    </div>
</div>
