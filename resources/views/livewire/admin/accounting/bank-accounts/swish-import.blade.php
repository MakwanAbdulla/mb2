<?php

use App\Models\Accounting\BankAccount;
use App\Models\Accounting\SwishImport;
use App\Services\Accounting\SwishReportParser;
use App\Services\Accounting\SwishTransactionMatcher;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Importera Swish-rapport');

new class extends Component {
    use WithFileUploads;

    public $file = null;
    public ?int $bankAccountId = null;

    public string  $fileHash            = '';
    public bool    $fileAlreadyImported = false;
    public bool    $accountAutoDetected = false;
    public string  $detectedAccountNumber = '';

    /** Parsed rows for preview before saving. */
    public array  $preview    = [];
    public int    $previewTotal = 0;
    public int    $skipped      = 0;   // rows already in DB from a previous import
    public array  $invalidRows  = [];  // rows the parser could not read (bad/missing date etc.)
    public ?string $periodFrom = null;
    public ?string $periodTo   = null;

    /** Set after successful import. */
    public bool $imported     = false;
    public int  $importedRows = 0;
    public int  $matchedRows  = 0;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    #[Computed]
    public function bankAccounts()
    {
        return BankAccount::orderBy('name')->get();
    }

    #[Computed]
    public function recentImports()
    {
        return SwishImport::with('bankAccount')
            ->latest()
            ->limit(10)
            ->get();
    }

    public function updatedFile(): void
    {
        $this->reset('preview', 'previewTotal', 'skipped', 'invalidRows', 'periodFrom', 'periodTo',
                     'imported', 'importedRows', 'matchedRows',
                     'fileAlreadyImported', 'accountAutoDetected', 'detectedAccountNumber');

        if (! $this->file) {
            return;
        }

        $content        = file_get_contents($this->file->getRealPath());
        $this->fileHash = SwishReportParser::fileHash($content);

        if (SwishImport::where('file_hash', $this->fileHash)->exists()) {
            $this->fileAlreadyImported = true;
            return;
        }

        $parser = new SwishReportParser();

        // Auto-detect account
        $accountNumber = $parser->parseAccountNumber($content);
        if ($accountNumber) {
            $this->detectedAccountNumber = $accountNumber;
            $digitsOnly = ltrim(preg_replace('/\D/', '', $accountNumber), '0');
            $match = $this->bankAccounts->first(fn ($ba) =>
                ltrim(preg_replace('/\D/', '', (string) $ba->account_number), '0') === $digitsOnly
            );
            if ($match) {
                $this->bankAccountId       = $match->id;
                $this->accountAutoDetected = true;
            }
        }

        [$this->periodFrom, $this->periodTo] = $parser->parsePeriod($content);

        $rows               = $parser->parse($content);
        $this->previewTotal = count($rows);
        $this->invalidRows  = $parser->getSkippedRows();

        // Count rows already in DB (cross-file dedup)
        $accountId     = $this->bankAccountId;
        $this->skipped = collect($rows)->filter(fn ($row) =>
            \App\Models\Accounting\SwishTransaction::where(
                'import_hash',
                \App\Models\Accounting\SwishTransaction::importHash($accountId ?? 0, $row)
            )->exists()
        )->count();

        $this->preview = array_slice($rows, 0, 5);
    }

    public function import(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $this->validate([
            'bankAccountId' => 'required|exists:bank_accounts,id',
            'file'          => 'required',
        ], [
            'bankAccountId.required' => 'Välj ett bankkonto.',
        ]);

        $content  = file_get_contents($this->file->getRealPath());
        $hash     = SwishReportParser::fileHash($content);

        // Re-check in case of race condition or double submit
        if (SwishImport::where('file_hash', $hash)->exists()) {
            $this->fileAlreadyImported = true;
            return;
        }

        $parser   = new SwishReportParser();
        $rows     = $parser->parse($content);
        $filename = $this->file->getClientOriginalName();
        $skippedDuringImport = $parser->getSkippedRows();

        $result = app(SwishTransactionMatcher::class)->import(
            bankAccountId: $this->bankAccountId,
            rows:          $rows,
            fileHash:      $hash,
            filename:      $filename,
            periodFrom:    $this->periodFrom,
            periodTo:      $this->periodTo,
        );

        $this->imported     = true;
        $this->importedRows = $result->row_count;
        $this->matchedRows  = $result->matched_count;
        $this->invalidRows  = $skippedDuringImport;

        $this->reset('file', 'preview', 'previewTotal', 'fileHash',
                     'fileAlreadyImported', 'accountAutoDetected', 'detectedAccountNumber');
        unset($this->recentImports);
    }
};
?>

