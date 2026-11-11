<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingDocument;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalSeries;
use App\Models\AuditLog;
use App\Support\StoredUpload;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title(fn () => 'Verifikation ' . ($this->entry ? $this->entry->series . $this->entry->number : ''));

/**
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\JournalLine[]        $currentLines
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\JournalLine[]        $supersededLines
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\Account[]            $accounts
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\CostCenter[]         $costCenters
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\AccountingDocument[] $documents
 */
new class extends Component {
    use WithFileUploads;

    public JournalEntry $entry;

    public bool   $editing     = false;
    public bool   $showHistory = false;
    public string $editNote    = '';
    public $newAttachment = null;

    public bool   $reversing    = false;
    public string $reverseNote  = '';

    // Navigator state
    public string $navSeries = '';
    public int    $navNumber = 0;

    // Editable lines (array of form rows)
    public array $editLines = [];

    public function mount(JournalEntry $journalEntry): void
    {
        abort_unless(Auth::user()->can('accounting.view'), 403);
        $this->entry = $journalEntry->load([
            'fiscalYear',
            'createdBy',
            'editedBy',
            'lines.account',
            'lines.costCenter',
            'lines.supersededBy',
            'source',
            'reverses',
            'reversedBy',
        ]);

        $this->navSeries = $journalEntry->series;
        $this->navNumber = $journalEntry->number;
    }

    // ── Navigator ─────────────────────────────────────────────────────────────

    #[Computed]
    public function availableSeries()
    {
        return JournalSeries::orderBy('sort_order')
            ->whereIn('code', JournalEntry::select('series')->distinct()->pluck('series'))
            ->get(['code', 'name']);
    }

    #[Computed]
    public function entriesInSeries()
    {
        return JournalEntry::where('series', $this->navSeries)
            ->orderBy('number')
            ->get(['id', 'number', 'date', 'description']);
    }

    #[Computed]
    public function previousEntry(): ?JournalEntry
    {
        return JournalEntry::where('series', $this->entry->series)
            ->where('number', '<', $this->entry->number)
            ->orderByDesc('number')
            ->first(['id', 'series', 'number']);
    }

    #[Computed]
    public function nextEntry(): ?JournalEntry
    {
        return JournalEntry::where('series', $this->entry->series)
            ->where('number', '>', $this->entry->number)
            ->orderBy('number')
            ->first(['id', 'series', 'number']);
    }

    public function updatedNavSeries(): void
    {
        $first = JournalEntry::where('series', $this->navSeries)
            ->orderBy('number')
            ->first(['id']);

        if ($first) {
            $this->redirect(route('admin.accounting.journal-entries.show', $first), navigate: true);
        }
    }

    public function updatedNavNumber(): void
    {
        $entry = JournalEntry::where('series', $this->navSeries)
            ->where('number', $this->navNumber)
            ->first(['id']);

        if ($entry) {
            $this->redirect(route('admin.accounting.journal-entries.show', $entry), navigate: true);
        }
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
    public function currentLines()
    {
        return $this->entry->lines->filter(fn ($l) => $l->superseded_at === null);
    }

    /** Account balance (debit-normal, öre) as of this entry's date, keyed by journal_line id. */
    #[Computed]
    public function lineBalances(): array
    {
        $accountIds = $this->currentLines->pluck('account_id')->unique()->all();
        if (empty($accountIds)) return [];

        $ob = DB::table('opening_balances')
            ->where('fiscal_year_id', $this->entry->fiscal_year_id)
            ->whereIn('account_id', $accountIds)
            ->pluck('amount', 'account_id');

        $totals = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.fiscal_year_id', $this->entry->fiscal_year_id)
            ->whereNull('journal_lines.superseded_at')
            ->whereIn('journal_lines.account_id', $accountIds)
            ->where('journal_entries.date', '<=', $this->entry->date->toDateString())
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) as d, SUM(journal_lines.credit) as c')
            ->groupBy('journal_lines.account_id')
            ->get()
            ->keyBy('account_id');

        $result = [];
        foreach ($this->currentLines as $line) {
            $id            = $line->account_id;
            $result[$line->id] = (int) ($ob->get($id, 0))
                + (int) ($totals->get($id)?->d ?? 0)
                - (int) ($totals->get($id)?->c ?? 0);
        }

        return $result;
    }

    #[Computed]
    public function supersededLines()
    {
        return $this->entry->lines->filter(fn ($l) => $l->superseded_at !== null)->sortBy('superseded_at');
    }

    public function startEditing(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        abort_if($this->entry->is_locked, 403, 'Verifikationen är låst.');

        // Populate edit form from current lines
        $this->editLines = $this->currentLines->map(fn ($l) => [
            'account_id'     => $l->account_id,
            'cost_center_id' => $l->cost_center_id,
            'debit'          => $l->debit > 0 ? number_format($l->debit / 100, 2, ',', '') : '',
            'credit'         => $l->credit > 0 ? number_format($l->credit / 100, 2, ',', '') : '',
            'description'    => $l->description ?? '',
        ])->values()->toArray();

        $this->editNote = '';
        $this->editing  = true;
    }

    public function addLine(): void
    {
        $this->editLines[] = [
            'account_id'     => '',
            'cost_center_id' => '',
            'debit'          => '',
            'credit'         => '',
            'description'    => '',
        ];
    }

    public function removeLine(int $index): void
    {
        array_splice($this->editLines, $index, 1);
        $this->editLines = array_values($this->editLines);
    }

    public function saveEdit(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        abort_if($this->entry->is_locked, 403);

        // debit/credit are formatted with a comma decimal (see startEditing()),
        // so `numeric` (which only accepts a dot) would reject every non-zero
        // value entered through this form — match the same comma-or-dot format
        // the parsing step below expects.
        $this->validate([
            'editNote'              => 'required|string|min:5|max:500',
            'editLines'             => 'required|array|min:2',
            'editLines.*.account_id' => 'required|exists:accounts,id',
            'editLines.*.debit'     => ['nullable', 'regex:/^\d*(?:[.,]\d{1,2})?$/'],
            'editLines.*.credit'    => ['nullable', 'regex:/^\d*(?:[.,]\d{1,2})?$/'],
        ], [
            'editLines.*.debit.regex'  => 'Ogiltigt belopp.',
            'editLines.*.credit.regex' => 'Ogiltigt belopp.',
        ]);

        // Parse amounts (support both comma and dot, values are in kronor)
        $lines = array_map(function ($row) {
            $debit  = (int) round((float) str_replace(',', '.', $row['debit']  ?: '0') * 100);
            $credit = (int) round((float) str_replace(',', '.', $row['credit'] ?: '0') * 100);
            return [
                'account_id'     => (int) $row['account_id'],
                'cost_center_id' => $row['cost_center_id'] ?: null,
                'debit'          => $debit,
                'credit'         => $credit,
                'description'    => $row['description'] ?: null,
            ];
        }, $this->editLines);

        // Verify balance
        $totalDebit  = array_sum(array_column($lines, 'debit'));
        $totalCredit = array_sum(array_column($lines, 'credit'));
        if ($totalDebit !== $totalCredit) {
            $this->addError('editLines', 'Debet och kredit måste vara lika. Diff: ' . number_format(abs($totalDebit - $totalCredit) / 100, 2, ',', ' ') . ' kr');
            return;
        }
        if ($totalDebit === 0) {
            $this->addError('editLines', 'Verifikationen kan inte vara noll.');
            return;
        }

        $this->entry->updateLines($lines, Auth::id(), $this->editNote);
        AuditLog::record('journal_entry_edited', 'accounting', $this->entry->series . $this->entry->number . ' — ' . $this->editNote, $this->entry);

        // Reload
        $this->entry->refresh()->load([
            'fiscalYear', 'createdBy', 'editedBy',
            'lines.account', 'lines.costCenter', 'lines.supersededBy',
        ]);

        $this->editing = false;
        $this->editNote = '';
        unset($this->currentLines, $this->supersededLines);
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->editLines = [];
        $this->editNote = '';
        $this->resetValidation();
    }

    // ── Reversal (storno) ────────────────────────────────────────────────────

    public function startReversing(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        abort_if($this->entry->reversedBy, 403, 'Verifikationen är redan vänd.');

        $this->reverseNote = '';
        $this->reversing   = true;
    }

    public function confirmReverse(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $this->validate([
            'reverseNote' => 'required|string|min:5|max:500',
        ]);

        try {
            $reversal = $this->entry->reverse(Auth::id(), $this->reverseNote);
        } catch (\RuntimeException $e) {
            $this->addError('reverseNote', $e->getMessage());
            return;
        }

        AuditLog::record(
            'journal_entry_reversed',
            'accounting',
            $this->entry->series . $this->entry->number . ' vänd av ' . $reversal->series . $reversal->number . ' — ' . $this->reverseNote,
            $this->entry
        );

        $this->redirect(route('admin.accounting.journal-entries.show', $reversal), navigate: true);
    }

    public function cancelReverse(): void
    {
        $this->reversing   = false;
        $this->reverseNote = '';
        $this->resetValidation();
    }

    // ── Documents ─────────────────────────────────────────────────────────────

    #[Computed]
    public function documents()
    {
        return $this->entry->documents()->get();
    }

    public function uploadDocument(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $this->validate([
            'newAttachment' => 'required|file|mimes:pdf,png,jpg,jpeg|max:20480',
        ], [
            'newAttachment.required' => 'Välj en fil.',
            'newAttachment.mimes'    => 'Endast PDF, PNG och JPEG accepteras.',
            'newAttachment.max'      => 'Filen får inte vara större än 20 MB.',
        ]);

        $path   = AccountingDocument::buildPath($this->newAttachment->getClientOriginalExtension());
        $upload = StoredUpload::store($this->newAttachment, 'local', dirname($path), basename($path));

        $upload->persist(fn (StoredUpload $upload) => AccountingDocument::create([
            'type'             => AccountingDocument::TYPE_OTHER,
            'journal_entry_id' => $this->entry->id,
            'disk'             => $upload->disk,
            'path'             => $upload->path,
            'original_name'    => $upload->originalName,
            'mime_type'        => $upload->mimeType,
            'size_bytes'       => $upload->sizeBytes,
            'uploaded_by'      => Auth::id(),
            'linked_at'        => now(),
        ]));

        AuditLog::record('document_uploaded', 'accounting', $this->entry->series . $this->entry->number . ' — ' . $upload->originalName, $this->entry);

        $this->newAttachment = null;
        unset($this->documents);
    }

    public function deleteDocument(int $docId): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $doc = AccountingDocument::where('id', $docId)
            ->where('journal_entry_id', $this->entry->id)
            ->firstOrFail();

        AuditLog::record('document_deleted', 'accounting', $this->entry->series . $this->entry->number . ' — ' . $doc->original_name, $this->entry);
        $doc->delete();
        unset($this->documents);
    }

    public function toggleRequiresReceipt(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $newValue = ! $this->entry->requires_receipt;
        $this->entry->update(['requires_receipt' => $newValue]);
        AuditLog::record('requires_receipt_toggled', 'accounting', $this->entry->series . $this->entry->number . ' — kvitto ' . ($newValue ? 'påkrävt' : 'ej påkrävt'), $this->entry);

        $this->entry->refresh();
    }
};
?>

