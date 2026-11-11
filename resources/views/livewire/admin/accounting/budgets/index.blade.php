<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\Budget;
use App\Models\Accounting\BudgetLine;
use App\Models\Accounting\FiscalYear;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Budgetar');

new class extends Component {

    public bool   $showCreateModal  = false;
    public string $formName         = '';
    public ?int   $formFiscalYearId = null;
    public string $formNotes        = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['accounting.manage', 'accounting.admin']), 403);
    }

    #[Computed]
    public function fiscalYears()
    {
        return FiscalYear::orderByDesc('starts_at')->get();
    }

    #[Computed]
    public function budgets()
    {
        return Budget::with('fiscalYear', 'createdBy')
            ->orderByDesc('created_at')
            ->get();
    }

    public function openCreate(): void
    {
        $this->formName         = '';
        $this->formNotes        = '';
        $this->formFiscalYearId = FiscalYear::where('is_locked', false)
            ->where('starts_at', '<=', today())
            ->where('ends_at', '>=', today())
            ->first()?->id
            ?? FiscalYear::orderByDesc('starts_at')->first()?->id;

        $this->showCreateModal = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate([
            'formName'         => 'required|string|max:100',
            'formFiscalYearId' => 'required|exists:fiscal_years,id',
        ], [
            'formName.required'         => 'Ange ett namn för budgeten.',
            'formFiscalYearId.required' => 'Välj räkenskapsår.',
        ]);

        $budget = Budget::create([
            'name'           => $this->formName,
            'fiscal_year_id' => $this->formFiscalYearId,
            'notes'          => $this->formNotes ?: null,
            'is_active'      => true,
            'is_locked'      => false,
            'created_by'     => Auth::id(),
        ]);

        $this->showCreateModal = false;
        $this->redirect(route('admin.accounting.budgets.edit', $budget), navigate: true);
    }

    /**
     * Create a new budget pre-filled with last year's actual outcome.
     * Annual amount = last year's actual (öre), distribution = monthly actual percentages.
     * Falls back to even distribution if a month has no transactions.
     */
    public function createFromActuals(int $sourceFiscalYearId): void
    {
        abort_unless(Auth::user()->canAny(['accounting.manage', 'accounting.admin']), 403);

        $sourceFy  = FiscalYear::findOrFail($sourceFiscalYearId);
        $targetFy  = FiscalYear::where('starts_at', '>', $sourceFy->ends_at)
            ->orderBy('starts_at')
            ->first();

        if (! $targetFy) {
            $this->addError('general', 'Inget efterföljande räkenskapsår finns att budgetera för.');
            return;
        }

        $budget = Budget::create([
            'name'           => 'Budget ' . $targetFy->name . ' (från utfall ' . $sourceFy->name . ')',
            'fiscal_year_id' => $targetFy->id,
            'notes'          => 'Genererad från utfall ' . $sourceFy->name,
            'is_active'      => true,
            'is_locked'      => false,
            'created_by'     => Auth::id(),
        ]);

        // Fetch actual monthly totals from source fiscal year
        $actuals = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.fiscal_year_id', $sourceFiscalYearId)
            ->whereNull('journal_lines.superseded_at')
            ->whereIn('accounts.type', ['income', 'expense'])
            ->where('accounts.is_group', false)
            ->selectRaw('accounts.id as account_id, accounts.type,
                         MONTH(journal_entries.date) as month_num,
                         SUM(journal_lines.credit) - SUM(journal_lines.debit) as net_credit')
            ->groupBy('accounts.id', 'accounts.type', DB::raw('MONTH(journal_entries.date)'))
            ->get()
            ->groupBy('account_id');

        $accounts = Account::whereIn('type', ['income', 'expense'])
            ->where('is_active', true)
            ->where('is_group', false)
            ->get();

        foreach ($accounts as $acc) {
            $monthlyActuals = $actuals->get($acc->id, collect());

            if ($monthlyActuals->isEmpty()) continue;

            // Net per month: income = credit-debit (positive=revenue), expense = debit-credit (positive=cost)
            $monthlyOre = [];
            for ($m = 1; $m <= 12; $m++) {
                $row    = $monthlyActuals->firstWhere('month_num', $m);
                $net    = (int) ($row?->net_credit ?? 0);
                $monthlyOre[$m] = $acc->type === 'expense' ? -$net : $net;
            }

            $annualOre = array_sum($monthlyOre);
            if ($annualOre <= 0) continue;

            // Calculate percentage for each month (× 100, sum = 10000)
            $pctData = [];
            $assigned = 0;
            $lastNonZeroMonth = null;
            foreach (BudgetLine::MONTHS_PCT as $i => $col) {
                $monthNum = $i + 1;
                $raw = $monthlyOre[$monthNum] > 0
                    ? (int) round($monthlyOre[$monthNum] / $annualOre * 10000)
                    : 0;
                $pctData[$col] = $raw;
                $assigned += $raw;
                if ($raw > 0) $lastNonZeroMonth = $col;
            }

            // Fix rounding so total = 10000
            if ($lastNonZeroMonth && $assigned !== 10000) {
                $pctData[$lastNonZeroMonth] += (10000 - $assigned);
            }

            BudgetLine::create(array_merge(
                ['budget_id' => $budget->id, 'account_id' => $acc->id, 'annual_ore' => $annualOre],
                $pctData
            ));
        }

        $this->redirect(route('admin.accounting.budgets.edit', $budget), navigate: true);
    }
};
?>

