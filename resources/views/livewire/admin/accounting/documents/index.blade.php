<?php

use App\Models\Accounting\AccountingDocument;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\AuditLog;
use App\Support\StoredUpload;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Dokument');

/**
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\FiscalYear[]        $fiscalYears
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\AccountingDocument[] $documents
 */
/**
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\FiscalYear[]        $fiscalYears
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\AccountingDocument[] $documents
 */
new class extends Component {
    use WithFileUploads;

    // ── Filters ───────────────────────────────────────────────────────────────
    public string  $filterType      = '';      // '' | invoice_attachment | receipt | contract | other
    public string  $filterLinked    = 'all';   // all | unlinked | linked
    public ?int    $filterFiscalYear = null;

    // ── Upload form ───────────────────────────────────────────────────────────
    public bool   $showUpload  = false;
    public string $uploadType  = AccountingDocument::TYPE_RECEIPT;
    public string $uploadDesc  = '';
    public ?int   $uploadFiscalYearId = null;
    public $uploadFile = null;

    // ── Link to JE ────────────────────────────────────────────────────────────
    public ?int   $linkingDocId = null;
    public string $linkJeQuery  = '';
    public array  $linkJeResults = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $fy = FiscalYear::orderByDesc('starts_at')->first();
        $this->filterFiscalYear   = $fy?->id;
        $this->uploadFiscalYearId = $fy?->id;
    }

    #[Computed]
    public function fiscalYears()
    {
        return FiscalYear::orderByDesc('starts_at')->get(['id', 'name']);
    }

    #[Computed]
    public function documents()
    {
        return AccountingDocument::query()
            ->with(['uploadedBy', 'invoice.vendor', 'journalEntry'])
            ->when($this->filterFiscalYear, fn ($q) => $q->where('fiscal_year_id', $this->filterFiscalYear))
            ->when($this->filterType,       fn ($q) => $q->where('type', $this->filterType))
            ->when($this->filterLinked === 'unlinked', fn ($q) => $q->whereNull('journal_entry_id'))
            ->when($this->filterLinked === 'linked',   fn ($q) => $q->whereNotNull('journal_entry_id'))
            ->latest()
            ->get();
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    public function saveDocument(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $this->validate([
            'uploadFile'          => 'required|file|mimes:pdf,png,jpg,jpeg|max:20480',
            'uploadType'          => 'required|in:invoice_attachment,receipt,contract,other',
            'uploadFiscalYearId'  => 'nullable|exists:fiscal_years,id',
        ], [
            'uploadFile.required' => 'Välj en fil.',
            'uploadFile.mimes'    => 'Endast PDF, PNG och JPEG accepteras.',
            'uploadFile.max'      => 'Filen får inte vara större än 20 MB.',
        ]);

        $ext  = $this->uploadFile->getClientOriginalExtension();
        $year = $this->uploadFiscalYearId
            ? (FiscalYear::find($this->uploadFiscalYearId)?->starts_at->year ?? now()->year)
            : now()->year;
        $path   = AccountingDocument::buildPath($ext, $year);
        $upload = StoredUpload::store($this->uploadFile, 'local', dirname($path), basename($path));

        $uploadedDoc = $upload->persist(fn (StoredUpload $upload) => AccountingDocument::create([
            'type'            => $this->uploadType,
            'fiscal_year_id'  => $this->uploadFiscalYearId,
            'disk'            => $upload->disk,
            'path'            => $upload->path,
            'original_name'   => $upload->originalName,
            'mime_type'       => $upload->mimeType,
            'size_bytes'      => $upload->sizeBytes,
            'description'     => $this->uploadDesc ?: null,
            'uploaded_by'     => Auth::id(),
        ]));

        AuditLog::record('document_uploaded', 'accounting', $uploadedDoc->original_name . ' (' . $uploadedDoc->typeLabel() . ')', $uploadedDoc);

        $this->uploadFile    = null;
        $this->uploadDesc    = '';
        $this->showUpload    = false;
        unset($this->documents);
    }

    // ── Link to journal entry ─────────────────────────────────────────────────

    public function startLink(int $docId): void
    {
        $this->linkingDocId  = $docId;
        $this->linkJeQuery   = '';
        $this->linkJeResults = [];
    }

    public function cancelLink(): void
    {
        $this->linkingDocId  = null;
        $this->linkJeQuery   = '';
        $this->linkJeResults = [];
    }

    public function searchJe(): void
    {
        $q = trim($this->linkJeQuery);
        if (strlen($q) < 1) {
            $this->linkJeResults = [];
            return;
        }

        // Search by series+number (e.g. "D42") or description
        $this->linkJeResults = JournalEntry::where(function ($query) use ($q) {
                $query->whereRaw("CONCAT(series, number) LIKE ?", ["%{$q}%"])
                      ->orWhere('description', 'LIKE', "%{$q}%");
            })
            ->orderByDesc('date')
            ->limit(10)
            ->get(['id', 'series', 'number', 'date', 'description'])
            ->map(fn ($e) => [
                'id'    => $e->id,
                'label' => $e->series . $e->number . ' — ' . $e->date->format('d/m Y') . ' — ' . $e->description,
            ])
            ->toArray();
    }

    public function linkToJe(int $jeId): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        abort_if($this->linkingDocId === null, 400);

        $doc = AccountingDocument::findOrFail($this->linkingDocId);
        $je  = JournalEntry::findOrFail($jeId);

        $doc->update([
            'journal_entry_id' => $je->id,
            'linked_at'        => now(),
        ]);

        AuditLog::record('document_linked', 'accounting', $doc->original_name . ' → ' . $je->series . $je->number, $doc);

        $this->cancelLink();
        unset($this->documents);
    }

    public function unlink(int $docId): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $unlinkedDoc = AccountingDocument::findOrFail($docId);
        $unlinkedDoc->update([
            'journal_entry_id' => null,
            'linked_at'        => null,
        ]);

        AuditLog::record('document_unlinked', 'accounting', $unlinkedDoc->original_name, $unlinkedDoc);

        unset($this->documents);
    }

    public function delete(int $docId): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        $deletedDoc = AccountingDocument::findOrFail($docId);
        $deletedName = $deletedDoc->original_name;
        $deletedDoc->delete();
        AuditLog::record('document_deleted', 'accounting', $deletedName, null);
        unset($this->documents);
    }
};
?>

