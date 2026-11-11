<?php

use App\Models\Accounting\Budget;
use App\Models\Accounting\BudgetLine;
use App\Models\Accounting\FiscalYear;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Budgetuppföljning');

new class extends Component {

    public ?int   $budgetId    = null;
    public string $dateFrom    = '';
    public string $dateTo      = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['accounting.view', 'accounting.manage', 'accounting.admin']), 403);

        // Default to the active budget for the current fiscal year
        $fy = FiscalYear::where('is_locked', false)
            ->where('starts_at', '<=', today())
            ->where('ends_at', '>=', today())
            ->first();

        if ($fy) {
            $budget = Budget::where('fiscal_year_id', $fy->id)
                ->where('is_active', true)
                ->first();

            if ($budget) {
                $this->budgetId = $budget->id;
                $this->dateFrom = $fy->starts_at->format('Y-m-d');
                $this->dateTo   = min(today(), $fy->ends_at)->format('Y-m-d');
            }
        }
    }

    #[Computed]
    public function budgets()
    {
        return Budget::with('fiscalYear')->orderByDesc('created_at')->get();
    }

    #[Computed]
    public function selectedBudget(): ?Budget
    {
        return $this->budgetId ? Budget::with('lines.account', 'fiscalYear')->find($this->budgetId) : null;
    }

    public function updatedBudgetId(): void
    {
        $fy = $this->selectedBudget?->fiscalYear;
        if ($fy) {
            $this->dateFrom = $fy->starts_at->format('Y-m-d');
            $this->dateTo   = min(today(), $fy->ends_at)->format('Y-m-d');
        }
    }

    /**
     * Returns rows with budget, actual and variance per account.
     * Grouped as income and expense.
     */
    #[Computed]
    public function rows(): array
    {
        $budget = $this->selectedBudget;

        if (! $budget) {
            return ['income' => collect(), 'expense' => collect()];
        }

        // Actual amounts from journal lines within date range
        $actuals = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.fiscal_year_id', $budget->fiscal_year_id)
            ->whereNull('journal_lines.superseded_at')
            ->whereIn('accounts.type', ['income', 'expense'])
            ->where('accounts.is_group', false)
            ->when($this->dateFrom, fn ($q) => $q->where('journal_entries.date', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn ($q) => $q->where('journal_entries.date', '<=', $this->dateTo))
            ->selectRaw('accounts.id, SUM(journal_lines.debit) as total_debit, SUM(journal_lines.credit) as total_credit')
            ->groupBy('accounts.id')
            ->get()
            ->keyBy('id');

        // Determine which month numbers (1–12) fall within the date range
        $fromDate = $this->dateFrom ? \Illuminate\Support\Carbon::parse($this->dateFrom) : null;
        $toDate   = $this->dateTo   ? \Illuminate\Support\Carbon::parse($this->dateTo)   : null;
        $fyYear   = $budget->fiscalYear?->starts_at->year ?? now()->year;

        $monthsInRange = [];
        for ($monthNum = 1; $monthNum <= 12; $monthNum++) {
            $monthStart = \Illuminate\Support\Carbon::create($fyYear, $monthNum, 1)->startOfMonth();
            $monthEnd   = $monthStart->copy()->endOfMonth();

            if ((! $fromDate || $monthEnd->gte($fromDate)) &&
                (! $toDate   || $monthStart->lte($toDate))) {
                $monthsInRange[] = $monthNum;
            }
        }

        $buildRows = function (string $type) use ($budget, $actuals, $monthsInRange) {
            return $budget->lines
                ->filter(fn ($l) => $l->account?->type === $type && $l->annualTotal() !== 0)
                ->map(function ($line) use ($type, $actuals, $monthsInRange) {
                    $budgetOre = (int) collect($monthsInRange)->sum(fn ($m) => $line->monthAmount($m));
                    $actual    = $actuals->get($line->account_id);

                    if ($type === 'income') {
                        $actualOre  = (int) ($actual?->total_credit ?? 0) - (int) ($actual?->total_debit ?? 0);
                        $varianceOre = $actualOre - $budgetOre; // positive = better than budget
                    } else {
                        $actualOre   = (int) ($actual?->total_debit ?? 0) - (int) ($actual?->total_credit ?? 0);
                        $varianceOre = $budgetOre - $actualOre; // positive = under budget (good)
                    }

                    return [
                        'code'         => $line->account?->code,
                        'name'         => $line->account?->name,
                        'budget_ore'   => $budgetOre,
                        'actual_ore'   => $actualOre,
                        'variance_ore' => $varianceOre,
                    ];
                })
                ->sortBy('code')
                ->values();
        };

        return [
            'income'  => $buildRows('income'),
            'expense' => $buildRows('expense'),
        ];
    }

    #[Computed]
    public function totals(): array
    {
        $rows = $this->rows;
        return [
            'income' => [
                'budget'   => $rows['income']->sum('budget_ore'),
                'actual'   => $rows['income']->sum('actual_ore'),
                'variance' => $rows['income']->sum('variance_ore'),
            ],
            'expense' => [
                'budget'   => $rows['expense']->sum('budget_ore'),
                'actual'   => $rows['expense']->sum('actual_ore'),
                'variance' => $rows['expense']->sum('variance_ore'),
            ],
        ];
    }
};
?>

