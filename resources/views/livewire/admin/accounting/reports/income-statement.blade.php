<?php

use App\Models\Accounting\FiscalYear;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Resultaträkning');

new class extends Component {

    public ?int   $fiscalYearId = null;
    public string $dateFrom     = '';
    public string $dateTo       = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['accounting.view', 'accounting.manage', 'accounting.admin']), 403);

        $fy = FiscalYear::where('is_locked', false)
            ->where('starts_at', '<=', today())
            ->where('ends_at', '>=', today())
            ->first()
            ?? FiscalYear::orderByDesc('starts_at')->first();

        if ($fy) {
            $this->fiscalYearId = $fy->id;
            $this->dateFrom     = $fy->starts_at->format('Y-m-d');
            $this->dateTo       = min(today(), $fy->ends_at)->format('Y-m-d');
        }
    }

    #[Computed]
    public function fiscalYears()
    {
        return FiscalYear::orderByDesc('starts_at')->get();
    }

    /**
     * Returns rows grouped by type (income/expense), each with:
     * code, name, net (positive = favourable direction).
     *
     * Income:  net = credit - debit  (positive = revenue)
     * Expense: net = debit - credit  (positive = cost)
     */
    #[Computed]
    public function rows(): array
    {
        if (! $this->fiscalYearId) return ['income' => collect(), 'expense' => collect()];

        $data = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.fiscal_year_id', $this->fiscalYearId)
            ->whereNull('journal_lines.superseded_at')
            ->whereIn('accounts.type', ['income', 'expense'])
            ->where('accounts.is_group', false)
            ->when($this->dateFrom, fn ($q) => $q->where('journal_entries.date', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn ($q) => $q->where('journal_entries.date', '<=', $this->dateTo))
            ->selectRaw('accounts.id, accounts.code, accounts.name, accounts.type,
                         SUM(journal_lines.debit) as total_debit,
                         SUM(journal_lines.credit) as total_credit')
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.code')
            ->get();

        $income  = $data->where('type', 'income')->map(fn ($r) => [
            'code' => $r->code,
            'name' => $r->name,
            'net'  => (int)$r->total_credit - (int)$r->total_debit,  // positive = revenue
        ])->values();

        $expense = $data->where('type', 'expense')->map(fn ($r) => [
            'code' => $r->code,
            'name' => $r->name,
            'net'  => (int)$r->total_debit - (int)$r->total_credit,  // positive = cost
        ])->values();

        return compact('income', 'expense');
    }

    #[Computed]
    public function totals(): array
    {
        $rows = $this->rows;
        return [
            'income'  => $rows['income']->sum('net'),
            'expense' => $rows['expense']->sum('net'),
            'result'  => $rows['income']->sum('net') - $rows['expense']->sum('net'),
        ];
    }
};
?>

<div>



    {{-- Filters --}}
    <div class="mb-5 flex flex-wrap gap-3">
        <div>
            <label class="lbl">Räkenskapsår</label>
            <flux:select wire:model.live="fiscalYearId" class="w-52">
                <option value="">Välj räkenskapsår…</option>
                @foreach ($this->fiscalYears as $fy)
                    <option value="{{ $fy->id }}">{{ $fy->name }}</option>
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

    @if ($this->fiscalYearId)
        <div class="grid gap-6 lg:grid-cols-2">

            {{-- Intäkter --}}
            <div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    <div class="bg-green-50 dark:bg-green-900/20 border-b border-zinc-200 dark:border-zinc-700 px-5 py-3 flex items-center justify-between">
                        <span class="text-sm font-semibold text-green-700 dark:text-green-400 uppercase tracking-wider">Intäkter</span>
                        <span class="font-mono text-sm font-bold text-green-700 dark:text-green-400">
                            {{ format_ore($this->totals['income']) }}
                        </span>
                    </div>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                            @forelse ($this->rows['income'] as $row)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                    <td class="px-5 py-2.5 font-mono text-xs font-semibold w-20">{{ $row['code'] }}</td>
                                    <td class="px-5 py-2.5 text-zinc-700 dark:text-zinc-300">{{ $row['name'] }}</td>
                                    <td class="px-5 py-2.5 text-right font-mono text-sm {{ $row['net'] < 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                        {{ format_ore(abs($row['net'])) }}
                                        @if ($row['net'] < 0)<span class="text-xs ml-0.5">−</span>@endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-5 py-6 text-center text-sm text-zinc-400">Inga intäkter.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Kostnader --}}
            <div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    <div class="bg-red-50 dark:bg-red-900/20 border-b border-zinc-200 dark:border-zinc-700 px-5 py-3 flex items-center justify-between">
                        <span class="text-sm font-semibold text-red-700 dark:text-red-400 uppercase tracking-wider">Kostnader</span>
                        <span class="font-mono text-sm font-bold text-red-700 dark:text-red-400">
                            {{ format_ore($this->totals['expense']) }}
                        </span>
                    </div>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                            @forelse ($this->rows['expense'] as $row)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                    <td class="px-5 py-2.5 font-mono text-xs font-semibold w-20">{{ $row['code'] }}</td>
                                    <td class="px-5 py-2.5 text-zinc-700 dark:text-zinc-300">{{ $row['name'] }}</td>
                                    <td class="px-5 py-2.5 text-right font-mono text-sm">{{ format_ore($row['net']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-5 py-6 text-center text-sm text-zinc-400">Inga kostnader.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        {{-- Resultat --}}
        <div class="mt-4 rounded-xl border-2 {{ $this->totals['result'] >= 0 ? 'border-green-300 dark:border-green-700' : 'border-red-300 dark:border-red-700' }} p-5 flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Årets resultat</p>
                <p class="text-xs text-zinc-400">Intäkter {{ format_ore($this->totals['income']) }} − Kostnader {{ format_ore($this->totals['expense']) }}</p>
            </div>
            <p class="text-2xl font-bold font-mono {{ $this->totals['result'] >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                @if ($this->totals['result'] < 0)−@endif{{ format_ore(abs($this->totals['result'])) }}
            </p>
        </div>
    @else
        <div class="py-16 text-center text-sm text-zinc-400">Välj ett räkenskapsår för att visa resultaträkning.</div>
    @endif
</div>
