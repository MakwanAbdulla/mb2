<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\FiscalYear;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Huvudbok');

new class extends Component {

    public ?int  $fiscalYearId = null;
    public string $accountId   = '';
    public string $dateFrom    = '';
    public string $dateTo      = '';

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

    #[Computed]
    public function accounts()
    {
        return Account::where('is_active', true)
            ->where('is_group', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);
    }

    #[Computed]
    public function selectedAccount(): ?Account
    {
        return $this->accountId ? Account::find($this->accountId) : null;
    }

    /**
     * Returns opening balance (öre) for the selected account: all transactions
     * in the fiscal year BEFORE the dateFrom filter.
     * Positive = debit balance (for asset/expense accounts).
     */
    #[Computed]
    public function openingBalance(): int
    {
        if (! $this->accountId || ! $this->fiscalYearId) return 0;

        $row = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $this->accountId)
            ->where('journal_entries.fiscal_year_id', $this->fiscalYearId)
            ->whereNull('journal_lines.superseded_at')
            ->when($this->dateFrom, fn ($q) => $q->where('journal_entries.date', '<', $this->dateFrom))
            ->selectRaw('SUM(journal_lines.debit) as d, SUM(journal_lines.credit) as c')
            ->first();

        return (int)($row->d ?? 0) - (int)($row->c ?? 0);
    }

    /**
     * Transactions in the selected period, with running balance computed in PHP.
     */
    #[Computed]
    public function rows(): array
    {
        if (! $this->accountId || ! $this->fiscalYearId) return [];

        $lines = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $this->accountId)
            ->where('journal_entries.fiscal_year_id', $this->fiscalYearId)
            ->whereNull('journal_lines.superseded_at')
            ->when($this->dateFrom, fn ($q) => $q->where('journal_entries.date', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn ($q) => $q->where('journal_entries.date', '<=', $this->dateTo))
            ->select(
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.description as line_description',
                'journal_entries.date',
                'journal_entries.series',
                'journal_entries.number',
                'journal_entries.description as entry_description',
                'journal_entries.id as entry_id',
            )
            ->orderBy('journal_entries.date')
            ->orderBy('journal_entries.series')
            ->orderBy('journal_entries.number')
            ->get();

        $balance = $this->openingBalance;
        $rows    = [];

        foreach ($lines as $line) {
            $balance += (int)$line->debit - (int)$line->credit;
            $rows[] = [
                'date'        => $line->date,
                'ver'         => $line->series . $line->number,
                'entry_id'    => $line->entry_id,
                'description' => $line->line_description ?: $line->entry_description,
                'debit'       => (int)$line->debit,
                'credit'      => (int)$line->credit,
                'balance'     => $balance,
            ];
        }

        return $rows;
    }

    #[Computed]
    public function periodTotals(): array
    {
        return [
            'debit'  => array_sum(array_column($this->rows, 'debit')),
            'credit' => array_sum(array_column($this->rows, 'credit')),
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
            <label class="lbl">Konto</label>
            <x-account-select
                :accounts="$this->accounts"
                model="accountId"
                :live="true"
                :nullable="true"
                null-label="Alla konton"
                placeholder="Alla konton…"
                class="w-72"
            />
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

    @if ($this->selectedAccount)
        <div class="mb-4 flex items-center gap-3">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                {{ $this->selectedAccount->code }} – {{ $this->selectedAccount->name }}
            </h2>
            <span class="text-xs px-2 py-0.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-500">
                {{ match($this->selectedAccount->type) {
                    'asset'     => 'Tillgång',
                    'liability' => 'Skuld',
                    'equity'    => 'Eget kapital',
                    'income'    => 'Intäkt',
                    'expense'   => 'Kostnad',
                    default     => $this->selectedAccount->type,
                } }}
            </span>
        </div>
    @endif

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 w-28">Datum</th>
                    <th class="px-4 py-3 w-24">Ver.nr</th>
                    <th class="px-4 py-3">Beskrivning</th>
                    <th class="px-4 py-3 w-32 text-right">Debet</th>
                    <th class="px-4 py-3 w-32 text-right">Kredit</th>
                    <th class="px-4 py-3 w-36 text-right">Saldo</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                {{-- Opening balance row --}}
                @if ($this->selectedAccount && $this->dateFrom)
                    <tr class="bg-zinc-50/50 dark:bg-zinc-800/30 italic text-zinc-500 text-xs">
                        <td class="px-4 py-2.5">–</td>
                        <td class="px-4 py-2.5">–</td>
                        <td class="px-4 py-2.5">Ingående saldo</td>
                        <td class="px-4 py-2.5 text-right">–</td>
                        <td class="px-4 py-2.5 text-right">–</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold not-italic {{ $this->openingBalance < 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-700 dark:text-zinc-300' }}">
                            {{ format_ore(abs($this->openingBalance)) }}{{ $this->openingBalance < 0 ? ' K' : ' D' }}
                        </td>
                    </tr>
                @endif

                @forelse ($this->rows as $row)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-4 py-2.5 text-zinc-500 text-xs">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d/m Y') }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs">
                            <a href="{{ route('admin.accounting.journal-entries.show', $row['entry_id']) }}" wire:navigate
                               class="hover:underline text-complement-light">
                                {{ $row['ver'] }}
                            </a>
                        </td>
                        <td class="px-4 py-2.5 text-zinc-700 dark:text-zinc-300">{{ $row['description'] }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-xs">
                            {{ $row['debit'] > 0 ? format_ore($row['debit']) : '–' }}
                        </td>
                        <td class="px-4 py-2.5 text-right font-mono text-xs">
                            {{ $row['credit'] > 0 ? format_ore($row['credit']) : '–' }}
                        </td>
                        <td class="px-4 py-2.5 text-right font-mono text-sm font-medium {{ $row['balance'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-800 dark:text-zinc-200' }}">
                            {{ format_ore(abs($row['balance'])) }}{{ $row['balance'] < 0 ? ' K' : ' D' }}
                        </td>
                    </tr>
                @empty
                    @if ($this->selectedAccount)
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-sm text-zinc-400">
                                Inga transaktioner för valt konto och period.
                            </td>
                        </tr>
                    @else
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-sm text-zinc-400">
                                Välj ett konto ovan för att visa huvudboken.
                            </td>
                        </tr>
                    @endif
                @endforelse
            </tbody>
            @if (count($this->rows) > 0)
                <tfoot class="border-t-2 border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 font-semibold text-sm">
                    <tr>
                        <td colspan="3" class="px-4 py-3 text-zinc-500 dark:text-zinc-400">
                            Periodsumma ({{ count($this->rows) }} rader)
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ format_ore($this->periodTotals['debit']) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ format_ore($this->periodTotals['credit']) }}</td>
                        <td class="px-4 py-3 text-right font-mono">
                            @php $__rows = $this->rows; $finalBalance = end($__rows)['balance'] ?? $this->openingBalance; @endphp
                            <span class="{{ $finalBalance < 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                {{ format_ore(abs($finalBalance)) }}{{ $finalBalance < 0 ? ' K' : ' D' }}
                            </span>
                        </td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @if ($this->selectedAccount)
        <p class="mt-2 text-xs text-zinc-400">D = Debetsaldo &nbsp;·&nbsp; K = Kreditsaldo</p>
    @endif
</div>