<div>



    {{-- Filters --}}
    <div class="mb-5 flex flex-wrap gap-3">
        <div>
            <label class="lbl">Budget</label>
            <flux:select wire:model.live="budgetId" class="w-64">
                <option value="">Välj budget…</option>
                @foreach ($this->budgets as $b)
                    <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->fiscalYear?->name }})</option>
                @endforeach
            </flux:select>
        </div>
        <div>
            <label class="lbl">Fr.o.m.</label>
            <flux:input type="date" wire:model.live="dateFrom" />
        </div>
        <div>
            <label class="lbl">T.o.m.</label>
            <flux:input type="date" wire:model.live="dateTo" />
        </div>
    </div>

    @if (! $this->budgetId)
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 px-4 py-10 text-center text-sm text-zinc-400">
            Välj en budget för att se uppföljningen.
        </div>
    @else
        @php
            $totals = $this->totals;
            $netBudget   = $totals['income']['budget']   - $totals['expense']['budget'];
            $netActual   = $totals['income']['actual']   - $totals['expense']['actual'];
            $netVariance = $totals['income']['variance'] - $totals['expense']['variance'];
        @endphp

        {{-- Summary cards --}}
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
                <p class="text-xs text-zinc-400 uppercase tracking-wider">Budget netto</p>
                <p class="mt-1 text-xl font-mono font-semibold">{{ format_ore($netBudget) }}</p>
            </div>
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
                <p class="text-xs text-zinc-400 uppercase tracking-wider">Utfall netto</p>
                <p class="mt-1 text-xl font-mono font-semibold">{{ format_ore($netActual) }}</p>
            </div>
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
                <p class="text-xs text-zinc-400 uppercase tracking-wider">Avvikelse</p>
                <p class="mt-1 text-xl font-mono font-semibold {{ $netVariance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                    {{ $netVariance >= 0 ? '+' : '' }}{{ format_ore($netVariance) }}
                </p>
            </div>
        </div>

        {{-- Detail table --}}
        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800 text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3 text-left">Konto</th>
                        <th class="px-4 py-3 w-36 text-right">Budget</th>
                        <th class="px-4 py-3 w-36 text-right">Utfall</th>
                        <th class="px-4 py-3 w-36 text-right">Avvikelse</th>
                        <th class="px-4 py-3 w-24 text-right">%</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">

                    {{-- Income --}}
                    <tr class="bg-zinc-50 dark:bg-zinc-800/60">
                        <td colspan="5" class="px-4 py-2 text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                            Intäkter
                        </td>
                    </tr>
                    @foreach ($this->rows['income'] as $row)
                        @php $pct = $row['budget_ore'] ? round($row['actual_ore'] / $row['budget_ore'] * 100) : null; @endphp
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="px-4 py-2.5">
                                <span class="font-mono text-xs font-semibold">{{ $row['code'] }}</span>
                                <span class="text-zinc-500 ml-1 text-xs">{{ $row['name'] }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs text-zinc-500">{{ format_ore($row['budget_ore']) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs">{{ format_ore($row['actual_ore']) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs font-medium {{ $row['variance_ore'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                {{ $row['variance_ore'] >= 0 ? '+' : '' }}{{ format_ore($row['variance_ore']) }}
                            </td>
                            <td class="px-4 py-2.5 text-right text-xs text-zinc-400">
                                {{ $pct !== null ? $pct . ' %' : '–' }}
                            </td>
                        </tr>
                    @endforeach
                    <tr class="bg-zinc-50/70 dark:bg-zinc-800/40 font-semibold text-xs">
                        <td class="px-4 py-2 text-zinc-500 uppercase tracking-wider">Summa intäkter</td>
                        <td class="px-4 py-2 text-right font-mono text-zinc-500">{{ format_ore($totals['income']['budget']) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ format_ore($totals['income']['actual']) }}</td>
                        <td class="px-4 py-2 text-right font-mono {{ $totals['income']['variance'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ $totals['income']['variance'] >= 0 ? '+' : '' }}{{ format_ore($totals['income']['variance']) }}
                        </td>
                        <td></td>
                    </tr>

                    {{-- Expense --}}
                    <tr class="bg-zinc-50 dark:bg-zinc-800/60">
                        <td colspan="5" class="px-4 py-2 text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                            Kostnader
                        </td>
                    </tr>
                    @foreach ($this->rows['expense'] as $row)
                        @php $pct = $row['budget_ore'] ? round($row['actual_ore'] / $row['budget_ore'] * 100) : null; @endphp
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="px-4 py-2.5">
                                <span class="font-mono text-xs font-semibold">{{ $row['code'] }}</span>
                                <span class="text-zinc-500 ml-1 text-xs">{{ $row['name'] }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs text-zinc-500">{{ format_ore($row['budget_ore']) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs">{{ format_ore($row['actual_ore']) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs font-medium {{ $row['variance_ore'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                {{ $row['variance_ore'] >= 0 ? '+' : '' }}{{ format_ore($row['variance_ore']) }}
                            </td>
                            <td class="px-4 py-2.5 text-right text-xs text-zinc-400">
                                {{ $pct !== null ? $pct . ' %' : '–' }}
                            </td>
                        </tr>
                    @endforeach
                    <tr class="bg-zinc-50/70 dark:bg-zinc-800/40 font-semibold text-xs">
                        <td class="px-4 py-2 text-zinc-500 uppercase tracking-wider">Summa kostnader</td>
                        <td class="px-4 py-2 text-right font-mono text-zinc-500">{{ format_ore($totals['expense']['budget']) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ format_ore($totals['expense']['actual']) }}</td>
                        <td class="px-4 py-2 text-right font-mono {{ $totals['expense']['variance'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ $totals['expense']['variance'] >= 0 ? '+' : '' }}{{ format_ore($totals['expense']['variance']) }}
                        </td>
                        <td></td>
                    </tr>

                    {{-- Net result --}}
                    <tr class="border-t-2 border-zinc-300 dark:border-zinc-600 bg-zinc-100 dark:bg-zinc-800 font-bold">
                        <td class="px-4 py-3 text-sm">Nettoresultat</td>
                        <td class="px-4 py-3 text-right font-mono text-sm text-zinc-500">{{ format_ore($netBudget) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-sm">{{ format_ore($netActual) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-sm {{ $netVariance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ $netVariance >= 0 ? '+' : '' }}{{ format_ore($netVariance) }}
                        </td>
                        <td></td>
                    </tr>

                </tbody>
            </table>
        </div>
    @endif
</div>
