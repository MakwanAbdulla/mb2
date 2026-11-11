<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalSeries;
use App\Models\Accounting\JournalTemplate;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Ny verifikation');

new class extends Component {

    #[Url]
    public ?int $fiscal_year = null;

    public string $formSeries      = 'A';
    public string $formDate        = '';
    public string $formDescription = '';

    public array $lines = [
        ['account_id' => '', 'cost_center_id' => '', 'debit' => '', 'credit' => '', 'description' => ''],
        ['account_id' => '', 'cost_center_id' => '', 'debit' => '', 'credit' => '', 'description' => ''],
    ];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $this->formDate = today()->format('Y-m-d');

        // Default to the currently active (ongoing) fiscal year, not just the latest
        if (! $this->fiscal_year) {
            $fy = FiscalYear::where('is_locked', false)
                ->where('starts_at', '<=', today())
                ->where('ends_at', '>=', today())
                ->latest('starts_at')
                ->first()
                ?? FiscalYear::where('is_locked', false)->latest('starts_at')->first();
            $this->fiscal_year = $fy?->id;
        }

        // Pre-fill from receipt scanner
        if ($prefill = session()->pull('receipt_prefill')) {
            if (! empty($prefill['date']))        $this->formDate        = $prefill['date'];
            if (! empty($prefill['description'])) $this->formDescription = $prefill['description'];
            if (! empty($prefill['lines']))        $this->lines           = $prefill['lines'];
        }
    }

    #[Computed]
    public function openFiscalYears()
    {
        // All non-locked fiscal years — may be more than one (closing prev + ongoing new)
        return FiscalYear::where('is_locked', false)->orderByDesc('starts_at')->get();
    }

    #[Computed]
    public function manualSeries()
    {
        return JournalSeries::where('is_manual', true)->orderBy('sort_order')->get();
    }

    #[Computed]
    public function accounts()
    {
        return Account::where('is_active', true)->where('is_group', false)->orderBy('code')->get();
    }

    #[Computed]
    public function costCenters()
    {
        return CostCenter::where('is_active', true)->orderBy('code')->get();
    }

    #[Computed]
    public function selectedFiscalYear(): ?FiscalYear
    {
        return $this->fiscal_year ? FiscalYear::find($this->fiscal_year) : null;
    }

    /**
     * Current balance (öre, debit-normal) per line index, before this entry is posted.
     * Keyed by line index. Recomputes when account_id, formDate or fiscal_year changes.
     */
    #[Computed]
    public function lineBalances(): array
    {
        if (! $this->fiscal_year || ! $this->formDate) return [];

        $accountIds = collect($this->lines)
            ->pluck('account_id')
            ->filter(fn ($id) => $id !== '' && $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($accountIds)) return [];

        $ob = DB::table('opening_balances')
            ->where('fiscal_year_id', $this->fiscal_year)
            ->whereIn('account_id', $accountIds)
            ->pluck('amount', 'account_id');

        $totals = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.fiscal_year_id', $this->fiscal_year)
            ->whereNull('journal_lines.superseded_at')
            ->whereIn('journal_lines.account_id', $accountIds)
            ->where('journal_entries.date', '<=', $this->formDate)
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) as d, SUM(journal_lines.credit) as c')
            ->groupBy('journal_lines.account_id')
            ->get()
            ->keyBy('account_id');

        $result = [];
        foreach ($this->lines as $i => $line) {
            $id = (int) ($line['account_id'] ?? 0);
            if (! $id) continue;

            $before = (int) ($ob->get($id, 0))
                + (int) ($totals->get($id)?->d ?? 0)
                - (int) ($totals->get($id)?->c ?? 0);

            $debit  = (int) round((float) str_replace(',', '.', $line['debit']  ?: '0') * 100);
            $credit = (int) round((float) str_replace(',', '.', $line['credit'] ?: '0') * 100);

            $result[$i] = ['before' => $before, 'after' => $before + $debit - $credit];
        }

        return $result;
    }

    /** Running totals for balance indicator. */
    #[Computed]
    public function totalDebit(): int
    {
        return (int) array_sum(array_map(fn ($l) =>
            (int) round((float) str_replace(',', '.', $l['debit'] ?: '0') * 100),
            $this->lines
        ));
    }

    #[Computed]
    public function totalCredit(): int
    {
        return (int) array_sum(array_map(fn ($l) =>
            (int) round((float) str_replace(',', '.', $l['credit'] ?: '0') * 100),
            $this->lines
        ));
    }

    #[Computed]
    public function activeTemplates()
    {
        return JournalTemplate::active()->orderBy('name')->get(['id', 'name', 'series', 'lines']);
    }

    /**
     * Apply a template to the line editor.
     * $rawTotal is the total amount entered by the user (SEK, decimal).
     * Formulas referencing "Total" are evaluated and amounts filled in.
     */
    public function applyTemplate(int $templateId, string $rawTotal = ''): void
    {
        $tpl   = JournalTemplate::findOrFail($templateId);
        $total = $rawTotal !== '' ? (float) str_replace(',', '.', $rawTotal) : 0.0;

        $this->formSeries = $tpl->series;

        $this->lines = array_values(array_map(function ($l) use ($total) {
            $amount = '';
            if ($total > 0 && ! empty($l['formula'])) {
                $computed = $this->evalFormula($l['formula'], $total);
                $amount   = $computed > 0 ? number_format($computed, 2, ',', ' ') : '';
            }

            $side = $l['side'] ?? 'debit';
            return [
                'account_id'     => (string) ($l['account_id'] ?? ''),
                'cost_center_id' => (string) ($l['cost_center_id'] ?? ''),
                'debit'          => $side === 'debit'  ? $amount : '',
                'credit'         => $side === 'credit' ? $amount : '',
                'description'    => $l['description'] ?? '',
            ];
        }, $tpl->lines ?? []));

        while (count($this->lines) < 2) {
            $this->lines[] = ['account_id' => '', 'cost_center_id' => '', 'debit' => '', 'credit' => '', 'description' => ''];
        }
    }

    private function evalFormula(string $formula, float $total): float
    {
        // Replace "Total" (case-insensitive) with the numeric value
        $expr = preg_replace('/Total/i', (string) $total, $formula);

        // Only allow digits, whitespace, and arithmetic operators
        if (! preg_match('/^[\d\s\+\-\*\/\(\)\.]+$/', $expr)) {
            return 0.0;
        }

        try {
            // phpcs:ignore Squiz.PHP.Eval.Discouraged
            return (float) eval("return ({$expr});");
        } catch (\Throwable) {
            return 0.0;
        }
    }

    public function addLine(): void
    {
        $this->lines[] = ['account_id' => '', 'cost_center_id' => '', 'debit' => '', 'credit' => '', 'description' => ''];
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) > 2) {
            array_splice($this->lines, $index, 1);
            $this->lines = array_values($this->lines);
        }
    }

    public function save(): void
    {
        $this->validate([
            'fiscal_year'         => 'required|exists:fiscal_years,id',
            'formSeries'          => 'required|string|size:1',
            'formDate'            => 'required|date',
            'formDescription'     => 'required|string|max:500',
            'lines'               => 'required|array|min:2',
            'lines.*.account_id'  => 'required|exists:accounts,id',
            'lines.*.debit'       => 'nullable|numeric|min:0',
            'lines.*.credit'      => 'nullable|numeric|min:0',
        ]);

        $fy = FiscalYear::findOrFail($this->fiscal_year);
        abort_if($fy->is_locked, 422, 'Räkenskapsåret är låst.');

        // Validate that date falls within chosen fiscal year
        if (! $fy->containsDate($this->formDate)) {
            $this->addError('formDate', "Datumet ligger utanför räkenskapsåret {$fy->name}.");
            return;
        }

        $parsedLines = array_map(fn ($row) => [
            'account_id'     => (int) $row['account_id'],
            'cost_center_id' => $row['cost_center_id'] ?: null,
            'debit'          => (int) round((float) str_replace(',', '.', $row['debit']  ?: '0') * 100),
            'credit'         => (int) round((float) str_replace(',', '.', $row['credit'] ?: '0') * 100),
            'description'    => $row['description'] ?: null,
        ], $this->lines);

        $totalDebit  = array_sum(array_column($parsedLines, 'debit'));
        $totalCredit = array_sum(array_column($parsedLines, 'credit'));

        if ($totalDebit !== $totalCredit) {
            $this->addError('lines', 'Debet och kredit måste vara lika. Diff: ' . number_format(abs($totalDebit - $totalCredit) / 100, 2, ',', ' ') . ' kr');
            return;
        }
        if ($totalDebit === 0) {
            $this->addError('lines', 'Verifikationen kan inte vara noll.');
            return;
        }

        $entry = DB::transaction(function () use ($fy, $parsedLines) {
            // Next number in series for this fiscal year
            $lastNumber = JournalEntry::where('fiscal_year_id', $fy->id)
                ->where('series', $this->formSeries)
                ->lockForUpdate()
                ->max('number') ?? 0;

            $entry = JournalEntry::create([
                'fiscal_year_id' => $fy->id,
                'series'         => $this->formSeries,
                'number'         => $lastNumber + 1,
                'date'           => $this->formDate,
                'description'    => $this->formDescription,
                'created_by'     => Auth::id(),
            ]);

            foreach ($parsedLines as $line) {
                $entry->lines()->create($line);
            }

            return $entry;
        });

        AuditLog::record('journal_entry_created', 'accounting', $entry->series . $entry->number . ' — ' . $entry->description, $entry);

        $this->redirect(route('admin.accounting.journal-entries.show', $entry), navigate: true);
    }
};
?>