<div>

    <div class="mb-4 flex justify-end">
        @can('accounting.manage')
                <flux:button wire:click="openCreate" variant="primary" size="sm">
                    Ny budget
                </flux:button>@endcan
    </div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3">Namn</th>
                    <th class="px-4 py-3 w-36">Räkenskapsår</th>
                    <th class="px-4 py-3 w-28 text-center">Status</th>
                    <th class="px-4 py-3 w-36">Skapad av</th>
                    <th class="px-4 py-3 w-20"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->budgets as $budget)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">
                            {{ $budget->name }}
                            @if ($budget->notes)
                                <p class="text-xs text-zinc-400 font-normal">{{ $budget->notes }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-zinc-500">{{ $budget->fiscalYear?->name ?? '–' }}</td>
                        <td class="px-4 py-3 text-center">
                            @if ($budget->is_locked)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                    <x-bootstrap-icon name="lock-fill" class="size-3" /> Låst
                                </span>
                            @elseif ($budget->is_active)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">
                                    Aktiv
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400">
                                    Inaktiv
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-zinc-500">{{ $budget->createdBy?->name ?? '–' }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-4">
                                @can('accounting.manage')
                                    <button wire:click="createFromActuals({{ $budget->fiscalYear?->id }})"
                                            wire:confirm="Skapa budget för nästa räkenskapsår baserad på utfall från {{ $budget->fiscalYear?->name }}?"
                                            class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 whitespace-nowrap"
                                            title="Skapa nästa års budget från detta utfall">
                                        <x-bootstrap-icon name="copy" class="size-3 inline" /> Nästa år från utfall
                                    </button>
                                @endcan
                                <a href="{{ route('admin.accounting.budgets.edit', $budget) }}" wire:navigate
                                   class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                    Redigera
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-zinc-400">
                            Inga budgetar skapade ännu.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal wire:model="showCreateModal" class="max-w-md">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-4">Ny budget</h2>
            <div class="space-y-4">
                <div>
                    <flux:label for="budgetName">Namn</flux:label>
                    <flux:input id="budgetName" wire:model="formName" placeholder="t.ex. Budget 2027" />
                    @error('formName') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:label for="budgetFy">Räkenskapsår</flux:label>
                    <flux:select id="budgetFy" wire:model="formFiscalYearId">
                        <option value="">Välj räkenskapsår…</option>
                        @foreach ($this->fiscalYears as $fy)
                            <option value="{{ $fy->id }}">{{ $fy->name }}</option>
                        @endforeach
                    </flux:select>
                    @error('formFiscalYearId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:label for="budgetNotes">Anteckningar (valfritt)</flux:label>
                    <flux:textarea id="budgetNotes" wire:model="formNotes" rows="2" />
                </div>
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showCreateModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="save" variant="primary">Skapa och redigera</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
