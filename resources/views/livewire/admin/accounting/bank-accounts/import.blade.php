<?php

use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankStatementImport;
use App\Models\Accounting\BankTransaction;
use App\Services\Accounting\BankStatementParser;
use App\Services\Accounting\CardReceiptMatcher;
use App\Services\Accounting\InvoiceMatcherService;
use App\Services\Accounting\PostingRuleService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Importera kontoutdrag');

new class extends Component {
    use WithFileUploads;

    public ?int $bankAccountId = null;
    public $file = null;

    /** Parsed rows ready for preview, before saving. */
    public array $preview = [];
    public int   $skipped   = 0;   // row-level duplicates detected
    public bool  $imported  = false;

    /** Set when the uploaded file has been imported before (file-level duplicate). */
    public bool   $fileAlreadyImported = false;
    public string $fileHash = '';

    /** Set when the account number in the file doesn't match any registered bank account. */
    public bool   $accountAutoDetected  = false;
    public string $detectedAccountNumber = '';

    // Invoice matching result (set after import)
    public int   $invoiceMatched   = 0;
    public array $invoicePotential = [];  // [{tx_id, tx_date, tx_desc, tx_amount, invoices: [{id, number, counterpart}]}]

    // Card matching result (set after import)
    public int   $cardTagged    = 0;
    public int   $cardMatched   = 0;
    public int   $cardMissing   = 0;
    public array $cardUnknownRefs = [];  // K-refs seen but not registered

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    #[Computed]
    public function bankAccounts()
    {
        return BankAccount::orderBy('name')->get();
    }

    public function updatedFile(): void
    {
        $this->preview              = [];
        $this->skipped              = 0;
        $this->imported             = false;
        $this->fileAlreadyImported  = false;
        $this->fileHash             = '';
        $this->accountAutoDetected  = false;
        $this->detectedAccountNumber = '';

        if (! $this->file) {
            return;
        }

        $content        = file_get_contents($this->file->getRealPath());
        $this->fileHash = BankStatementParser::fileHash($content);

        // Check file-level duplicate before doing anything else
        if (BankStatementImport::where('file_hash', $this->fileHash)->exists()) {
            $this->fileAlreadyImported = true;
            return;
        }

        // Auto-detect bank account from the file
        $parser         = new BankStatementParser();
        $accountNumber  = $parser->parseAccountNumber($content);

        if ($accountNumber) {
            $this->detectedAccountNumber = $accountNumber;

            // Normalise to digits only, stripping leading zeros, for comparison
            $digitsOnly = ltrim(preg_replace('/\D/', '', $accountNumber), '0');

            $match = $this->bankAccounts->first(function (BankAccount $ba) use ($digitsOnly) {
                return ltrim(preg_replace('/\D/', '', (string) $ba->account_number), '0') === $digitsOnly;
            });

            if ($match) {
                $this->bankAccountId       = $match->id;
                $this->accountAutoDetected = true;
            }
        }

        $this->parse($content);
    }

    public function updatedBankAccountId(): void
    {
        if ($this->file && ! $this->fileAlreadyImported) {
            $content = file_get_contents($this->file->getRealPath());
            $this->parse($content);
        }
    }

    private function parse(string $content): void
    {
        if (! $this->bankAccountId) {
            $this->preview = [];
            $this->skipped = 0;
            return;
        }

        $parser = new BankStatementParser();
        $rows   = $parser->parse($content);

        $this->preview = [];
        $this->skipped = 0;

        foreach ($rows as $row) {
            $hash      = BankStatementParser::importHash((int) $this->bankAccountId, $row);
            $duplicate = BankTransaction::where('import_hash', $hash)->exists();

            $this->preview[] = array_merge($row, [
                'hash'      => $hash,
                'duplicate' => $duplicate,
            ]);

            if ($duplicate) {
                $this->skipped++;
            }
        }
    }

    public function import(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        $this->validate(['bankAccountId' => 'required|exists:bank_accounts,id']);

        // Block if this file was already imported (race condition guard)
        if (BankStatementImport::where('file_hash', $this->fileHash)->exists()) {
            $this->fileAlreadyImported = true;
            $this->preview = [];
            return;
        }

        $imported        = 0;
        $newTransactions = collect();

        foreach ($this->preview as $row) {
            if ($row['duplicate']) {
                continue;
            }

            // Re-check in DB — preview flags can be stale (Livewire replay, double-click, etc.)
            if (BankTransaction::where('import_hash', $row['hash'])->exists()) {
                continue;
            }

            $tx = BankTransaction::create([
                'bank_account_id'  => $this->bankAccountId,
                'booking_date'     => $row['booking_date'] ?: null,
                'transaction_date' => $row['transaction_date'] ?: null,
                'value_date'       => $row['value_date'] ?: null,
                'amount'           => $row['amount_ore'],
                'balance_ore'      => $row['balance_ore'],
                'import_hash'      => $row['hash'],
                'description'      => $row['description'],
                'reference'        => $row['reference'],
                'status'           => BankTransaction::STATUS_UNMATCHED,
                'raw_data'         => $row,
            ]);

            $newTransactions->push($tx);
            $imported++;
        }

        // Record the file so it can never be imported again
        BankStatementImport::create([
            'bank_account_id' => $this->bankAccountId,
            'file_hash'       => $this->fileHash,
            'row_count'       => count($this->preview),
        ]);

        // 1. Tag corporate-card transactions FIRST — sets corporate_card_id so the
        //    rule engine (step 3) skips them. Card purchases must not be auto-booked;
        //    they need a receipt from the card holder before a verifikat is created.
        $cardResult = app(CardReceiptMatcher::class)->processImport($newTransactions);
        $this->cardTagged      = $cardResult['tagged'];
        $this->cardMatched     = $cardResult['matched'];
        $this->cardMissing     = $cardResult['missing'];
        $this->cardUnknownRefs = $cardResult['unknownRefs'];

        // 2. Match invoices — transactions matched here get status=matched so the
        //    rule engine skips them, preventing double-booking.
        $invoiceResult = app(InvoiceMatcherService::class)->processImport($newTransactions);
        $this->invoiceMatched   = $invoiceResult['matched'];
        $this->invoicePotential = collect($invoiceResult['potential'])
            ->map(fn ($p) => [
                'tx_id'     => $p['tx']->id,
                'tx_date'   => $p['tx']->booking_date?->format('d/m Y') ?? $p['tx']->transaction_date?->format('d/m Y'),
                'tx_desc'   => $p['tx']->description,
                'tx_amount' => $p['tx']->amount,
                'invoices'  => $p['invoices']->map(fn ($inv) => [
                    'id'          => $inv->id,
                    'type'        => $inv->type,
                    'number'      => $inv->invoice_number ?? "#{$inv->id}",
                    'counterpart' => $inv->customer?->name ?? $inv->vendor?->name ?? '–',
                ])->all(),
            ])
            ->all();

        // 3. Match pending Orders by OCR number — fires OrderPaid on each match,
        //    which activates the membership and creates a journal entry.
        //    Must run after invoice matching and before posting rules.
        $orderMatched = app(\App\Services\Accounting\OrderMatcherService::class)
            ->processImport($newTransactions);

        // 4. Apply booking rules — skips card transactions (corporate_card_id IS NOT NULL)
        //    and invoice/order-matched transactions (status = matched).
        $ruleResult = app(PostingRuleService::class)->applyAll($this->bankAccountId);
        $autoPosted = $ruleResult['posted'];

        // 5. Reconcile SumUp payouts against newly imported bank transactions.
        $sumupMatched = app(\App\Services\Accounting\SumupReconciler::class)
            ->reconcileAll($this->bankAccountId);

        // 6. Match internal transfers between own bank accounts.
        $transferMatched = app(\App\Services\Accounting\InternalTransferMatcher::class)
            ->matchAll();

        // 7. Match Zettle Swish bank transactions to Zettle SWISH sales.
        $zettleReconciler   = app(\App\Services\Accounting\ZettleReconciler::class);
        $zettleSwishMatched = $zettleReconciler->reconcileSwish($this->bankAccountId);

        // 8. Match Zettle card-payout bank transactions to ZettlePayout records.
        $zettlePayoutMatched = $zettleReconciler->reconcilePayouts($this->bankAccountId);

        $this->imported = true;
        $this->preview  = [];
        $this->file     = null;
        $this->skipped  = 0;
        $this->fileHash = '';

        $msg = "{$imported} transaktioner importerade.";
        if ($orderMatched > 0) {
            $msg .= " {$orderMatched} " . ($orderMatched === 1 ? 'betalning matchades' : 'betalningar matchades') . " mot fakturor och bokfördes.";
        }
        if ($autoPosted > 0) {
            $msg .= " {$autoPosted} bokfördes automatiskt via regler.";
        }
        if ($sumupMatched > 0) {
            $msg .= " {$sumupMatched} SumUp-utbetalningar matchades och bokfördes.";
        }
        if ($zettleSwishMatched > 0) {
            $msg .= " {$zettleSwishMatched} Zettle Swish-betalningar matchades och bokfördes.";
        }
        if ($zettlePayoutMatched > 0) {
            $msg .= " {$zettlePayoutMatched} Zettle-utbetalningar matchades och bokfördes.";
        }
        if ($transferMatched > 0) {
            $msg .= " {$transferMatched} interna överföringar bokfördes.";
        }
        if ($this->cardTagged > 0) {
            $msg .= " {$this->cardTagged} korttransaktioner identifierade.";
        }
        $this->dispatch('notify', message: $msg);
    }

    public function resetForm(): void
    {
        $this->preview               = [];
        $this->file                  = null;
        $this->skipped               = 0;
        $this->imported              = false;
        $this->fileAlreadyImported   = false;
        $this->fileHash              = '';
        $this->accountAutoDetected   = false;
        $this->detectedAccountNumber = '';
        $this->invoiceMatched        = 0;
        $this->invoicePotential      = [];
    }
};
?>