<div>

    {{-- Meta info row --}}
    <div class="flex flex-wrap gap-6 text-sm text-zinc-500 dark:text-zinc-400 mb-6">
        <span>
            <span class="font-medium text-zinc-700 dark:text-zinc-300">Skapad av:</span>
            {{ $entry->createdBy?->name ?? '–' }}
            · {{ $entry->created_at->format('d/m Y H:i') }}
        </span>

        @if ($entry->source_type && $entry->source)
            <span>
                <span class="font-medium text-zinc-700 dark:text-zinc-300">Källa:</span>
                @php $src = $entry->source; @endphp
                @if ($src instanceof \App\Models\Accounting\BankTransaction)
                    <span>
                        Banktransaktion #{{ $src->id }}
                        @if ($src->description) — {{ Str::limit($src->description, 40) }} @endif
                    </span>
                @elseif ($src instanceof \App\Models\Accounting\Invoice)
                    <a href="{{ route($src->isPayable() ? 'admin.accounting.invoices.payable.show' : 'admin.accounting.invoices.receivable.show', $src) }}"
                       wire:navigate class="hover:underline">
                        {{ $src->isPayable() ? 'Leverantörsfaktura' : 'Kundfaktura' }} {{ $src->invoice_number ?? '#'.$src->id }}
                    </a>
                @elseif ($src instanceof \App\Models\Accounting\AccountingInboxEmail)
                    <span>Fakturainkorg: {{ Str::limit($src->subject ?? $src->from_email, 50) }}</span>
                @else
                    <span>{{ class_basename($entry->source_type) }} #{{ $entry->source_id }}</span>
                @endif
            </span>
        @endif

        @if ($entry->requires_receipt)
            <span class="inline-flex items-center gap-1.5 text-amber-600 dark:text-amber-400">
                <x-bootstrap-icon name="receipt" class="size-3.5" />
                Kvitto krävs
                @if ($this->documents->isEmpty())
                    <strong>— saknas</strong>
                @else
                    <span class="text-green-600 dark:text-green-400 font-semibold">— uppladdat</span>
                @endif
            </span>
        @endif
        @can('accounting.manage')
            <button wire:click="toggleRequiresReceipt"
                    class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 underline">
                {{ $entry->requires_receipt ? 'Kvitto ej nödvändigt' : 'Markera: kvitto krävs' }}
            </button>
        @endcan

        @if ($entry->hasBeenEdited())
            <span>
                <span class="font-medium text-amber-600 dark:text-amber-400">Senast ändrad av:</span>
                {{ $entry->editedBy?->name ?? '–' }}
                · {{ $entry->edited_at->format('d/m Y H:i') }}
                · <em>"{{ $entry->edit_note }}"</em>
            </span>
        @endif

        @if ($entry->reverses)
            <span>
                <span class="font-medium text-zinc-700 dark:text-zinc-300">Vänder:</span>
                <a href="{{ route('admin.accounting.journal-entries.show', $entry->reverses) }}"
                   wire:navigate class="hover:underline">
                    {{ $entry->reverses->series }}{{ $entry->reverses->number }}
                </a>
            </span>
        @endif
        @if ($entry->reversedBy)
            <span>
                <span class="font-medium text-red-600 dark:text-red-400">Vänd av:</span>
                <a href="{{ route('admin.accounting.journal-entries.show', $entry->reversedBy) }}"
                   wire:navigate class="hover:underline">
                    {{ $entry->reversedBy->series }}{{ $entry->reversedBy->number }}
                </a>
            </span>
        @endif
        @can('accounting.manage')
            @if (! $entry->is_locked && ! $editing && ! $reversing)
                <button wire:click="startEditing"
                        class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 underline">
                    Redigera rader
                </button>
            @endif
            @if (! $entry->reversedBy && ! $editing && ! $reversing)
                <button wire:click="startReversing"
                        class="text-xs text-zinc-400 hover:text-red-600 dark:hover:text-red-400 underline">
                    Vänd verifikation
                </button>
            @endif
        @endcan
        @if ($this->supersededLines->isNotEmpty() && ! $editing)
            <button wire:click="$toggle('showHistory')" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 underline">
                {{ $showHistory ? 'Dölj ändringshistorik' : 'Visa ändringshistorik' }}
                ({{ $this->supersededLines->count() }} rad{{ $this->supersededLines->count() === 1 ? '' : 'er' }})
            </button>
        @endif
    </div>

    {{-- Reversal confirmation --}}
    @if ($reversing)
        <div class="mb-6 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/30 p-4">
            <h2 class="font-semibold text-red-800 dark:text-red-300 mb-1">Vänd verifikation</h2>
            <p class="text-xs text-red-700 dark:text-red-400 mb-4">
                Skapar en ny verifikation i innevarande öppna räkenskapsår med samtliga rader
                debet/kredit-omvända. {{ $entry->series }}{{ $entry->number }} ändras inte.
            </p>

            <div class="mb-4">
                <flux:label for="reverseNote">Anledning <span class="text-red-500">*</span></flux:label>
                <flux:input id="reverseNote" wire:model="reverseNote"
                    placeholder="Beskriv varför verifikationen vänds…" />
                @error('reverseNote') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end gap-3">
                <flux:button wire:click="cancelReverse" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="confirmReverse" variant="danger">Vänd verifikation</flux:button>
            </div>
        </div>
    @endif

    {{-- Edit form --}}
    @if ($editing)
        <div class="mb-6 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 p-4">
            <h2 class="font-semibold text-amber-800 dark:text-amber-300 mb-1">Redigera verifikationsrader</h2>
            <p class="text-xs text-amber-700 dark:text-amber-400 mb-4">
                De befintliga raderna markeras som ersatta och sparas i ändringshistoriken. Fyll i nya rader nedan.
            </p>

            {{-- Edit lines table --}}
            <div class="overflow-x-auto rounded border border-amber-200 dark:border-amber-800 mb-4">
                <table class="w-full text-sm">
                    <thead class="bg-amber-100 dark:bg-amber-900/40 text-xs font-medium text-amber-700 dark:text-amber-300">
                        <tr>
                            <th class="px-3 py-2 text-left w-10">#</th>
                            <th class="px-3 py-2 text-left">Konto</th>
                            <th class="px-3 py-2 text-left w-36">Kostnadsst.</th>
                            <th class="px-3 py-2 text-left">Beskrivning</th>
                            <th class="px-3 py-2 text-right w-28">Debet (kr)</th>
                            <th class="px-3 py-2 text-right w-28">Kredit (kr)</th>
                            <th class="px-3 py-2 w-8"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-100 dark:divide-amber-900/30 bg-white dark:bg-zinc-900">
                        @foreach ($editLines as $i => $line)
                            <tr>
                                <td class="px-3 py-2 text-xs text-zinc-400">{{ $i + 1 }}</td>
                                <td class="px-3 py-2">
                                    <x-account-select
                                        :accounts="$this->accounts"
                                        model="editLines.{{ $i }}.account_id"
                                    />
                                    @error("editLines.{$i}.account_id") <p class="text-xs text-red-600 mt-0.5">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-3 py-2">
                                    <select wire:model="editLines.{{ $i }}.cost_center_id"
                                        class="w-full text-sm rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-brand focus:ring-brand">
                                        <option value="">–</option>
                                        @foreach ($this->costCenters as $cc)
                                            <option value="{{ $cc->id }}">{{ $cc->code }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-3 py-2">
                                    <input type="text" wire:model="editLines.{{ $i }}.description"
                                        placeholder="Valfri beskrivning"
                                        class="w-full text-sm rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-brand focus:ring-brand" />
                                </td>
                                <td class="px-3 py-2">
                                    <input type="text" wire:model="editLines.{{ $i }}.debit"
                                        placeholder="0,00"
                                        class="w-full text-sm text-right rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-brand focus:ring-brand font-mono" />
                                </td>
                                <td class="px-3 py-2">
                                    <input type="text" wire:model="editLines.{{ $i }}.credit"
                                        placeholder="0,00"
                                        class="w-full text-sm text-right rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-brand focus:ring-brand font-mono" />
                                </td>
                                <td class="px-3 py-2">
                                    @if (count($editLines) > 2)
                                        <button wire:click="removeLine({{ $i }})"
                                            class="text-red-400 hover:text-red-600" title="Ta bort rad">
                                            <x-bootstrap-icon name="x" class="size-4" />
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-amber-50 dark:bg-amber-950/20">
                        <tr>
                            <td colspan="4" class="px-3 py-2">
                                <button wire:click="addLine" class="text-xs text-amber-700 dark:text-amber-400 hover:underline">
                                    + Lägg till rad
                                </button>
                            </td>
                            <td class="px-3 py-2 text-right text-xs font-mono text-zinc-600 dark:text-zinc-400">
                                {{-- Live sum shown via JS would be better, but static label works --}}
                                Debet
                            </td>
                            <td class="px-3 py-2 text-right text-xs font-mono text-zinc-600 dark:text-zinc-400">
                                Kredit
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @error('editLines') <p class="text-sm text-red-600 mb-3">{{ $message }}</p> @enderror

            {{-- Edit note (mandatory) --}}
            <div class="mb-4">
                <flux:label for="editNote">Ändringsorsak <span class="text-red-500">*</span></flux:label>
                <flux:input id="editNote" wire:model="editNote"
                    placeholder="Beskriv varför du ändrar verifikationen…" />
                @error('editNote') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end gap-3">
                <flux:button wire:click="cancelEdit" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="saveEdit" variant="primary">Spara ändringar</flux:button>
            </div>
        </div>
    @endif

    {{-- Current lines --}}
    <h2 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-2">
        {{ $editing ? 'Befintliga rader (ersätts)' : 'Verifikationsrader' }}
    </h2>
    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700 mb-6">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-2 w-28">Konto</th>
                    <th class="px-4 py-2">Kontonamn</th>
                    <th class="px-4 py-2 w-24">Kostst.</th>
                    <th class="px-4 py-2">Beskrivning</th>
                    <th class="px-4 py-2 w-28 text-right">Debet</th>
                    <th class="px-4 py-2 w-28 text-right">Kredit</th>
                    <th class="px-4 py-2 w-32 text-right">Saldo</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($this->currentLines as $line)
                    @php $bal = $this->lineBalances[$line->id] ?? null; @endphp
                    <tr class="{{ $editing ? 'opacity-50 line-through' : '' }}">
                        <td class="px-4 py-2.5 font-mono text-xs">{{ $line->account->code }}</td>
                        <td class="px-4 py-2.5">{{ $line->account->name }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs text-zinc-400">
                            {{ $line->costCenter?->code ?? '–' }}
                        </td>
                        <td class="px-4 py-2.5 text-zinc-500 dark:text-zinc-400">
                            {{ $line->description ?? '' }}
                        </td>
                        <td class="px-4 py-2.5 text-right font-mono">
                            @if ($line->debit > 0)
                                {{ number_format($line->debit / 100, 2, ',', ' ') }}
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-right font-mono">
                            @if ($line->credit > 0)
                                {{ number_format($line->credit / 100, 2, ',', ' ') }}
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-right font-mono text-xs whitespace-nowrap">
                            @if ($bal !== null)
                                @if ($bal > 0)
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ number_format($bal / 100, 2, ',', ' ') }}</span>
                                @elseif ($bal < 0)
                                    <span class="text-zinc-500 dark:text-zinc-400">−{{ number_format(abs($bal) / 100, 2, ',', ' ') }}</span>
                                @else
                                    <span class="text-zinc-300 dark:text-zinc-600">0,00</span>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-zinc-50 dark:bg-zinc-800 font-semibold text-sm">
                <tr>
                    <td colspan="4" class="px-4 py-2.5 text-right text-xs text-zinc-500 uppercase tracking-wider">Summa</td>
                    <td class="px-4 py-2.5 text-right font-mono">
                        {{ number_format($this->currentLines->sum('debit') / 100, 2, ',', ' ') }}
                    </td>
                    <td class="px-4 py-2.5 text-right font-mono">
                        {{ number_format($this->currentLines->sum('credit') / 100, 2, ',', ' ') }}
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- Documents --}}
    <div class="mt-6">
        <h2 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-2">
            Bilagor
        </h2>

        @if ($this->documents->isNotEmpty())
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden mb-4">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($this->documents as $doc)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                        <td class="px-4 py-2.5 w-8">
                            <x-bootstrap-icon
                                name="{{ $doc->isPdf() ? 'file-earmark-pdf-fill' : 'file-earmark-image-fill' }}"
                                class="size-5 {{ $doc->isPdf() ? 'text-red-500' : 'text-blue-500' }}" />
                        </td>
                        <td class="px-4 py-2.5">
                            <button
                                @click="$dispatch('open-document', {
                                    url:         '{{ route('document.inline', $doc) }}',
                                    downloadUrl: '{{ route('document.download', $doc) }}',
                                    name:        '{{ addslashes($doc->original_name) }}',
                                    mime:        '{{ $doc->mime_type }}'
                                })"
                                class="font-medium text-zinc-800 dark:text-zinc-200 hover:underline text-left">
                                {{ $doc->original_name }}
                            </button>
                            <span class="text-xs text-zinc-400 ml-2">{{ $doc->sizeForHumans() }}</span>
                            @if ($doc->typeLabel() !== 'Övrigt')
                                <span class="ml-1 text-xs text-zinc-400 italic">· {{ $doc->typeLabel() }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-xs text-zinc-400">
                            {{ $doc->uploadedBy?->name ?? '–' }}
                            · {{ $doc->created_at->format('d/m Y H:i') }}
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <div class="flex items-center gap-2 justify-end">
                                <a href="{{ route('document.download', $doc) }}"
                                   class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300"
                                   title="Ladda ner">
                                    <x-bootstrap-icon name="download" class="size-4" />
                                </a>
                                @can('accounting.manage')
                                <button wire:click="deleteDocument({{ $doc->id }})"
                                        x-data x-on:click="confirm('Ta bort bilagan?') || $event.preventDefault()"
                                        class="text-zinc-300 hover:text-red-500 dark:hover:text-red-400 transition-colors"
                                        title="Ta bort">
                                    <x-bootstrap-icon name="trash" class="size-4" />
                                </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
            <p class="text-sm text-zinc-400 dark:text-zinc-500 mb-4">Inga bilagor uppladdade.</p>
        @endif

        @can('accounting.manage')
        <div class="flex items-center gap-3">
            <input type="file" wire:model="newAttachment" accept=".pdf,.png,.jpg,.jpeg"
                   class="text-sm text-zinc-500 dark:text-zinc-400
                          file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0
                          file:text-sm file:font-medium file:bg-zinc-100 file:text-zinc-700
                          dark:file:bg-zinc-800 dark:file:text-zinc-300
                          hover:file:bg-zinc-200 dark:hover:file:bg-zinc-700 cursor-pointer" />
            @error('newAttachment') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            @if ($newAttachment)
                <flux:button wire:click="uploadDocument" variant="primary" size="sm">
                    <x-bootstrap-icon name="upload" class="size-3.5" /> Ladda upp
                </flux:button>
            @endif
        </div>
        @endcan
    </div>

    {{-- Change history --}}
    @if ($this->supersededLines->isNotEmpty() && ($showHistory || $editing))
        <h2 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-2">
            Ändringshistorik
        </h2>
        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-2 w-28">Konto</th>
                        <th class="px-4 py-2">Kontonamn</th>
                        <th class="px-4 py-2 w-24">Kostst.</th>
                        <th class="px-4 py-2">Beskrivning</th>
                        <th class="px-4 py-2 w-28 text-right">Debet</th>
                        <th class="px-4 py-2 w-28 text-right">Kredit</th>
                        <th class="px-4 py-2 w-44">Ersatt</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($this->supersededLines as $line)
                        <tr class="opacity-60">
                            <td class="px-4 py-2.5 font-mono text-xs line-through text-zinc-400">{{ $line->account->code }}</td>
                            <td class="px-4 py-2.5 line-through text-zinc-400">{{ $line->account->name }}</td>
                            <td class="px-4 py-2.5 font-mono text-xs text-zinc-300 dark:text-zinc-500">
                                {{ $line->costCenter?->code ?? '–' }}
                            </td>
                            <td class="px-4 py-2.5 line-through text-zinc-400">
                                {{ $line->description ?? '' }}
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono line-through text-zinc-400">
                                @if ($line->debit > 0)
                                    {{ number_format($line->debit / 100, 2, ',', ' ') }}
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono line-through text-zinc-400">
                                @if ($line->credit > 0)
                                    {{ number_format($line->credit / 100, 2, ',', ' ') }}
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-xs text-zinc-400">
                                {{ $line->superseded_at->format('d/m Y H:i') }}
                                @if ($line->supersededBy)
                                    · {{ $line->supersededBy->name }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