<div class="space-y-6">

        {{-- Header fields --}}
        <div class="max-w-3xl rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">

                {{-- Fiscal year — shows all open years since two may be active simultaneously --}}
                <div class="sm:col-span-1">
                    <flux:label for="fySelect">Räkenskapsår</flux:label>
                    <flux:select id="fySelect" wire:model.live="fiscal_year">
                        @foreach ($this->openFiscalYears as $fy)
                            <option value="{{ $fy->id }}">
                                {{ $fy->name }}
                                @if ($fy->containsDate(today())) (pågående) @endif
                            </option>
                        @endforeach
                    </flux:select>
                    @if ($this->openFiscalYears->count() > 1)
                        <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                            Flera öppna räkenskapsår — kontrollera att du väljer rätt.
                        </p>
                    @endif
                    @error('fiscal_year') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <flux:label for="formSeries">Serie</flux:label>
                    <flux:select id="formSeries" wire:model="formSeries">
                        @foreach ($this->manualSeries as $s)
                            <option value="{{ $s->code }}">{{ $s->code }} – {{ $s->name }}</option>
                        @endforeach
                    </flux:select>
                </div>

                <div>
                    <flux:label for="formDate">Datum</flux:label>
                    <flux:input id="formDate" type="date" wire:model.live="formDate" />
                    @error('formDate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-4">
                    <flux:label for="formDescription">Beskrivning</flux:label>
                    <flux:input id="formDescription" wire:model="formDescription"
                        placeholder="t.ex. Hyra november – Lokalen" />
                    @error('formDescription') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- Template picker --}}
        @if ($this->activeTemplates->isNotEmpty())
        <div x-data="{ open: false, templateId: '', total: '' }" class="flex items-center gap-3">
            <button type="button" @click="open = !open"
                class="flex items-center gap-1.5 text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 transition-colors">
                <x-bootstrap-icon name="layout-text-sidebar" class="size-4" />
                Välj mall
                <x-bootstrap-icon name="chevron-down" class="size-3" x-bind:class="{ '-rotate-180': open }" />
            </button>

            <div x-show="open" x-cloak x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                 @click.outside="open = false"
                 class="flex items-end gap-2 flex-wrap">

                <div>
                    <flux:label class="text-xs mb-1">Mall</flux:label>
                    <select x-model="templateId"
                        class="text-sm rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-brand focus:ring-brand">
                        <option value="">Välj…</option>
                        @foreach ($this->activeTemplates as $tpl)
                            <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <flux:label class="text-xs mb-1">Totalbelopp (SEK)</flux:label>
                    <input type="text" x-model="total" placeholder="t.ex. 12 500"
                        class="w-32 text-sm rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-brand focus:ring-brand font-mono" />
                </div>

                <button type="button"
                    x-bind:disabled="!templateId"
                    @click="if(templateId) { $wire.applyTemplate(templateId, total); open = false; }"
                    class="btn btn-sm btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                    Tillämpa
                </button>
            </div>
        </div>
        @endif

        {{-- Lines --}}
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-2 w-8">#</th>
                            <th class="px-4 py-2">Konto</th>
                            <th class="px-4 py-2 w-32">Kostnadst.</th>
                            <th class="px-4 py-2">Beskrivning</th>
                            <th class="px-4 py-2 w-32 text-right">Debet (kr)</th>
                            <th class="px-4 py-2 w-32 text-right">Kredit (kr)</th>
                            <th class="px-4 py-2 w-32 text-right text-zinc-400">Saldo nu</th>
                            <th class="px-4 py-2 w-32 text-right text-zinc-400">Saldo efter</th>
                            <th class="px-4 py-2 w-8"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($lines as $i => $line)
                            <tr>
                                <td class="px-4 py-2 text-xs text-zinc-400">{{ $i + 1 }}</td>
                                <td class="px-4 py-2">
                                    <x-account-select
                                        :accounts="$this->accounts"
                                        model="lines.{{ $i }}.account_id"
                                        :live="true"
                                    />
                                    @error("lines.{$i}.account_id") <p class="text-xs text-red-600 mt-0.5">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-4 py-2">
                                    <select wire:model="lines.{{ $i }}.cost_center_id"
                                        class="w-full text-sm rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-brand focus:ring-brand">
                                        <option value="">–</option>
                                        @foreach ($this->costCenters as $cc)
                                            <option value="{{ $cc->id }}">{{ $cc->code }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-4 py-2">
                                    <input type="text" wire:model="lines.{{ $i }}.description"
                                        placeholder="Valfri"
                                        class="w-full text-sm rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-brand focus:ring-brand" />
                                </td>
                                <td class="px-4 py-2">
                                    <input type="text" wire:model.live="lines.{{ $i }}.debit"
                                        placeholder="0,00"
                                        class="w-full text-sm text-right rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-brand focus:ring-brand font-mono" />
                                </td>
                                <td class="px-4 py-2">
                                    <input type="text" wire:model.live="lines.{{ $i }}.credit"
                                        placeholder="0,00"
                                        class="w-full text-sm text-right rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-brand focus:ring-brand font-mono" />
                                </td>
                                @php $bal = $this->lineBalances[$i] ?? null; @endphp
                                <td class="px-4 py-2 text-right font-mono text-xs whitespace-nowrap">
                                    @if ($bal !== null)
                                        @php $v = $bal['before']; @endphp
                                        @if ($v > 0)
                                            <span class="text-zinc-500 dark:text-zinc-400">{{ number_format($v / 100, 2, ',', ' ') }}</span>
                                        @elseif ($v < 0)
                                            <span class="text-zinc-400 dark:text-zinc-500">−{{ number_format(abs($v) / 100, 2, ',', ' ') }}</span>
                                        @else
                                            <span class="text-zinc-300 dark:text-zinc-600">0,00</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-xs whitespace-nowrap">
                                    @if ($bal !== null)
                                        @php $v = $bal['after']; @endphp
                                        @if ($v > 0)
                                            <span class="text-zinc-700 dark:text-zinc-200 font-medium">{{ number_format($v / 100, 2, ',', ' ') }}</span>
                                        @elseif ($v < 0)
                                            <span class="text-zinc-600 dark:text-zinc-300 font-medium">−{{ number_format(abs($v) / 100, 2, ',', ' ') }}</span>
                                        @else
                                            <span class="text-zinc-400 dark:text-zinc-500 font-medium">0,00</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    @if (count($lines) > 2)
                                        <button wire:click="removeLine({{ $i }})"
                                            class="text-zinc-300 hover:text-red-500 dark:text-zinc-600 dark:hover:text-red-400">
                                            <x-bootstrap-icon name="x" class="size-4" />
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t border-zinc-200 dark:border-zinc-700">
                        <tr class="bg-zinc-50 dark:bg-zinc-800">
                            <td colspan="3" class="px-4 py-2">
                                <button wire:click="addLine" class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                    + Lägg till rad
                                </button>
                            </td>
                            <td class="px-4 py-2 text-right text-xs font-medium text-zinc-500">Summa</td>
                            <td class="px-4 py-2 text-right font-mono text-sm font-semibold
                                {{ $this->totalDebit === $this->totalCredit && $this->totalDebit > 0 ? 'text-green-600 dark:text-green-400' : 'text-zinc-700 dark:text-zinc-300' }}">
                                {{ number_format($this->totalDebit / 100, 2, ',', ' ') }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-sm font-semibold
                                {{ $this->totalDebit === $this->totalCredit && $this->totalDebit > 0 ? 'text-green-600 dark:text-green-400' : 'text-zinc-700 dark:text-zinc-300' }}">
                                {{ number_format($this->totalCredit / 100, 2, ',', ' ') }}
                            </td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                        @if ($this->totalDebit !== $this->totalCredit && ($this->totalDebit > 0 || $this->totalCredit > 0))
                            <tr>
                                <td colspan="9" class="px-4 py-2">
                                    <p class="text-xs text-red-600 dark:text-red-400">
                                        Obalans: {{ number_format(abs($this->totalDebit - $this->totalCredit) / 100, 2, ',', ' ') }} kr
                                        ({{ $this->totalDebit > $this->totalCredit ? 'debet överstiger kredit' : 'kredit överstiger debet' }})
                                    </p>
                                </td>
                            </tr>
                        @endif
                        @error('lines') <tr><td colspan="9" class="px-4 py-2"><p class="text-xs text-red-600">{{ $message }}</p></td></tr> @enderror
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- Balance indicator + save --}}
        <div class="max-w-3xl flex items-center justify-between">
            <div class="flex items-center gap-2 text-sm">
                @if ($this->totalDebit > 0 && $this->totalDebit === $this->totalCredit)
                    <x-bootstrap-icon name="check-circle-fill" class="size-5 text-green-600 dark:text-green-400" />
                    <span class="text-green-600 dark:text-green-400 font-medium">Balanserad</span>
                @elseif ($this->totalDebit > 0 || $this->totalCredit > 0)
                    <x-bootstrap-icon name="exclamation-triangle-fill" class="size-5 text-amber-500" />
                    <span class="text-amber-600 dark:text-amber-400">Ej balanserad</span>
                @endif
            </div>
            <div class="flex gap-3">
                <flux:button
                    href="{{ route('admin.accounting.journal-entries.index', ['fiscal_year' => $fiscal_year]) }}"
                    wire:navigate
                    variant="ghost">
                    Avbryt
                </flux:button>
                <flux:button wire:click="save" variant="primary"
                    :disabled="$this->totalDebit === 0 || $this->totalDebit !== $this->totalCredit">
                    Bokför verifikation
                </flux:button>
            </div>
        </div>
</div>