<div>

    <div class="space-y-6 max-w-4xl">

        @if ($imported)
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-4 text-sm text-green-800
                        dark:border-green-700/40 dark:bg-green-900/20 dark:text-green-300">
                <div class="flex items-center gap-2">
                    <x-bootstrap-icon name="check-circle-fill" class="size-4" />
                    Import klar. Transaktionerna är nu tillgängliga för bankavstämning.
                </div>

                {{-- Invoice match results --}}
                @if ($invoiceMatched > 0 || count($invoicePotential) > 0)
                    <div class="mt-3 space-y-1.5 text-xs border-t border-green-200 dark:border-green-700/40 pt-3">
                        <p class="font-medium text-green-800 dark:text-green-300">Fakturamatchning</p>
                        @if ($invoiceMatched > 0)
                            <p class="flex items-center gap-1">
                                <x-bootstrap-icon name="check-circle-fill" class="size-3 text-green-500" />
                                {{ $invoiceMatched }} faktura{{ $invoiceMatched !== 1 ? 'r' : '' }} matchad{{ $invoiceMatched !== 1 ? 'e' : '' }} och markerad{{ $invoiceMatched !== 1 ? 'e' : '' }} som betald{{ $invoiceMatched !== 1 ? 'a' : '' }}. Kassören har fått avisering med underlag.
                            </p>
                        @endif
                        @if (count($invoicePotential) > 0)
                            <p class="flex items-center gap-1 text-amber-700 dark:text-amber-400">
                                <x-bootstrap-icon name="exclamation-circle-fill" class="size-3" />
                                {{ count($invoicePotential) }} möjlig{{ count($invoicePotential) !== 1 ? 'a' : '' }} matchning{{ count($invoicePotential) !== 1 ? 'ar' : '' }} — bekräfta manuellt under bankavstämning.
                            </p>
                            <div class="mt-1 space-y-1">
                                @foreach ($invoicePotential as $pot)
                                    <div class="rounded border border-amber-200 dark:border-amber-700/40 bg-amber-50 dark:bg-amber-900/10 px-3 py-2">
                                        <p class="font-medium text-amber-800 dark:text-amber-300">
                                            {{ $pot['tx_date'] }} · {{ number_format($pot['tx_amount'] / 100, 2, ',', ' ') }} kr · {{ $pot['tx_desc'] }}
                                        </p>
                                        <p class="text-amber-700 dark:text-amber-400 mt-0.5">
                                            Möjlig matchning:
                                            @foreach ($pot['invoices'] as $inv)
                                                <a href="{{ route('admin.accounting.invoices.' . ($inv['type'] ?? 'payable') . '.show', $inv['id']) }}"
                                                   wire:navigate
                                                   class="font-medium underline">
                                                    {{ $inv['number'] }} ({{ $inv['counterpart'] }})
                                                </a>{{ ! $loop->last ? ', ' : '' }}
                                            @endforeach
                                        </p>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                @if ($cardTagged > 0 || count($cardUnknownRefs) > 0)
                    <div class="mt-3 space-y-1.5 text-xs border-t border-green-200 dark:border-green-700/40 pt-3">
                        <p class="font-medium text-green-800 dark:text-green-300">Kontokort</p>
                        @if ($cardTagged > 0)
                            <p>{{ $cardTagged }} korttransaktion{{ $cardTagged !== 1 ? 'er' : '' }} identifierade.</p>
                        @endif
                        @if ($cardMatched > 0)
                            <p class="flex items-center gap-1">
                                <x-bootstrap-icon name="check-circle-fill" class="size-3 text-green-500" />
                                {{ $cardMatched }} matchade mot inlämnade kvitton automatiskt.
                            </p>
                        @endif
                        @if ($cardMissing > 0)
                            <p class="flex items-center gap-1 text-amber-700 dark:text-amber-400">
                                <x-bootstrap-icon name="exclamation-circle-fill" class="size-3" />
                                {{ $cardMissing }} saknar kvitto — kortinnehavarna har fått en notifikation.
                            </p>
                        @endif
                    </div>
                @endif

                @if (count($cardUnknownRefs) > 0)
                    <div class="mt-3 rounded-lg border border-red-200 bg-red-50 dark:border-red-800/40 dark:bg-red-900/10 px-4 py-3 text-xs">
                        <p class="font-semibold text-red-800 dark:text-red-300 flex items-center gap-1.5 mb-1.5">
                            <x-bootstrap-icon name="credit-card-2-front" class="size-3.5" />
                            Okänt kort{{ count($cardUnknownRefs) > 1 ? 'referenser' : 'referens' }} — åtgärd krävs
                        </p>
                        <p class="text-red-700 dark:text-red-400 mb-2">
                            Följande kortreferens{{ count($cardUnknownRefs) > 1 ? 'er hittades' : ' hittades' }} i kontoutdraget men
                            {{ count($cardUnknownRefs) > 1 ? 'är inte registrerade' : 'är inte registrerad' }}:
                        </p>
                        <div class="flex flex-wrap gap-2 mb-2">
                            @foreach ($cardUnknownRefs as $ref)
                                <span class="font-mono font-bold px-2 py-0.5 rounded bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-200">
                                    {{ $ref }}
                                </span>
                            @endforeach
                        </div>
                        <p class="text-red-600 dark:text-red-400">
                            Transaktionerna är importerade men saknar koppling till kortinnehavare.
                            Registrera kortet så matchas transaktionerna retroaktivt.
                        </p>
                        <a href="{{ route('admin.accounting.corporate-cards.index') }}" wire:navigate
                           class="mt-2 inline-flex items-center gap-1 font-medium text-red-700 dark:text-red-300 hover:underline">
                            Registrera kontokort →
                        </a>
                    </div>
                @endif
                <div class="mt-3 flex gap-3">
                    <button wire:click="resetForm" class="btn btn-ghost btn-sm">Importera fler</button>
                    <a href="{{ route('admin.accounting.bank-transactions.index') }}" wire:navigate class="btn btn-sm">
                        Visa transaktioner →
                    </a>
                    @if ($cardMissing > 0)
                        <a href="{{ route('admin.accounting.inbox.index', ['tab' => 'restlista']) }}" wire:navigate class="btn btn-sm">
                            Visa restlista →
                        </a>
                    @endif
                </div>
            </div>
        @else
            {{-- File already imported warning --}}
            @if ($fileAlreadyImported)
                <div class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-700/40 dark:bg-amber-900/20 px-4 py-4 text-sm text-amber-800 dark:text-amber-300">
                    <div class="flex items-center gap-2 font-medium">
                        <x-bootstrap-icon name="exclamation-triangle-fill" class="size-4" />
                        Den här filen har redan importerats
                    </div>
                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">
                        Filinnehållet matchar en tidigare import. Ladda upp ett annat kontoutdrag.
                    </p>
                    <button wire:click="resetForm" class="mt-3 btn btn-ghost btn-sm">Välj en annan fil</button>
                </div>
            @endif

            {{-- Setup form --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 space-y-4 bg-white dark:bg-zinc-900">
                <h2 class="font-semibold text-sm">1. Ladda upp fil</h2>

                <div>
                    <flux:label>Fil (CSV eller TXT från Swedbank)</flux:label>
                    <input type="file"
                           wire:model.live="file"
                           accept=".csv,.txt"
                           class="mt-1 block w-full text-sm text-zinc-500
                                  file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0
                                  file:text-sm file:font-medium
                                  file:bg-zinc-100 file:text-zinc-700
                                  dark:file:bg-zinc-700 dark:file:text-zinc-200
                                  hover:file:bg-zinc-200 dark:hover:file:bg-zinc-600" />
                    <p class="mt-1 text-xs text-zinc-400">
                        Exportera från Swedbank Företag → Konto → Transaktioner → Exportera.
                        Välj CSV eller Tabseparerat.
                    </p>
                </div>

                @if ($file && ! $fileAlreadyImported)
                    @if ($accountAutoDetected)
                        {{-- Auto-detected — show read-only with override option --}}
                        <div class="flex items-center gap-3 rounded-lg border border-green-200 bg-green-50 dark:border-green-700/40 dark:bg-green-900/20 px-3 py-2 text-sm">
                            <x-bootstrap-icon name="check-circle-fill" class="size-4 text-green-500 shrink-0" />
                            <span class="text-green-800 dark:text-green-300">
                                Konto identifierat automatiskt:
                                <strong>{{ $this->bankAccounts->firstWhere('id', $bankAccountId)?->name }}</strong>
                            </span>
                            <button type="button"
                                    wire:click="$set('accountAutoDetected', false)"
                                    class="ml-auto text-xs text-green-700 dark:text-green-400 hover:underline shrink-0">
                                Byt konto
                            </button>
                        </div>
                    @else
                        <div>
                            <flux:label for="bankAccount">Bankkonto</flux:label>
                            @if ($detectedAccountNumber && ! $accountAutoDetected)
                                <p class="mt-0.5 text-xs text-amber-600 dark:text-amber-400">
                                    Kontonummer i filen ({{ $detectedAccountNumber }}) matchar inget registrerat bankkonto.
                                    Välj manuellt nedan.
                                </p>
                            @endif
                            <select id="bankAccount"
                                    wire:model.live="bankAccountId"
                                    class="inp mt-1 w-full">
                                <option value="">Välj konto…</option>
                                @foreach ($this->bankAccounts as $ba)
                                    <option value="{{ $ba->id }}">{{ $ba->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                @endif
            </div>

            {{-- Preview --}}
            @if (count($preview) > 0)
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                    <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-100 dark:border-zinc-800">
                        <h2 class="font-semibold text-sm">
                            2. Förhandsgranska
                            <span class="font-normal text-zinc-400 ml-1">
                                {{ count($preview) }} rader,
                                {{ count($preview) - $skipped }} nya,
                                {{ $skipped }} redan importerade
                            </span>
                        </h2>
                        @if (count($preview) - $skipped > 0)
                            <flux:button wire:click="import" variant="primary" size="sm">
                                Importera {{ count($preview) - $skipped }} transaktioner
                            </flux:button>
                        @else
                            <span class="text-sm text-zinc-400">Inga nya transaktioner att importera.</span>
                        @endif
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="text-zinc-400 dark:text-zinc-500 border-b border-zinc-100 dark:border-zinc-800">
                                <tr>
                                    <th class="px-4 py-2 text-left w-28">Bokfdag</th>
                                    <th class="px-4 py-2 text-left w-28">Transdag</th>
                                    <th class="px-4 py-2 text-left">Referens</th>
                                    <th class="px-4 py-2 text-left">Text</th>
                                    <th class="px-4 py-2 text-right w-28">Belopp</th>
                                    <th class="px-4 py-2 text-right w-28">Saldo</th>
                                    <th class="px-4 py-2 w-24"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                                @foreach ($preview as $row)
                                    <tr class="{{ $row['duplicate'] ? 'opacity-40' : '' }}">
                                        <td class="px-4 py-1.5 font-mono text-zinc-500">{{ $row['booking_date'] }}</td>
                                        <td class="px-4 py-1.5 font-mono text-zinc-400">{{ $row['transaction_date'] }}</td>
                                        <td class="px-4 py-1.5 text-zinc-500 max-w-[120px] truncate">{{ $row['reference'] }}</td>
                                        <td class="px-4 py-1.5 text-zinc-700 dark:text-zinc-300 max-w-[200px] truncate">{{ $row['description'] }}</td>
                                        <td class="px-4 py-1.5 font-mono text-right {{ $row['amount_ore'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                            {{ number_format($row['amount_ore'] / 100, 2, ',', ' ') }}
                                        </td>
                                        <td class="px-4 py-1.5 font-mono text-right text-zinc-400">
                                            {{ number_format($row['balance_ore'] / 100, 2, ',', ' ') }}
                                        </td>
                                        <td class="px-4 py-1.5 text-right">
                                            @if ($row['duplicate'])
                                                <span class="text-xs text-zinc-400">redan importerad</span>
                                            @else
                                                <x-bootstrap-icon name="plus-circle" class="size-3.5 text-green-500" />
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @elseif ($file && ! $fileAlreadyImported && ! $bankAccountId)
                <p class="text-sm text-amber-600 dark:text-amber-400">Välj ett bankkonto för att förhandsgranska.</p>
            @elseif ($file && ! $fileAlreadyImported)
                <p class="text-sm text-zinc-400">Ingen data hittades i filen.</p>
            @endif

            {{-- Info about BG/BGMax --}}
            <div class="rounded-lg border border-zinc-100 dark:border-zinc-800 px-4 py-3 text-xs text-zinc-400 dark:text-zinc-500">
                <p class="font-medium text-zinc-500 dark:text-zinc-400 mb-1">Om Bankgiro-betalningar</p>
                <p>
                    Bankgiro-inbetalningar syns i kontoutdraget som en rad ("Bankgiro inbetalning").
                    För att se <em>vem</em> som betalat behöver du BGMax-filen från Bankgirot —
                    ett maskinläsbart format med betalningsreferenser och avsändare per transaktion.
                    BGMax beställs via Bankgirot (Swedbank Företag → Betalningstjänster → BGMax).
                </p>
            </div>
        @endif

    </div>
</div>