<div>

    <div class="mb-4 flex justify-end">
        <flux:button wire:click="$toggle('showUpload')" variant="primary" size="sm">
                <x-bootstrap-icon name="upload" class="size-4" /> Ladda upp dokument
            </flux:button>
    </div>

    {{-- Upload panel --}}
    @if ($showUpload)
    <div class="mb-6 rounded-xl border border-brand/40 bg-brand/5 dark:bg-brand/10 p-5">
        <h2 class="text-sm font-semibold mb-4">Nytt dokument</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <flux:label>Typ</flux:label>
                <flux:select wire:model="uploadType">
                    <option value="receipt">Kvitto</option>
                    <option value="invoice_attachment">Fakturakopia</option>
                    <option value="contract">Avtal</option>
                    <option value="other">Övrigt</option>
                </flux:select>
            </div>
            <div>
                <flux:label>Räkenskapsår</flux:label>
                <flux:select wire:model="uploadFiscalYearId">
                    <option value="">Inget</option>
                    @foreach ($this->fiscalYears as $fy)
                        <option value="{{ $fy->id }}">{{ $fy->name }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div class="sm:col-span-2">
                <flux:label>Beskrivning (valfri)</flux:label>
                <flux:input wire:model="uploadDesc" placeholder="t.ex. ICA kvitto 2026-04-10" />
            </div>
            <div class="sm:col-span-2">
                <flux:label>Fil <span class="text-red-500">*</span></flux:label>
                <input type="file" wire:model="uploadFile" accept=".pdf,.png,.jpg,.jpeg"
                       class="block w-full text-sm text-zinc-500 dark:text-zinc-400
                              file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0
                              file:text-sm file:font-medium file:bg-zinc-100 file:text-zinc-700
                              dark:file:bg-zinc-800 dark:file:text-zinc-300
                              hover:file:bg-zinc-200 dark:hover:file:bg-zinc-700 cursor-pointer" />
                @error('uploadFile') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2 flex items-end gap-3">
                @if ($uploadFile)
                    <flux:button wire:click="saveDocument" variant="primary" size="sm">
                        <x-bootstrap-icon name="cloud-upload" class="size-4" /> Spara
                    </flux:button>
                @endif
                <flux:button wire:click="$set('showUpload', false)" variant="ghost" size="sm">Avbryt</flux:button>
            </div>
        </div>
    </div>
    @endif

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3 mb-5 text-sm">
        <flux:select wire:model.live="filterFiscalYear" class="w-40">
            <option value="">Alla år</option>
            @foreach ($this->fiscalYears as $fy)
                <option value="{{ $fy->id }}">{{ $fy->name }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="filterType" class="w-44">
            <option value="">Alla typer</option>
            <option value="receipt">Kvitto</option>
            <option value="invoice_attachment">Fakturakopia</option>
            <option value="contract">Avtal</option>
            <option value="other">Övrigt</option>
        </flux:select>

        <flux:select wire:model.live="filterLinked" class="w-44">
            <option value="all">Alla</option>
            <option value="unlinked">Ej kopplade</option>
            <option value="linked">Kopplade</option>
        </flux:select>
    </div>

    {{-- Document list --}}
    @if ($this->documents->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-700 py-14 text-center text-zinc-400 dark:text-zinc-500">
            <x-bootstrap-icon name="file-earmark-x" class="size-10 mx-auto mb-3 opacity-40" />
            <p class="text-sm">Inga dokument matchar filtret.</p>
        </div>
    @else
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 w-8"></th>
                    <th class="px-4 py-3">Dokument</th>
                    <th class="px-4 py-3 w-32">Typ</th>
                    <th class="px-4 py-3 w-36">Uppladdad</th>
                    <th class="px-4 py-3">Kopplad till</th>
                    <th class="px-4 py-3 w-40"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($this->documents as $doc)
                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40 transition-colors">

                    {{-- Icon --}}
                    <td class="px-4 py-3">
                        <x-bootstrap-icon
                            name="{{ $doc->isPdf() ? 'file-earmark-pdf-fill' : 'file-earmark-image-fill' }}"
                            class="size-5 {{ $doc->isPdf() ? 'text-red-400' : 'text-blue-400' }}" />
                    </td>

                    {{-- Name + description --}}
                    <td class="px-4 py-3">
                        <button
                            @click="$dispatch('open-document', {
                                url:         '{{ route('document.inline', $doc) }}',
                                downloadUrl: '{{ route('document.download', $doc) }}',
                                name:        '{{ addslashes($doc->original_name) }}',
                                mime:        '{{ $doc->mime_type }}'
                            })"
                            class="font-medium text-zinc-800 dark:text-zinc-100 hover:underline text-left block">
                            {{ $doc->original_name }}
                        </button>
                        @if ($doc->description)
                            <span class="text-xs text-zinc-400 italic">{{ $doc->description }}</span>
                        @endif
                        <span class="text-xs text-zinc-400">{{ $doc->sizeForHumans() }}</span>
                    </td>

                    {{-- Type badge --}}
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                            {{ $doc->typeLabel() }}
                        </span>
                    </td>

                    {{-- Uploaded by + date --}}
                    <td class="px-4 py-3 text-xs text-zinc-400">
                        {{ $doc->uploadedBy?->name ?? '–' }}<br>
                        {{ $doc->created_at->format('d/m Y') }}
                    </td>

                    {{-- Link status --}}
                    <td class="px-4 py-3 text-xs">
                        @if ($doc->journalEntry)
                            <a href="{{ route('admin.accounting.journal-entries.show', $doc->journalEntry) }}"
                               wire:navigate class="text-complement-light hover:underline font-mono">
                                {{ $doc->journalEntry->series }}{{ $doc->journalEntry->number }}
                            </a>
                            <span class="text-zinc-400 block">{{ $doc->linked_at?->format('d/m Y') }}</span>
                        @elseif ($doc->invoice)
                            <span class="text-amber-600 dark:text-amber-400">
                                <x-bootstrap-icon name="receipt" class="size-3 inline" />
                                Faktura #{{ $doc->invoice->invoice_number ?? $doc->invoice->id }}
                                (ej bokförd)
                            </span>
                        @else
                            <span class="text-zinc-300 dark:text-zinc-600">Ej kopplat</span>
                        @endif

                        {{-- JE search for linking --}}
                        @if ($linkingDocId === $doc->id)
                            <div class="mt-2 space-y-1" wire:key="link-{{ $doc->id }}">
                                <flux:input wire:model.live.debounce.300ms="linkJeQuery"
                                    placeholder="Sök verifikat…" size="sm" autofocus />
                                @if (! empty($linkJeResults))
                                    <div class="rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm divide-y divide-zinc-100 dark:divide-zinc-800 max-h-40 overflow-y-auto">
                                        @foreach ($linkJeResults as $je)
                                            <button wire:click="linkToJe({{ $je['id'] }})"
                                                    class="w-full text-left px-3 py-2 text-xs hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                                                {{ $je['label'] }}
                                            </button>
                                        @endforeach
                                    </div>
                                @elseif (strlen($linkJeQuery) > 0)
                                    <p class="text-xs text-zinc-400">Inga träffar.</p>
                                @endif
                                <button wire:click="cancelLink"
                                        class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                    Avbryt
                                </button>
                            </div>
                        @endif
                    </td>

                    {{-- Actions --}}
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2 justify-end">
                            <a href="{{ route('document.download', $doc) }}"
                               class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300" title="Ladda ner">
                                <x-bootstrap-icon name="download" class="size-4" />
                            </a>

                            @if ($doc->isUnlinked() && $linkingDocId !== $doc->id)
                                <button wire:click="startLink({{ $doc->id }})"
                                        class="text-zinc-400 hover:text-complement-light transition-colors" title="Koppla till verifikat">
                                    <x-bootstrap-icon name="link-45deg" class="size-4" />
                                </button>
                            @elseif ($doc->isLinked())
                                <button wire:click="unlink({{ $doc->id }})"
                                        x-data x-on:click="confirm('Avkoppla dokumentet från verifikatet?') || $event.preventDefault()"
                                        class="text-zinc-400 hover:text-amber-500 transition-colors" title="Avkoppla">
                                    <x-bootstrap-icon name="link" class="size-4" />
                                </button>
                            @endif

                            <button wire:click="delete({{ $doc->id }})"
                                    x-data x-on:click="confirm('Ta bort dokumentet permanent?') || $event.preventDefault()"
                                    class="text-zinc-300 hover:text-red-500 dark:hover:text-red-400 transition-colors" title="Ta bort">
                                <x-bootstrap-icon name="trash" class="size-4" />
                            </button>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
