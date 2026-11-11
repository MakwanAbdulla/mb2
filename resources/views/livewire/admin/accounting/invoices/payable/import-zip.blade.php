<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingDocument;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\InvoiceLine;
use App\Models\Accounting\Vendor;
use App\Models\AuditLog;
use App\Services\Accounting\InvoiceZipImporter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Importera leverantörsfakturor (ZIP)');

new class extends Component {
    use WithFileUploads;

    public $zipFile = null;

    public bool   $reviewing = false;
    public string $batchId   = '';
    public array  $candidates = [];
    public array  $skipped    = [];

    /** @var array<int,int> row index => count of invoices created so far in this session, for the result banner */
    public bool $imported     = false;
    public int  $importedCount = 0;
    public array $failedRows  = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    #[Computed]
    public function vendors()
    {
        return Vendor::active()->orderBy('name')->get(['id', 'name', 'code']);
    }

    #[Computed]
    public function accounts()
    {
        return Account::where('is_active', true)
            ->whereIn('type', ['expense', 'asset', 'liability'])
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    public function updatedZipFile(): void
    {
        $this->validate(['zipFile' => 'required|file|mimes:zip|max:46080']);

        $importer = app(InvoiceZipImporter::class);
        $result   = $importer->import($this->zipFile->getRealPath());

        $this->batchId = $result['batch_id'];
        $this->skipped = $result['skipped'];

        $this->candidates = collect($result['candidates'])->map(fn ($c) => [
            ...$c,
            'vendor_id'  => $c['matched_vendor_id'] ? (string) $c['matched_vendor_id'] : '',
            'account_id' => $c['suggested_account_id'] ? (string) $c['suggested_account_id'] : '',
            'amount_kr'  => $c['amount_ore'] !== null ? number_format($c['amount_ore'] / 100, 2, ',', '') : '',
            'date'       => $c['date'] ?: today()->format('Y-m-d'),
            'include'    => $c['error'] === null,
        ])->all();

        $this->zipFile   = null;
        $this->reviewing = true;
    }

    public function cancel(): void
    {
        if ($this->batchId) {
            app(InvoiceZipImporter::class)->discardBatch($this->batchId);
        }
        $this->reset('reviewing', 'batchId', 'candidates', 'skipped', 'imported', 'importedCount', 'failedRows');
    }

    public function confirmImport(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $created = 0;
        $failed  = [];

        foreach ($this->candidates as $i => $row) {
            if (! $row['include']) {
                continue;
            }

            $amountOre = (int) round((float) str_replace(',', '.', $row['amount_kr'] ?: '0') * 100);

            if (! $row['vendor_id'] || ! $row['account_id'] || $amountOre <= 0) {
                $failed[] = $row['original_name'] . ' — saknar leverantör, konto eller belopp';
                continue;
            }

            $invoice = Invoice::create([
                'type'          => Invoice::TYPE_PAYABLE,
                'vendor_id'     => $row['vendor_id'],
                'invoice_date'  => $row['date'] ?: today()->format('Y-m-d'),
                'amount_ex_vat' => $amountOre,
                'vat_amount'    => 0,
                'total'         => $amountOre,
                'currency'      => 'SEK',
                'status'        => Invoice::STATUS_DRAFT,
                'notes'         => 'Importerad från ZIP (' . $row['original_name'] . ')' . ($row['confidence'] ? ", tolkningssäkerhet: {$row['confidence']}" : ''),
                'created_by'    => Auth::id(),
            ]);

            InvoiceLine::create([
                'invoice_id'    => $invoice->id,
                'account_id'    => $row['account_id'],
                'description'   => $row['description'] ?: $row['original_name'],
                'amount_ex_vat' => $amountOre,
                'vat_rate'      => 0,
                'vat_amount'    => 0,
                'sort_order'    => 0,
            ]);

            $this->attachDocument($invoice->id, $row);

            AuditLog::record('invoice_draft_saved', 'accounting', "#{$invoice->id} (ZIP-import)", $invoice);

            $created++;
        }

        app(InvoiceZipImporter::class)->discardBatch($this->batchId);

        $this->reset('reviewing', 'batchId', 'candidates', 'skipped');
        $this->imported      = true;
        $this->importedCount = $created;
        $this->failedRows    = $failed;
    }

    private function attachDocument(int $invoiceId, array $row): void
    {
        if (! Storage::disk('local')->exists($row['scratch_path'])) {
            return;
        }

        $ext  = pathinfo($row['scratch_path'], PATHINFO_EXTENSION);
        $path = AccountingDocument::buildPath($ext);

        Storage::disk('local')->copy($row['scratch_path'], $path);

        AccountingDocument::create([
            'type'          => AccountingDocument::TYPE_INVOICE_ATTACHMENT,
            'invoice_id'    => $invoiceId,
            'disk'          => 'local',
            'path'          => $path,
            'original_name' => $row['original_name'],
            'mime_type'     => $row['mime_type'],
            'size_bytes'    => $row['size_bytes'],
            'uploaded_by'   => Auth::id(),
        ]);
    }
};
?>

<div class="max-w-5xl">

    <div class="mb-6">
        <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Importera leverantörsfakturor från ZIP</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Ladda upp en zip-fil med fakturor (PDF/bild). Varje PDF läses lokalt (ingen extern AI-tjänst) och
            matchas mot leverantörsregistret via organisationsnummer — granska och justera innan du sparar.
            Bildfiler kan inte tolkas automatiskt och bifogas bara.
        </p>
    </div>

    {{-- Result banner --}}
    @if ($imported)
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 dark:border-green-700/40 dark:bg-green-900/10 px-4 py-4">
            <p class="font-semibold text-green-800 dark:text-green-300 flex items-center gap-1.5 mb-1">
                <x-bootstrap-icon name="check-circle-fill" class="size-4" />
                {{ $importedCount }} faktur{{ $importedCount === 1 ? 'a' : 'or' }} sparade som utkast
            </p>
            <p class="text-sm text-green-700 dark:text-green-400">
                Öppna varje faktura för att granska och skicka för attest — dualitetsregeln gäller precis som vanligt.
            </p>
            @if (! empty($failedRows))
                <ul class="mt-2 text-sm text-amber-700 dark:text-amber-400 list-disc list-inside">
                    @foreach ($failedRows as $msg)
                        <li>{{ $msg }} — importerades inte</li>
                    @endforeach
                </ul>
            @endif
            <a href="{{ route('admin.accounting.invoices.payable.index') }}" wire:navigate
               class="mt-3 inline-block text-sm font-medium text-brand hover:underline">Till leverantörsfakturor →</a>
        </div>
    @endif

    {{-- Step 1: upload --}}
    @if (! $reviewing && ! $imported)
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6">
            <label class="lbl">Zip-fil</label>
            <input type="file" wire:model="zipFile" accept=".zip"
                   class="block w-full text-sm text-zinc-700 dark:text-zinc-300
                          file:mr-3 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-xs file:font-medium
                          file:text-zinc-700 hover:file:bg-zinc-200
                          dark:file:bg-zinc-700 dark:file:text-zinc-300 dark:hover:file:bg-zinc-600" />
            @error('zipFile') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            <div wire:loading wire:target="zipFile" class="mt-2 text-xs text-zinc-400 flex items-center gap-1.5">
                <x-bootstrap-icon name="arrow-repeat" class="size-3.5 animate-spin" />
                Packar upp och läser filerna, kan ta en stund…
            </div>
            <p class="mt-3 text-xs text-zinc-400 dark:text-zinc-500">
                Max 45 MB totalt, max 60 filer, max 20 MB per fil. Stödda format: PDF, JPEG, PNG, GIF, WEBP.
            </p>
        </div>
    @endif

    {{-- Step 2: review --}}
    @if ($reviewing)
        @if (! empty($skipped))
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-700/40 dark:bg-amber-900/10 px-4 py-3">
                <p class="text-sm font-medium text-amber-800 dark:text-amber-300 mb-1">
                    {{ count($skipped) }} fil{{ count($skipped) === 1 ? '' : 'er' }} i zip:en hoppades över
                </p>
                <ul class="text-xs text-amber-700 dark:text-amber-400 list-disc list-inside">
                    @foreach ($skipped as $s)
                        <li>{{ $s['original_name'] }} — {{ $s['reason'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (empty($candidates))
            <div class="rounded-xl border border-dashed border-zinc-200 dark:border-zinc-700 py-16 text-center">
                <p class="text-sm text-zinc-400">Inga giltiga fakturafiler hittades i zip-filen.</p>
                <flux:button wire:click="cancel" variant="ghost" class="mt-3">Tillbaka</flux:button>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($candidates as $i => $row)
                    <div class="rounded-xl border {{ $row['include'] ? 'border-zinc-200 dark:border-zinc-700' : 'border-zinc-100 dark:border-zinc-800 opacity-50' }} bg-white dark:bg-zinc-900 p-4">
                        <div class="flex items-start gap-3">
                            <input type="checkbox" wire:model.live="candidates.{{ $i }}.include" class="mt-2.5 rounded" />
                            <div class="flex-1 min-w-0 grid grid-cols-1 sm:grid-cols-5 gap-3">
                                <div class="sm:col-span-5 flex items-center justify-between text-xs text-zinc-400">
                                    <span class="truncate font-mono">{{ $row['original_name'] }}</span>
                                    @if ($row['error'])
                                        <span class="text-red-500 flex items-center gap-1">
                                            <x-bootstrap-icon name="exclamation-triangle-fill" class="size-3" /> {{ $row['error'] }}
                                        </span>
                                    @elseif ($row['confidence'])
                                        <span class="{{ $row['confidence'] === 'high' ? 'text-green-600 dark:text-green-400' : ($row['confidence'] === 'low' ? 'text-amber-500' : 'text-zinc-400') }}">
                                            Tolkningssäkerhet: {{ $row['confidence'] }}
                                        </span>
                                    @endif
                                </div>
                                <div>
                                    <label class="lbl text-xs">Leverantör</label>
                                    <flux:select wire:model="candidates.{{ $i }}.vendor_id" size="sm">
                                        <option value="">
                                            {{ $row['vendor_name'] ? "– Välj ({$row['vendor_name']} hittades ej) –" : '– Välj –' }}
                                        </option>
                                        @foreach ($this->vendors as $v)
                                            <option value="{{ $v->id }}">{{ $v->code }} – {{ $v->name }}</option>
                                        @endforeach
                                    </flux:select>
                                </div>
                                <div>
                                    <label class="lbl text-xs">Datum</label>
                                    <flux:input type="date" wire:model="candidates.{{ $i }}.date" size="sm" />
                                </div>
                                <div>
                                    <label class="lbl text-xs">Belopp (kr)</label>
                                    <flux:input wire:model="candidates.{{ $i }}.amount_kr" size="sm" class="font-mono text-right" />
                                </div>
                                <div>
                                    <label class="lbl text-xs">Konto</label>
                                    <x-account-select :accounts="$this->accounts" model="candidates.{{ $i }}.account_id" />
                                </div>
                                <div>
                                    <label class="lbl text-xs">Beskrivning</label>
                                    <flux:input wire:model="candidates.{{ $i }}.description" size="sm" />
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-5 flex items-center gap-3">
                <flux:button wire:click="confirmImport" variant="primary"
                             wire:loading.attr="disabled" wire:target="confirmImport">
                    <span wire:loading.remove wire:target="confirmImport">
                        Spara {{ collect($candidates)->where('include', true)->count() }} fakturor som utkast
                    </span>
                    <span wire:loading wire:target="confirmImport">Sparar…</span>
                </flux:button>
                <flux:button wire:click="cancel" variant="ghost">Avbryt</flux:button>
            </div>
        @endif
    @endif

</div>