<div>

    {{-- Success banner --}}
    @if ($imported)
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 dark:border-green-700/40 dark:bg-green-900/10 px-4 py-4">
            <p class="font-semibold text-green-800 dark:text-green-300 flex items-center gap-1.5 mb-1">
                <x-bootstrap-icon name="check-circle-fill" class="size-4" />
                Import klar
            </p>
            <p class="text-sm text-green-700 dark:text-green-400">
                {{ $importedRows }} rader importerade —
                {{ $matchedRows }} matchades mot banktransaktioner,
                {{ $importedRows - $matchedRows }} väntar på bankutdrag.
            </p>
        </div>
    @endif

    {{-- Rows the parser couldn't read (bad/missing date etc.) --}}
    @if (! empty($invalidRows))
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-700/40 dark:bg-amber-900/10 px-4 py-4">
            <p class="font-semibold text-amber-800 dark:text-amber-300 flex items-center gap-1.5 mb-1">
                <x-bootstrap-icon name="exclamation-triangle-fill" class="size-4" />
                {{ count($invalidRows) }} rad{{ count($invalidRows) === 1 ? '' : 'er' }} kunde inte läsas och importerades inte
            </p>
            <ul class="text-sm text-amber-700 dark:text-amber-400 list-disc list-inside">
                @foreach ($invalidRows as $row)
                    <li>
                        @if ($row['row_number'])
                            Rad {{ $row['row_number'] }}
                        @else
                            Okänd rad
                        @endif
                        — {{ $row['reason'] }}
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-xs text-amber-600 dark:text-amber-500">
                Kontrollera raden i den ursprungliga filen och bokför den manuellt om den är en riktig transaktion.
            </p>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Upload form --}}
        <div class="lg:col-span-2 space-y-5">

            {{-- File already imported --}}
            @if ($fileAlreadyImported)
                <div class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-700/40 dark:bg-amber-900/10 px-4 py-3 text-sm text-amber-800 dark:text-amber-300 flex items-center gap-2">
                    <x-bootstrap-icon name="exclamation-circle-fill" class="size-4 shrink-0" />
                    Den här filen har redan importerats.
                </div>
            @endif

            {{-- File picker --}}
            <div>
                <label class="lbl">Swish-rapport (CSV eller TXT)</label>
                <input type="file"
                       wire:model="file"
                       accept=".csv,.txt"
                       class="block w-full text-sm text-zinc-700 dark:text-zinc-300
                              file:mr-3 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-xs file:font-medium
                              file:text-zinc-700 hover:file:bg-zinc-200
                              dark:file:bg-zinc-700 dark:file:text-zinc-300 dark:hover:file:bg-zinc-600" />
                <div wire:loading wire:target="file" class="mt-1 text-xs text-zinc-400">Läser fil…</div>
            </div>

            {{-- Auto-detected account --}}
            @if ($detectedAccountNumber)
                <div class="text-xs text-zinc-500 dark:text-zinc-400 flex items-center gap-1.5">
                    <x-bootstrap-icon name="{{ $accountAutoDetected ? 'check-circle-fill' : 'exclamation-circle' }}"
                                      class="size-3.5 {{ $accountAutoDetected ? 'text-green-500' : 'text-amber-500' }}" />
                    Kontonr i filen: <span class="font-mono">{{ $detectedAccountNumber }}</span>
                    @if (! $accountAutoDetected)
                        — inget registrerat konto hittades, välj manuellt nedan.
                    @endif
                </div>
            @endif

            {{-- Bank account selector --}}
            <div>
                <label class="lbl">Bankkonto</label>
                <flux:select wire:model="bankAccountId" class="w-full max-w-sm">
                    <option value="">– Välj konto –</option>
                    @foreach ($this->bankAccounts as $ba)
                        <option value="{{ $ba->id }}">{{ $ba->name }}</option>
                    @endforeach
                </flux:select>
                @error('bankAccountId')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            {{-- Period + preview --}}
            @if ($previewTotal > 0)
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    <div class="bg-zinc-50 dark:bg-zinc-800 px-4 py-2 flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                        <span>
                            {{ $previewTotal - $skipped }} nya
                            @if ($skipped > 0)
                                · <span class="text-amber-600 dark:text-amber-400">{{ $skipped }} redan importerade</span>
                            @endif
                            @if ($periodFrom && $periodTo)
                                · {{ \Illuminate\Support\Carbon::parse($periodFrom)->format('d/m Y') }}
                                – {{ \Illuminate\Support\Carbon::parse($periodTo)->format('d/m Y') }}
                            @endif
                        </span>
                        <span>Visar {{ min(5, $previewTotal) }} av {{ $previewTotal }}</span>
                    </div>
                    <table class="w-full text-xs">
                        <thead class="text-left text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-700">
                            <tr>
                                <th class="px-3 py-2 w-24">Datum</th>
                                <th class="px-3 py-2">Avsändare</th>
                                <th class="px-3 py-2">Meddelande</th>
                                <th class="px-3 py-2 text-right w-24">Belopp</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($preview as $row)
                                <tr>
                                    <td class="px-3 py-2 font-mono text-zinc-500">{{ $row['transaction_date'] }}</td>
                                    <td class="px-3 py-2">
                                        <div class="font-mono text-zinc-600 dark:text-zinc-300">{{ $row['sender_phone'] }}</div>
                                        @if ($row['sender_name'])
                                            <div class="text-zinc-400">{{ $row['sender_name'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-zinc-500 dark:text-zinc-400 italic">
                                        {{ $row['message'] ?: '–' }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono font-semibold tabular-nums">
                                        {{ number_format($row['amount_ore'] / 100, 2, ',', ' ') }} kr
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center gap-3">
                    <flux:button wire:click="import" variant="primary"
                                 wire:loading.attr="disabled" wire:target="import">
                        <span wire:loading.remove wire:target="import">Importera {{ $previewTotal - $skipped }} rader</span>
                        <span wire:loading wire:target="import">Importerar…</span>
                    </flux:button>
                </div>
            @endif

        </div>

        {{-- Recent imports sidebar --}}
        <div>
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-3">Tidigare importer</h2>
            @forelse ($this->recentImports as $imp)
                <div class="mb-2 rounded-lg border border-zinc-200 dark:border-zinc-700 px-3 py-2 text-xs">
                    <div class="font-medium text-zinc-700 dark:text-zinc-200 truncate">{{ $imp->filename }}</div>
                    <div class="text-zinc-400 mt-0.5">{{ $imp->bankAccount->name }}</div>
                    <div class="text-zinc-400">
                        @if ($imp->period_from && $imp->period_to)
                            {{ $imp->period_from->format('d/m Y') }} – {{ $imp->period_to->format('d/m Y') }}
                            ·
                        @endif
                        {{ $imp->row_count }} rader, {{ $imp->matched_count }} matchade
                    </div>
                </div>
            @empty
                <p class="text-xs text-zinc-400">Inga importer ännu.</p>
            @endforelse
        </div>

    </div>
</div>
