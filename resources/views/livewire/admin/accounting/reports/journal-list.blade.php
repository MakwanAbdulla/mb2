<?php

use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalSeries;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Verifikationslista');

new class extends Component {

    public ?int  $fiscalYearId = null;
    public string $filterSeries = '';
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

    #[Computed]
    public function allSeries()
    {
        return JournalSeries::orderBy('sort_order')->get(['code', 'name']);
    }

    #[Computed]
    public function entries()
    {
        if (! $this->fiscalYearId) return collect();

        return JournalEntry::where('fiscal_year_id', $this->fiscalYearId)
            ->when($this->filterSeries, fn ($q) => $q->where('series', $this->filterSeries))
            ->when($this->dateFrom, fn ($q) => $q->where('date', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn ($q) => $q->where('date', '<=', $this->dateTo))
            ->addSelect('journal_entries.*')
            ->addSelect(DB::raw(
                '(SELECT SUM(debit)  FROM journal_lines WHERE journal_entry_id = journal_entries.id AND superseded_at IS NULL) AS total_debit'
            ))
            ->addSelect(DB::raw(
                '(SELECT SUM(credit) FROM journal_lines WHERE journal_entry_id = journal_entries.id AND superseded_at IS NULL) AS total_credit'
            ))
            ->addSelect(DB::raw(
                '(SELECT COUNT(*) FROM accounting_documents WHERE journal_entry_id = journal_entries.id) AS documents_count'
            ))
            ->with('createdBy')
            ->orderBy('date')
            ->orderBy('series')
            ->orderBy('number')
            ->get();
    }

    #[Computed]
    public function grandTotals(): array
    {
        return [
            'debit'  => $this->entries->sum('total_debit'),
            'credit' => $this->entries->sum('total_credit'),
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
            <label class="lbl">Serie</label>
            <flux:select wire:model.live="filterSeries" class="w-40">
                <option value="">Alla serier</option>
                @foreach ($this->allSeries as $s)
                    <option value="{{ $s->code }}">{{ $s->code }} – {{ $s->name }}</option>
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

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 w-24">Ver.nr</th>
                    <th class="px-4 py-3 w-28">Datum</th>
                    <th class="px-4 py-3">Beskrivning</th>
                    <th class="px-4 py-3 w-32 text-right">Debet</th>
                    <th class="px-4 py-3 w-32 text-right">Kredit</th>
                    <th class="px-4 py-3 w-12 text-center" title="Kvittostatus">
                        <x-bootstrap-icon name="receipt" class="size-3.5 mx-auto" />
                    </th>
                    <th class="px-4 py-3 w-16"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->entries as $entry)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-4 py-2.5 font-mono font-semibold text-xs">
                            {{ $entry->series }}{{ $entry->number }}
                            @if ($entry->hasBeenEdited())
                                <x-bootstrap-icon name="pencil-fill" class="size-2.5 text-amber-500 inline ml-0.5" title="Redigerad" />
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-zinc-500 text-xs">{{ $entry->date->format('d/m Y') }}</td>
                        <td class="px-4 py-2.5 text-zinc-700 dark:text-zinc-300">{{ $entry->description }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-xs">{{ format_ore((int)$entry->total_debit) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-xs">{{ format_ore((int)$entry->total_credit) }}</td>
                        <td class="px-4 py-2.5 text-center">
                            @if ($entry->requires_receipt)
                                @if ((int)$entry->documents_count === 0)
                                    <x-bootstrap-icon name="exclamation-triangle-fill"
                                        class="size-3.5 text-amber-500 mx-auto"
                                        title="Kvitto saknas" />
                                @else
                                    <x-bootstrap-icon name="check-circle-fill"
                                        class="size-3.5 text-green-500 mx-auto"
                                        title="Kvitto uppladdat" />
                                @endif
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <a href="{{ route('admin.accounting.journal-entries.show', $entry) }}" wire:navigate
                               class="text-xs text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300">
                                Visa
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-400">
                            Inga verifikationer för valt filter.
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if ($this->entries->isNotEmpty())
                <tfoot class="border-t-2 border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 font-semibold text-sm">
                    <tr>
                        <td colspan="3" class="px-4 py-3 text-zinc-500 dark:text-zinc-400">
                            Summa ({{ $this->entries->count() }} verifikationer)
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ format_ore($this->grandTotals['debit']) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ format_ore($this->grandTotals['credit']) }}</td>
                        <td></td>
                        <td></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
