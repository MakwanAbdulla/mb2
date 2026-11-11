<?php

use App\Models\Accounting\FiscalYear;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Balansräkning');

new class extends Component {

    public ?int   $fiscalYearId = null;
    public string $asOfDate     = '';

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
            $this->asOfDate     = min(today(), $fy->ends_at)->format('Y-m-d');
        }
    }

    #[Computed]
    public function fiscalYears()
    {
        return FiscalYear::orderByDesc('starts_at')->get();
    }

    /**
     * Returns balance sheet data as of asOfDate.
     * All transactions from the start of time (no fiscal-year boundary) up to asOfDate.
     * Result is calculated from income/expense types.
     */
    #[Computed]
    public function data(): array
    {
        if (! $this->fiscalYearId) {
            return ['assets' => collect(), 'liabilities' => collect(), 'equity' => collect(), 'income_net' => 0];
        }

        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.fiscal_year_id', $this->fiscalYearId)
            ->whereNull('journal_lines.superseded_at')
            ->where('accounts.is_group', false)
            ->when($this->asOfDate, fn ($q) => $q->where('journal_entries.date', '<=', $this->asOfDate))
            ->selectRaw('accounts.id, accounts.code, accounts.name, accounts.type,
                         SUM(journal_lines.debit) as d, SUM(journal_lines.credit) as c')
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.code')
            ->get();

        // Assets: debit-normal (net = d - c, positive = asset)
        $assets = $rows->where('type', 'asset')->map(fn ($r) => [
            'code' => $r->code, 'name' => $r->name,
            'net'  => (int)$r->d - (int)$r->c,
        ])->filter(fn ($r) => $r['net'] !== 0)->values();

        // Liabilities: credit-normal (net = c - d, positive = liability)
        $liabilities = $rows->where('type', 'liability')->map(fn ($r) => [
            'code' => $r->code, 'name' => $r->name,
            'net'  => (int)$r->c - (int)$r->d,
        ])->filter(fn ($r) => $r['net'] !== 0)->values();

        // Equity: credit-normal
        $equity = $rows->where('type', 'equity')->map(fn ($r) => [
            'code' => $r->code, 'name' => $r->name,
            'net'  => (int)$r->c - (int)$r->d,
        ])->filter(fn ($r) => $r['net'] !== 0)->values();

        // Income statement result for the fiscal year up to asOfDate
        $incomeNet  = $rows->where('type', 'income')->sum(fn ($r)  => (int)$r->c - (int)$r->d);
        $expenseNet = $rows->where('type', 'expense')->sum(fn ($r) => (int)$r->d - (int)$r->c);
        $result     = $incomeNet - $expenseNet;

        return compact('assets', 'liabilities', 'equity', 'result');
    }

    #[Computed]
    public function totals(): array
    {
        $d = $this->data;
        return [
            'assets'      => $d['assets']->sum('net'),
            'liabilities' => $d['liabilities']->sum('net')
                           + $d['equity']->sum('net')
                           + $d['result'],
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
            <label class="lbl">Per datum</label>
            <flux:input type="date" wire:model.live="asOfDate" />
        </div>
    </div>

    @if ($this->fiscalYearId)
        @php
            $d      = $this->data;
            $totals = $this->totals;
            $diff   = $totals['assets'] - $totals['liabilities'];
            $balanced = $diff === 0;
        @endphp

        @if (! $balanced)
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-800/40 dark:bg-amber-900/20 dark:text-amber-400">
                <x-bootstrap-icon name="exclamation-triangle-fill" class="size-4 inline mr-1" />
                Balansen stämmer inte — differens {{ format_ore(abs($diff)) }}.
                Kontrollera att alla konton är korrekt klassificerade.
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">

            {{-- Tillgångar --}}
            <div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    <div class="bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700 px-5 py-3 flex items-center justify-between">
                        <span class="text-sm font-semibold text-zinc-600 dark:text-zinc-300 uppercase tracking-wider">Tillgångar</span>
                        <span class="font-mono text-sm font-bold">{{ format_ore($totals['assets']) }}</span>
                    </div>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                            @forelse ($d['assets'] as $row)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                    <td class="px-5 py-2.5 font-mono text-xs font-semibold w-20">{{ $row['code'] }}</td>
                                    <td class="px-5 py-2.5 text-zinc-700 dark:text-zinc-300">{{ $row['name'] }}</td>
                                    <td class="px-5 py-2.5 text-right font-mono text-sm {{ $row['net'] < 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                        {{ format_ore(abs($row['net'])) }}
                                        @if ($row['net'] < 0)<span class="text-xs ml-0.5">−</span>@endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-5 py-6 text-center text-sm text-zinc-400">Inga tillgångskonton med saldo.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot class="border-t-2 border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 font-semibold">
                            <tr>
                                <td colspan="2" class="px-5 py-3 text-sm">Summa tillgångar</td>
                                <td class="px-5 py-3 text-right font-mono">{{ format_ore($totals['assets']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            {{-- Skulder + Eget kapital --}}
            <div class="space-y-4">

                {{-- Skulder --}}
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    <div class="bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700 px-5 py-3 flex items-center justify-between">
                        <span class="text-sm font-semibold text-zinc-600 dark:text-zinc-300 uppercase tracking-wider">Skulder</span>
                        <span class="font-mono text-sm font-bold">{{ format_ore($d['liabilities']->sum('net')) }}</span>
                    </div>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                            @forelse ($d['liabilities'] as $row)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                    <td class="px-5 py-2.5 font-mono text-xs font-semibold w-20">{{ $row['code'] }}</td>
                                    <td class="px-5 py-2.5 text-zinc-700 dark:text-zinc-300">{{ $row['name'] }}</td>
                                    <td class="px-5 py-2.5 text-right font-mono text-sm {{ $row['net'] < 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                        {{ format_ore(abs($row['net'])) }}
                                        @if ($row['net'] < 0)<span class="text-xs ml-0.5">−</span>@endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-5 py-6 text-center text-sm text-zinc-400">Inga skuldkonton med saldo.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Eget kapital --}}
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    <div class="bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700 px-5 py-3 flex items-center justify-between">
                        <span class="text-sm font-semibold text-zinc-600 dark:text-zinc-300 uppercase tracking-wider">Eget kapital</span>
                        <span class="font-mono text-sm font-bold">{{ format_ore($d['equity']->sum('net') + $d['result']) }}</span>
                    </div>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                            @foreach ($d['equity'] as $row)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                    <td class="px-5 py-2.5 font-mono text-xs font-semibold w-20">{{ $row['code'] }}</td>
                                    <td class="px-5 py-2.5 text-zinc-700 dark:text-zinc-300">{{ $row['name'] }}</td>
                                    <td class="px-5 py-2.5 text-right font-mono text-sm {{ $row['net'] < 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                        {{ format_ore(abs($row['net'])) }}
                                        @if ($row['net'] < 0)<span class="text-xs ml-0.5">−</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                            {{-- Årets resultat (from income statement) --}}
                            <tr class="bg-zinc-50/50 dark:bg-zinc-800/30">
                                <td class="px-5 py-2.5 font-mono text-xs font-semibold w-20">–</td>
                                <td class="px-5 py-2.5 text-zinc-600 dark:text-zinc-400 italic">Årets resultat</td>
                                <td class="px-5 py-2.5 text-right font-mono text-sm {{ $d['result'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">
                                    @if ($d['result'] < 0)−@endif{{ format_ore(abs($d['result'])) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                {{-- Total skulder + EK --}}
                <div class="rounded-lg border {{ $balanced ? 'border-green-300 dark:border-green-700' : 'border-amber-300 dark:border-amber-700' }} px-5 py-3 flex justify-between items-center">
                    <span class="text-sm font-semibold">Summa skulder + eget kapital</span>
                    <span class="font-mono font-bold text-base">{{ format_ore($totals['liabilities']) }}</span>
                </div>

            </div>
        </div>
    @else
        <div class="py-16 text-center text-sm text-zinc-400">Välj ett räkenskapsår för att visa balansräkning.</div>
    @endif
</div>
