<?php

use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalSeries;
use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Revision');

/**
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\FiscalYear[]  $fiscalYears
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\JournalSeries[] $series
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\JournalEntry[] $entries
 * @property-read array{total:int,reviewed:int,commented:int} $stats
 */
new class extends Component {

    public ?int   $fiscalYearId  = null;
    public string $filterSeries  = '';
    public string $filterReview  = 'all';   // all | unreviewed | reviewed | commented
    public string $dateFrom      = '';
    public string $dateTo        = '';

    // Selected entry IDs for bulk approve
    public array $selected = [];
    public bool  $selectAll = false;

    // Inline review state: entryId → ['note' => string, 'editing' => bool]
    public array $reviewState = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->hasRole('revisor') || Auth::user()->isAdmin(), 403);

        $fy = FiscalYear::orderByDesc('starts_at')->first();
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
            ->when($this->filterReview === 'unreviewed', fn ($q) => $q->whereNull('reviewed_at'))
            ->when($this->filterReview === 'reviewed',   fn ($q) => $q->whereNotNull('reviewed_at'))
            ->when($this->filterReview === 'commented',  fn ($q) => $q->whereNotNull('review_note')->where('review_note', '!=', ''))
            // with() must come before addSelect() to keep Intelephense on the Eloquent Builder type
            ->with(['reviewedBy', 'createdBy', 'attachments'])
            ->addSelect('journal_entries.*')
            ->addSelect(DB::raw(
                '(SELECT SUM(debit)  FROM journal_lines WHERE journal_entry_id = journal_entries.id AND superseded_at IS NULL) AS total_debit'
            ))
            ->addSelect(DB::raw(
                '(SELECT source_type FROM journal_entries je2 WHERE je2.id = journal_entries.id LIMIT 1) AS src_type'
            ))
            ->orderBy('date')
            ->orderBy('series')
            ->orderBy('number')
            ->get();
    }

    #[Computed]
    public function stats(): array
    {
        if (! $this->fiscalYearId) return ['total' => 0, 'reviewed' => 0, 'commented' => 0];

        $base = JournalEntry::where('fiscal_year_id', $this->fiscalYearId)
            ->when($this->filterSeries, fn ($q) => $q->where('series', $this->filterSeries))
            ->when($this->dateFrom, fn ($q) => $q->where('date', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn ($q) => $q->where('date', '<=', $this->dateTo));

        return [
            'total'     => (clone $base)->count(),
            'reviewed'  => (clone $base)->whereNotNull('reviewed_at')->count(),
            'commented' => (clone $base)->whereNotNull('review_note')->where('review_note', '!=', '')->count(),
        ];
    }

    // ── Review actions ─────────────────────────────────────────────────────────

    public function openNote(int $entryId): void
    {
        $entry = JournalEntry::findOrFail($entryId);
        $this->reviewState[$entryId] = [
            'note'    => $entry->review_note ?? '',
            'editing' => true,
        ];
    }

    public function saveNote(int $entryId): void
    {
        abort_unless(Auth::user()->hasRole('revisor') || Auth::user()->isAdmin(), 403);

        $note = trim($this->reviewState[$entryId]['note'] ?? '');

        $noteEntry = JournalEntry::findOrFail($entryId);
        $noteEntry->update(['review_note' => $note ?: null]);

        AuditLog::record('revision_note_saved', 'accounting', $noteEntry->series . $noteEntry->number . ': ' . ($note ?: '(rensad)'), $noteEntry);

        $this->reviewState[$entryId]['editing'] = false;
        unset($this->entries, $this->stats);
    }

    public function approve(int $entryId): void
    {
        abort_unless(Auth::user()->hasRole('revisor') || Auth::user()->isAdmin(), 403);

        $note = trim($this->reviewState[$entryId]['note'] ?? '');

        $approvedEntry = JournalEntry::findOrFail($entryId);
        $approvedEntry->update([
            'reviewed_by' => Auth::id(),
            'reviewed_at' => Carbon::now(),
            'review_note' => $note ?: null,
        ]);

        AuditLog::record('journal_entry_approved', 'accounting', $approvedEntry->series . $approvedEntry->number . ': ' . $approvedEntry->description, $approvedEntry);

        unset($this->reviewState[$entryId]);
        unset($this->entries, $this->stats);
    }

    public function unapprove(int $entryId): void
    {
        abort_unless(Auth::user()->hasRole('revisor') || Auth::user()->isAdmin(), 403);

        $unapprovedEntry = JournalEntry::findOrFail($entryId);
        $unapprovedEntry->update([
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        AuditLog::record('journal_entry_unapproved', 'accounting', $unapprovedEntry->series . $unapprovedEntry->number . ': ' . $unapprovedEntry->description, $unapprovedEntry);

        unset($this->entries, $this->stats);
    }

    public function bulkApprove(): void
    {
        abort_unless(Auth::user()->hasRole('revisor') || Auth::user()->isAdmin(), 403);
        if (empty($this->selected)) return;

        $now    = Carbon::now();
        $userId = Auth::id();

        $bulkCount = JournalEntry::whereIn('id', $this->selected)
            ->whereNull('reviewed_at')          // only un-reviewed ones
            ->count();

        JournalEntry::whereIn('id', $this->selected)
            ->whereNull('reviewed_at')
            ->update([
                'reviewed_by' => $userId,
                'reviewed_at' => $now,
            ]);

        AuditLog::record('journal_entries_bulk_approved', 'accounting', $bulkCount . ' verifikat godkända', null);

        $this->selected  = [];
        $this->selectAll = false;
        unset($this->entries, $this->stats);
    }

    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value
            ? $this->entries->whereNull('reviewed_at')->pluck('id')->map(fn ($id) => (string)$id)->toArray()
            : [];
    }

    // ── Source attachment helpers ─────────────────────────────────────────────

    /**
     * Returns all viewable attachments for a journal entry as
     * [['label' => ..., 'inline' => url, 'download' => url], ...]
     *
     * Covers:
     *   1. Directly uploaded attachments (journal_entry_attachments table)
     *   2. Invoice PDF when the source is an AP/AR invoice
     */
    public function entryAttachments(JournalEntry $entry): array
    {
        $result = [];

        // Documents directly linked to this journal entry
        foreach ($entry->documents as $doc) {
            $result[] = [
                'label'    => $doc->original_name,
                'inline'   => route('document.inline', $doc),
                'download' => route('document.download', $doc),
            ];
        }

        // Documents attached to the source invoice (before posting) that aren't
        // already linked to this entry (avoid duplicates after auto-propagation)
        if ($entry->source_type === Invoice::class && $entry->source_id) {
            $invoiceDocIds = $entry->documents->pluck('id')->all();
            $invoice = Invoice::find($entry->source_id);
            if ($invoice) {
                foreach ($invoice->documents as $doc) {
                    if (! in_array($doc->id, $invoiceDocIds, true)) {
                        $result[] = [
                            'label'    => $doc->original_name . ' (faktura)',
                            'inline'   => route('document.inline', $doc),
                            'download' => route('document.download', $doc),
                        ];
                    }
                }
            }
        }

        return $result;
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
            <label class="lbl">Status</label>
            <flux:select wire:model.live="filterReview" class="w-44">
                <option value="all">Alla</option>
                <option value="unreviewed">Ej granskade</option>
                <option value="reviewed">Granskade</option>
                <option value="commented">Med kommentar</option>
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

    {{-- Stats bar --}}
    @if ($this->fiscalYearId)
        <div class="mb-5 flex items-center gap-6 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-5 py-3 text-sm">
            <div class="flex items-center gap-2">
                <span class="text-zinc-400">Totalt</span>
                <span class="font-semibold">{{ $this->stats['total'] }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-block size-2.5 rounded-full bg-green-500"></span>
                <span class="text-zinc-400">Granskade</span>
                <span class="font-semibold text-green-700 dark:text-green-400">{{ $this->stats['reviewed'] }}</span>
            </div>
            <div class="flex items-center gap-2">
                <x-bootstrap-icon name="chat-left-text-fill" class="size-3.5 text-amber-500" />
                <span class="text-zinc-400">Med kommentar</span>
                <span class="font-semibold">{{ $this->stats['commented'] }}</span>
            </div>
            @php $pct = $this->stats['total'] > 0 ? round($this->stats['reviewed'] / $this->stats['total'] * 100) : 0; @endphp
            <div class="ml-auto flex items-center gap-3">
                <div class="w-32 h-2 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                    <div class="h-full rounded-full bg-green-500 transition-all" style="width: {{ $pct }}%"></div>
                </div>
                <span class="text-xs text-zinc-500">{{ $pct }}% klart</span>
            </div>
        </div>
    @endif

    {{-- Bulk actions --}}
    @if (count($this->selected) > 0)
        <div class="mb-4 flex items-center gap-3 rounded-lg border border-brand bg-brand/10 px-4 py-2.5">
            <span class="text-sm font-medium text-brand-dark dark:text-white">
                {{ count($this->selected) }} valda
            </span>
            <flux:button wire:click="bulkApprove" variant="primary" size="sm">
                <x-bootstrap-icon name="check2-all" class="size-4" /> Markera som granskade
            </flux:button>
            <button wire:click="$set('selected', []); $set('selectAll', false)"
                    class="text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                Avmarkera alla
            </button>
        </div>
    @endif

    {{-- Table --}}
    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 w-10">
                        <input type="checkbox" wire:model.live="selectAll"
                               class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                    </th>
                    <th class="px-4 py-3 w-24">Ver.nr</th>
                    <th class="px-4 py-3 w-28">Datum</th>
                    <th class="px-4 py-3">Beskrivning</th>
                    <th class="px-4 py-3 w-32 text-right">Belopp</th>
                    <th class="px-4 py-3 w-52">Kommentar / Status</th>
                    <th class="px-4 py-3 w-48"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->entries as $entry)
                    <tr @class([
                        'hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors',
                        'bg-green-50/50 dark:bg-green-900/10' => $entry->isReviewed(),
                    ])>
                        {{-- Checkbox (only for unreviewed) --}}
                        <td class="px-4 py-3">
                            @if (! $entry->isReviewed())
                                <input type="checkbox" wire:model.live="selected" value="{{ $entry->id }}"
                                       class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                            @else
                                <x-bootstrap-icon name="check-circle-fill" class="size-4 text-green-500 mx-auto block" />
                            @endif
                        </td>

                        {{-- Ver.nr --}}
                        <td class="px-4 py-3 font-mono font-semibold text-xs">
                            <a href="{{ route('admin.accounting.journal-entries.show', $entry) }}" wire:navigate
                               class="hover:underline text-complement-light">
                                {{ $entry->series }}{{ $entry->number }}
                            </a>
                            @if ($entry->hasBeenEdited())
                                <x-bootstrap-icon name="pencil-fill" class="size-2.5 text-amber-500 inline ml-0.5" title="Redigerad" />
                            @endif
                            @php $attachUrl = $this->attachmentUrl($entry); $attachDl = $this->attachmentDownloadUrl($entry); @endphp
                            @if ($attachUrl)
                                <div class="flex items-center gap-1 mt-0.5">
                                    <a href="{{ $attachUrl }}" target="_blank"
                                       class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 text-xs flex items-center gap-0.5">
                                        <x-bootstrap-icon name="file-earmark-pdf" class="size-3.5" /> Bilaga
                                    </a>
                                    <a href="{{ $attachDl }}" class="text-zinc-300 hover:text-zinc-500 dark:hover:text-zinc-400" title="Ladda ner">
                                        <x-bootstrap-icon name="download" class="size-3" />
                                    </a>
                                </div>
                            @endif
                        </td>

                        {{-- Datum --}}
                        <td class="px-4 py-3 text-xs text-zinc-500">{{ $entry->date->format('d/m Y') }}</td>

                        {{-- Beskrivning --}}
                        <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                            {{ $entry->description }}
                            @if ($entry->isReviewed())
                                <span class="block text-xs text-zinc-400 mt-0.5">
                                    Granskat av {{ $entry->reviewedBy?->name }}
                                    · {{ $entry->reviewed_at->format('d/m Y') }}
                                </span>
                            @endif
                        </td>

                        {{-- Belopp --}}
                        <td class="px-4 py-3 text-right font-mono text-sm">{{ format_ore((int)$entry->total_debit) }}</td>

                        {{-- Kommentar / status --}}
                        <td class="px-4 py-3">
                            @if (isset($this->reviewState[$entry->id]['editing']) && $this->reviewState[$entry->id]['editing'])
                                <textarea wire:model="reviewState.{{ $entry->id }}.note" rows="2"
                                    class="inp w-full text-xs"
                                    placeholder="Skriv kommentar…"></textarea>
                            @elseif ($entry->review_note)
                                <p class="text-xs text-zinc-600 dark:text-zinc-300 italic leading-snug">
                                    <x-bootstrap-icon name="chat-left-text-fill" class="size-3 text-amber-500 inline mr-0.5" />
                                    {{ $entry->review_note }}
                                </p>
                            @else
                                <span class="text-xs text-zinc-300 dark:text-zinc-600">–</span>
                            @endif
                        </td>

                        {{-- Åtgärder --}}
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2 flex-wrap justify-end">

                                @if (isset($this->reviewState[$entry->id]['editing']) && $this->reviewState[$entry->id]['editing'])
                                    {{-- Editing note --}}
                                    <button wire:click="saveNote({{ $entry->id }})"
                                        class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                        Spara not
                                    </button>
                                    <button wire:click="approve({{ $entry->id }})"
                                        class="text-xs font-medium text-green-600 hover:text-green-700 dark:text-green-400">
                                        <x-bootstrap-icon name="check2" class="size-3.5 inline" /> Godkänn
                                    </button>
                                @elseif (! $entry->isReviewed())
                                    {{-- Unreviewed --}}
                                    <button wire:click="openNote({{ $entry->id }})"
                                        class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                        <x-bootstrap-icon name="chat-left-text" class="size-3.5 inline" />
                                        Kommentar
                                    </button>
                                    <button wire:click="approve({{ $entry->id }})"
                                        class="text-xs font-medium text-green-600 hover:text-green-700 dark:text-green-400">
                                        <x-bootstrap-icon name="check2" class="size-3.5 inline" /> Godkänn
                                    </button>
                                @else
                                    {{-- Reviewed --}}
                                    @if (! $entry->review_note)
                                        <button wire:click="openNote({{ $entry->id }})"
                                            class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                            <x-bootstrap-icon name="chat-left-text" class="size-3.5 inline" /> Not
                                        </button>
                                    @else
                                        <button wire:click="openNote({{ $entry->id }})"
                                            class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                            Redigera not
                                        </button>
                                    @endif
                                    <button wire:click="unapprove({{ $entry->id }})"
                                        class="text-xs text-zinc-400 hover:text-red-500 dark:hover:text-red-400">
                                        Ångra
                                    </button>
                                @endif

                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-400">
                            Inga verifikationer matchar filtret.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
