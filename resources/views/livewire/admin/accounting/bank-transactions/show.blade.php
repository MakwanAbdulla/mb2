<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalSeries;
use App\Models\AuditLog;
use App\Services\Accounting\InvoiceMatcherService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Bankavstämning');

new class extends Component {

    public BankTransaction $bankTransaction;

    /** 'match' | 'book' | 'ignore' | 'invoice' | '' */
    public string $mode = '';

    // Invoice match mode
    public string $invoiceSearch  = '';
    public string $invoiceType    = Invoice::TYPE_RECEIVABLE; // default to incoming

    // Match mode
    public string $matchSearch = '';

    // Book mode
    public string $formSeries      = 'A';
    public string $formDate        = '';
    public string $formDescription = '';
    public string $otherAccountId  = '';
    public string $costCenterId    = '';

    // Ignore mode
    public string $ignoreNote = '';

    public function mount(BankTransaction $bankTransaction): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $this->bankTransaction = $bankTransaction->load([
            'bankAccount.account',
            'journalEntry.currentLines.account',
            'invoice.vendor',
            'invoice.customer',
            'invoice.documents',
        ]);

        // Default invoice type based on transaction direction
        $this->invoiceType = $bankTransaction->amount > 0
            ? Invoice::TYPE_RECEIVABLE
            : Invoice::TYPE_PAYABLE;

        $this->formDate        = $bankTransaction->booking_date?->format('Y-m-d')
                               ?? $bankTransaction->transaction_date?->format('Y-m-d')
                               ?? today()->format('Y-m-d');
        $this->formDescription = $bankTransaction->description ?? '';

        // Default to first available manual series
        $first = JournalSeries::where('is_manual', true)->orderBy('sort_order')->first();
        if ($first) {
            $this->formSeries = $first->code;
        }
    }

    #[Computed]
    public function accounts()
    {
        return Account::where('is_active', true)
            ->where('is_group', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function manualSeries()
    {
        return JournalSeries::where('is_manual', true)->orderBy('sort_order')->get();
    }

    /** Bank ledger account id (from the linked BankAccount). */
    #[Computed]
    public function bankLedgerAccountId(): ?int
    {
        return $this->bankTransaction->bankAccount?->account_id;
    }

    #[Computed]
    public function bankLedgerAccount(): ?Account
    {
        $id = $this->bankLedgerAccountId;
        return $id ? $this->accounts->firstWhere('id', $id) : null;
    }

    /** Search unpaid invoices to match against. */
    #[Computed]
    public function invoiceResults()
    {
        if (strlen(trim($this->invoiceSearch)) < 2) {
            return collect();
        }

        return Invoice::where('type', $this->invoiceType)
            ->where('status', Invoice::STATUS_POSTED)
            ->whereDoesntHave('bankTransaction')
            ->where(function ($q) {
                $term = '%' . trim($this->invoiceSearch) . '%';
                $q->where('invoice_number', 'like', $term)
                  ->orWhere('ocr_number', 'like', $term)
                  ->orWhereHas('vendor', fn ($q2) => $q2->where('name', 'like', $term))
                  ->orWhereHas('customer', fn ($q2) => $q2->where('name', 'like', $term));
            })
            ->with(['vendor', 'customer'])
            ->orderByDesc('due_date')
            ->limit(8)
            ->get();
    }

    /** Link this transaction to an invoice (kassör manual confirmation). */
    public function linkInvoice(int $invoiceId): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $invoice = Invoice::findOrFail($invoiceId);

        if ($invoice->bankTransaction()->exists()) {
            $this->addError('invoice', 'Fakturan är redan kopplad till en annan transaktion.');
            return;
        }

        try {
            app(InvoiceMatcherService::class)->confirmMatch($this->bankTransaction, $invoice);
        } catch (\Throwable $e) {
            $this->addError('invoice', $e->getMessage());
            return;
        }

        $this->bankTransaction->refresh()->load([
            'bankAccount.account', 'journalEntry.currentLines.account',
            'invoice.vendor', 'invoice.customer', 'invoice.documents',
        ]);

        AuditLog::record('bank_tx_invoice_linked', 'accounting',
            number_format(abs($this->bankTransaction->amount) / 100, 2, ',', ' ') . ' kr — ' . ($this->bankTransaction->description ?? ''),
            $this->bankTransaction);

        $this->mode          = '';
        $this->invoiceSearch = '';
        unset($this->invoiceResults);
    }

    /** Remove the invoice link and revert invoice to posted. */
    public function unlinkInvoice(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $invoice = $this->bankTransaction->invoice;

        $this->bankTransaction->update([
            'invoice_id' => null,
            'status'     => BankTransaction::STATUS_UNMATCHED,
        ]);

        if ($invoice) {
            $invoice->update([
                'status'  => Invoice::STATUS_POSTED,
                'paid_at' => null,
            ]);
        }

        $this->bankTransaction->refresh()->load([
            'bankAccount.account', 'journalEntry.currentLines.account',
            'invoice.vendor', 'invoice.customer', 'invoice.documents',
        ]);

        AuditLog::record('bank_tx_invoice_unlinked', 'accounting',
            number_format(abs($this->bankTransaction->amount) / 100, 2, ',', ' ') . ' kr — ' . ($this->bankTransaction->description ?? ''),
            $this->bankTransaction);
    }

    /**
     * Journal entries to match against.
     *
     * Without a search term: shows entries whose max line amount equals the
     * transaction amount (exact-amount default list).
     * With a search term: filters by text across description, series+number,
     * and account name/code — still sorted with exact-amount matches first.
     *
     * Excludes entries already linked to another bank transaction.
     */
    #[Computed]
    public function matchResults()
    {
        $absAmount = abs($this->bankTransaction->amount);

        $linkedIds = BankTransaction::whereNotNull('journal_entry_id')
            ->where('id', '!=', $this->bankTransaction->id)
            ->pluck('journal_entry_id');

        $q = JournalEntry::with(['currentLines.account'])
            ->whereNotIn('id', $linkedIds)
            ->whereHas('currentLines', fn ($lq) =>
                $lq->where('debit', $absAmount)->orWhere('credit', $absAmount)
            );

        $search = trim($this->matchSearch);
        if (strlen($search) >= 2) {
            $term = '%' . $search . '%';
            $q->where(function ($sq) use ($term) {
                $sq->where('description', 'like', $term)
                   ->orWhere(DB::raw("CONCAT(series, number)"), 'like', $term)
                   ->orWhereHas('currentLines.account', fn ($q2) =>
                       $q2->where('name', 'like', $term)->orWhere('code', 'like', $term)
                   );
            });
        }

        return $q->orderByDesc('date')
            ->limit(10)
            ->get();
    }

    /** Link an existing journal entry to this transaction (status → matched). */
    public function linkEntry(int $journalEntryId): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        // Guard: entry must not already be linked to another transaction
        $alreadyLinked = BankTransaction::where('journal_entry_id', $journalEntryId)
            ->where('id', '!=', $this->bankTransaction->id)
            ->exists();

        if ($alreadyLinked) {
            $this->addError('match', 'Verifikationen är redan kopplad till en annan transaktion.');
            return;
        }

        $this->bankTransaction->update([
            'journal_entry_id' => $journalEntryId,
            'status'           => BankTransaction::STATUS_MATCHED,
        ]);

        $this->bankTransaction->refresh()->load(['bankAccount.account', 'journalEntry.currentLines.account']);

        AuditLog::record('bank_tx_entry_linked', 'accounting',
            number_format(abs($this->bankTransaction->amount) / 100, 2, ',', ' ') . ' kr — ' . ($this->bankTransaction->description ?? ''),
            $this->bankTransaction);

        $this->mode        = '';
        $this->matchSearch = '';
    }

    /** Create a new journal entry and link it (status → posted). */
    public function book(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $this->validate([
            'formSeries'      => 'required|string|size:1',
            'formDate'        => 'required|date',
            'formDescription' => 'required|string|max:500',
            'otherAccountId'  => 'required|exists:accounts,id',
        ]);

        $tx      = $this->bankTransaction;
        $amount  = abs($tx->amount);  // öre, always positive
        $bankId  = $this->bankLedgerAccountId;

        if (! $bankId) {
            $this->addError('book', 'Bankkontot saknar kopplat redovisningskonto.');
            return;
        }

        // Find unlocked fiscal year covering the date
        $fy = FiscalYear::where('is_locked', false)
            ->where('starts_at', '<=', $this->formDate)
            ->where('ends_at', '>=', $this->formDate)
            ->first();

        if (! $fy) {
            $this->addError('formDate', 'Inget öppet räkenskapsår täcker det valda datumet.');
            return;
        }

        // Credit (incoming money, amount > 0): debit bank, credit other
        // Debit  (outgoing money, amount < 0): debit other, credit bank
        $isIncoming = $tx->amount > 0;

        $lines = [
            [
                'account_id'     => $isIncoming ? $bankId : (int) $this->otherAccountId,
                'cost_center_id' => $isIncoming ? null : ($this->costCenterId ?: null),
                'debit'          => $amount,
                'credit'         => 0,
                'description'    => null,
            ],
            [
                'account_id'     => $isIncoming ? (int) $this->otherAccountId : $bankId,
                'cost_center_id' => $isIncoming ? ($this->costCenterId ?: null) : null,
                'debit'          => 0,
                'credit'         => $amount,
                'description'    => null,
            ],
        ];

        $entry = DB::transaction(function () use ($fy, $lines, $tx) {
            $lastNumber = JournalEntry::where('fiscal_year_id', $fy->id)
                ->where('series', $this->formSeries)
                ->lockForUpdate()
                ->max('number') ?? 0;

            $entry = JournalEntry::create([
                'fiscal_year_id'  => $fy->id,
                'series'          => $this->formSeries,
                'number'          => $lastNumber + 1,
                'date'            => $this->formDate,
                'description'     => $this->formDescription,
                'source_type'     => BankTransaction::class,
                'source_id'       => $tx->id,
                'created_by'      => Auth::id(),
                'requires_receipt' => $tx->corporate_card_id !== null,
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create($line);
            }

            $tx->update([
                'journal_entry_id' => $entry->id,
                'status'           => BankTransaction::STATUS_POSTED,
            ]);

            return $entry;
        });

        $this->bankTransaction->refresh()->load(['bankAccount.account', 'journalEntry.currentLines.account']);

        AuditLog::record('bank_tx_booked', 'accounting',
            number_format(abs($this->bankTransaction->amount) / 100, 2, ',', ' ') . ' kr — ' . ($this->bankTransaction->description ?? ''),
            $this->bankTransaction);

        $this->mode = '';
    }

    /** Mark as ignored with optional note. */
    public function ignore(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $this->bankTransaction->update([
            'status' => BankTransaction::STATUS_IGNORED,
            'notes'  => trim($this->ignoreNote) ?: null,
        ]);

        $this->bankTransaction->refresh()->load(['bankAccount.account', 'journalEntry.currentLines.account']);

        AuditLog::record('bank_tx_ignored', 'accounting',
            number_format(abs($this->bankTransaction->amount) / 100, 2, ',', ' ') . ' kr — ' . ($this->bankTransaction->description ?? ''),
            $this->bankTransaction);

        $this->mode       = '';
        $this->ignoreNote = '';
    }

    /** Remove match/posting and return to unmatched. Does NOT delete the journal entry. */
    public function unmatch(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $this->bankTransaction->update([
            'journal_entry_id' => null,
            'status'           => BankTransaction::STATUS_UNMATCHED,
            'notes'            => null,
        ]);

        $this->bankTransaction->refresh()->load(['bankAccount.account', 'journalEntry.currentLines.account']);

        AuditLog::record('bank_tx_unmatched', 'accounting',
            number_format(abs($this->bankTransaction->amount) / 100, 2, ',', ' ') . ' kr — ' . ($this->bankTransaction->description ?? ''),
            $this->bankTransaction);

        $this->mode = '';
    }
};
?>

<div>

    <div class="space-y-6 max-w-3xl">

        <div class="flex justify-end">
            <a href="{{ route('admin.accounting.posting-rules.index', ['from_tx' => $bankTransaction->id]) }}"
               wire:navigate
               class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 flex items-center gap-1">
                <x-bootstrap-icon name="lightning" class="size-3.5" />
                Skapa bokföringsregel från denna transaktion
            </a>
        </div>

        {{-- Transaction info card --}}
        @php
            $tx     = $bankTransaction;
            $amount = $tx->amount;
            $isIn   = $amount >= 0;
            $statusMap = [
                'unmatched' => ['Omatchad', 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
                'matched'   => ['Matchad',  'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'],
                'posted'    => ['Bokförd',  'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'],
                'ignored'   => ['Ignorerad','bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400'],
            ];
            [$statusLabel, $statusCls] = $statusMap[$tx->status] ?? ['Okänd', ''];
        @endphp

        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
            <div class="px-5 py-3 flex items-center justify-between">
                <h2 class="font-semibold text-sm">Transaktion</h2>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $statusCls }}">
                    {{ $statusLabel }}
                </span>
            </div>
            <div class="px-5 py-4 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                <div>
                    <p class="text-xs text-zinc-400 mb-0.5">Bokföringsdag</p>
                    <p class="font-mono">{{ $tx->booking_date?->format('d/m Y') ?? '–' }}</p>
                </div>
                <div>
                    <p class="text-xs text-zinc-400 mb-0.5">Transaktionsdag</p>
                    <p class="font-mono text-zinc-500">{{ $tx->transaction_date?->format('d/m Y') ?? '–' }}</p>
                </div>
                <div>
                    <p class="text-xs text-zinc-400 mb-0.5">Konto</p>
                    <p class="text-zinc-700 dark:text-zinc-300">{{ $tx->bankAccount->name }}</p>
                </div>
                <div>
                    <p class="text-xs text-zinc-400 mb-0.5">Referens</p>
                    <p class="font-mono text-zinc-500 text-xs">{{ $tx->reference ?: '–' }}</p>
                </div>
                <div class="col-span-2 sm:col-span-2">
                    <p class="text-xs text-zinc-400 mb-0.5">Text</p>
                    <p class="text-zinc-700 dark:text-zinc-300">{{ $tx->description ?: '–' }}</p>
                </div>
                <div>
                    <p class="text-xs text-zinc-400 mb-0.5">Belopp</p>
                    <p class="text-xl font-mono font-semibold {{ $isIn ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                        {{ $isIn ? '+' : '' }}{{ number_format($amount / 100, 2, ',', ' ') }}
                    </p>
                </div>
                @if ($tx->balance_ore !== null)
                    <div>
                        <p class="text-xs text-zinc-400 mb-0.5">Saldo efter</p>
                        <p class="font-mono text-zinc-500 text-sm">
                            {{ number_format($tx->balance_ore / 100, 2, ',', ' ') }}
                        </p>
                    </div>
                @endif
                @if ($this->bankLedgerAccount)
                    <div>
                        <p class="text-xs text-zinc-400 mb-0.5">Redovisningskonto</p>
                        <p class="font-mono text-sm">
                            <span class="font-semibold">{{ $this->bankLedgerAccount->code }}</span>
                            <span class="text-zinc-400"> {{ $this->bankLedgerAccount->name }}</span>
                        </p>
                    </div>
                @endif
            </div>
            @if ($tx->notes)
                <div class="px-5 py-3 text-sm text-zinc-500 dark:text-zinc-400">
                    <span class="text-xs text-zinc-400">Anteckning: </span>{{ $tx->notes }}
                </div>
            @endif
        </div>

        {{-- Matched invoice --}}
        @if ($tx->invoice)
            @php $inv = $tx->invoice; @endphp
            <div class="rounded-xl border border-violet-200 dark:border-violet-800/40 bg-violet-50 dark:bg-violet-900/10">
                <div class="px-5 py-3 border-b border-violet-100 dark:border-violet-800/30 flex items-center justify-between">
                    <h2 class="font-semibold text-sm text-violet-800 dark:text-violet-300 flex items-center gap-1.5">
                        <x-bootstrap-icon name="receipt" class="size-4" />
                        Matchad faktura — {{ $inv->isPayable() ? 'leverantörsfaktura' : 'utställd faktura' }}
                    </h2>
                    <div class="flex items-center gap-3">
                        @if ($inv->isPayable())
                            <a href="{{ route('admin.accounting.invoices.payable.show', $inv) }}"
                               wire:navigate class="text-xs text-violet-600 dark:text-violet-400 hover:underline">
                                Öppna →
                            </a>
                        @else
                            <a href="{{ route('admin.accounting.invoices.receivable.show', $inv) }}"
                               wire:navigate class="text-xs text-violet-600 dark:text-violet-400 hover:underline">
                                Öppna →
                            </a>
                        @endif
                        <button wire:click="unlinkInvoice"
                                wire:confirm="Koppla loss fakturan? Fakturan återgår till status Bokförd."
                                class="text-xs text-zinc-400 hover:text-red-600 dark:hover:text-red-400">
                            Koppla loss
                        </button>
                    </div>
                </div>
                <div class="px-5 py-3 grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                    <div>
                        <p class="text-xs text-zinc-400 mb-0.5">Fakturanummer</p>
                        <p class="font-medium">{{ $inv->invoice_number ?? "#{$inv->id}" }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-zinc-400 mb-0.5">{{ $inv->isPayable() ? 'Leverantör' : 'Kund' }}</p>
                        <p>{{ $inv->vendor?->name ?? $inv->customer?->name ?? '–' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-zinc-400 mb-0.5">Belopp</p>
                        <p class="font-mono">{{ number_format($inv->total / 100, 2, ',', ' ') }} kr</p>
                    </div>
                    <div>
                        <p class="text-xs text-zinc-400 mb-0.5">Betald</p>
                        <p class="font-mono text-green-600 dark:text-green-400">
                            {{ $inv->paid_at?->format('d/m Y') ?? '–' }}
                        </p>
                    </div>
                </div>
                @if ($inv->documents->isNotEmpty())
                    <div class="px-5 py-2 border-t border-violet-100 dark:border-violet-800/30 text-xs text-zinc-500">
                        <span class="text-zinc-400">Bilagor: </span>
                        @foreach ($inv->documents as $doc)
                            <span>{{ $doc->original_name }}</span>{{ ! $loop->last ? ', ' : '' }}
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Already linked journal entry --}}
        @if ($tx->journalEntry)
            <div class="rounded-xl border border-blue-200 dark:border-blue-800/40 bg-blue-50 dark:bg-blue-900/10">
                <div class="px-5 py-3 border-b border-blue-100 dark:border-blue-800/30 flex items-center justify-between">
                    <h2 class="font-semibold text-sm text-blue-800 dark:text-blue-300">
                        Kopplad verifikation
                    </h2>
                    <div class="flex items-center gap-3">
                        <a href="{{ route('admin.accounting.journal-entries.show', $tx->journalEntry) }}"
                           wire:navigate
                           class="text-xs text-blue-600 dark:text-blue-400 hover:underline">
                            Öppna →
                        </a>
                        <button wire:click="unmatch"
                                wire:confirm="Koppla loss verifikationen från transaktionen? Verifikationen raderas inte."
                                class="text-xs text-zinc-400 hover:text-red-600 dark:hover:text-red-400">
                            Koppla loss
                        </button>
                    </div>
                </div>
                @php $je = $tx->journalEntry; @endphp
                <div class="px-5 py-3 text-sm">
                    <div class="flex items-baseline gap-3 mb-2">
                        <span class="font-mono font-semibold">{{ $je->series }}{{ $je->number }}</span>
                        <span class="text-zinc-500">{{ $je->date?->format('d/m Y') }}</span>
                        <span class="text-zinc-700 dark:text-zinc-300">{{ $je->description }}</span>
                    </div>
                    <table class="w-full text-xs text-zinc-500 dark:text-zinc-400">
                        @foreach ($je->currentLines as $line)
                            <tr>
                                <td class="py-0.5 w-20 font-mono">{{ $line->account->code ?? '–' }}</td>
                                <td class="py-0.5">{{ $line->account->name ?? '–' }}</td>
                                <td class="py-0.5 text-right w-28 font-mono {{ $line->debit  > 0 ? 'text-zinc-700 dark:text-zinc-300' : '' }}">
                                    {{ $line->debit  > 0 ? number_format($line->debit  / 100, 2, ',', ' ') : '' }}
                                </td>
                                <td class="py-0.5 text-right w-28 font-mono {{ $line->credit > 0 ? 'text-zinc-700 dark:text-zinc-300' : '' }}">
                                    {{ $line->credit > 0 ? number_format($line->credit / 100, 2, ',', ' ') : '' }}
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </div>
        @endif

        {{-- Actions — only shown when unmatched --}}
        @if ($tx->status === 'unmatched')
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                <div class="px-5 py-3 border-b border-zinc-100 dark:border-zinc-800">
                    <h2 class="font-semibold text-sm">Åtgärd</h2>
                </div>

                {{-- Mode selector tabs --}}
                <div class="px-5 py-3 flex gap-2 border-b border-zinc-100 dark:border-zinc-800">
                    <button wire:click="$set('mode', 'match')"
                            class="px-3 py-1.5 rounded text-sm font-medium transition-colors
                                   {{ $mode === 'match' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
                        <x-bootstrap-icon name="link-45deg" class="size-3.5 inline mr-1" />
                        Matcha verifikat
                    </button>
                    <button wire:click="$set('mode', 'book')"
                            class="px-3 py-1.5 rounded text-sm font-medium transition-colors
                                   {{ $mode === 'book' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
                        <x-bootstrap-icon name="plus-circle" class="size-3.5 inline mr-1" />
                        Boka nytt verifikat
                    </button>
                    <button wire:click="$set('mode', 'invoice')"
                            class="px-3 py-1.5 rounded text-sm font-medium transition-colors
                                   {{ $mode === 'invoice' ? 'bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
                        <x-bootstrap-icon name="receipt" class="size-3.5 inline mr-1" />
                        Matcha faktura
                    </button>
                    <button wire:click="$set('mode', 'ignore')"
                            class="px-3 py-1.5 rounded text-sm font-medium transition-colors
                                   {{ $mode === 'ignore' ? 'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
                        <x-bootstrap-icon name="eye-slash" class="size-3.5 inline mr-1" />
                        Ignorera
                    </button>
                </div>

                <div class="px-5 py-4">

                    {{-- ── MATCH MODE ─────────────────────────────────────────── --}}
                    @if ($mode === 'match')
                        <p class="text-xs text-zinc-400 mb-3">
                            Sök ett befintligt verifikat att koppla transaktionen till.
                            Transaktionsbeloppet är
                            <span class="font-mono font-semibold {{ $isIn ? 'text-green-600' : 'text-red-600' }}">
                                {{ number_format(abs($amount) / 100, 2, ',', ' ') }} kr
                            </span>.
                        </p>

                        <input type="search"
                               wire:model.live.debounce.300ms="matchSearch"
                               placeholder="Sök på text, kontonr, serie+nr…"
                               class="inp w-full text-sm mb-4" />

                        @error('match')
                            <p class="text-sm text-red-600 dark:text-red-400 mb-3">{{ $message }}</p>
                        @enderror

                        @if ($this->matchResults->isEmpty())
                            @if (strlen(trim($matchSearch)) >= 2)
                                <p class="text-sm text-zinc-400">Inga verifikat hittades.</p>
                            @else
                                <p class="text-sm text-zinc-400">
                                    Inga verifikat med exakt belopp
                                    ({{ number_format(abs($amount) / 100, 2, ',', ' ') }} kr) hittades.
                                    Sök för att hitta andra verifikat.
                                </p>
                            @endif
                        @else
                            @if (strlen(trim($matchSearch)) < 2)
                                <p class="text-xs text-zinc-400 mb-2">
                                    Visar verifikat med belopp {{ number_format(abs($amount) / 100, 2, ',', ' ') }} kr.
                                    Sök för att filtrera ytterligare.
                                </p>
                            @endif
                            <div class="space-y-1">
                                @foreach ($this->matchResults as $je)
                                    @php
                                        $jeDebit  = $je->currentLines->sum('debit');
                                        $jeCredit = $je->currentLines->sum('credit');
                                        $jeAmount = max($jeDebit, $jeCredit);
                                    @endphp
                                    <div class="flex items-center justify-between gap-3 px-3 py-2 rounded-lg border border-green-200 dark:border-green-800/40 bg-green-50/50 dark:bg-green-900/10">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-baseline gap-2 text-sm">
                                                <span class="font-mono font-semibold text-zinc-700 dark:text-zinc-200">
                                                    {{ $je->series }}{{ $je->number }}
                                                </span>
                                                <span class="text-zinc-400 text-xs">{{ $je->date?->format('d/m Y') }}</span>
                                            </div>
                                            <p class="text-xs text-zinc-500 truncate">{{ $je->description }}</p>
                                            <p class="text-xs text-zinc-400 mt-0.5">
                                                @foreach ($je->currentLines->take(3) as $line)
                                                    {{ $line->account->code ?? '?' }}
                                                    @if (! $loop->last), @endif
                                                @endforeach
                                                —
                                                <span class="font-mono">{{ number_format($jeAmount / 100, 2, ',', ' ') }} kr</span>
                                            </p>
                                        </div>
                                        <button wire:click="linkEntry({{ $je->id }})"
                                                class="btn btn-sm shrink-0">
                                            Matcha
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                    {{-- ── BOOK MODE ──────────────────────────────────────────── --}}
                    @elseif ($mode === 'book')
                        <p class="text-xs text-zinc-400 mb-4">
                            Skapa ett nytt 2-radigt verifikat kopplat till transaktionen.
                            Bankkontots redovisningskonto
                            <span class="font-mono font-semibold text-zinc-600 dark:text-zinc-300">
                                {{ $this->bankLedgerAccount?->code }} {{ $this->bankLedgerAccount?->name }}
                            </span>
                            bokförs automatiskt på
                            {{ $isIn ? 'debet (pengar in)' : 'kredit (pengar ut)' }}.
                        </p>

                        @error('book')
                            <p class="text-sm text-red-600 dark:text-red-400 mb-3">{{ $message }}</p>
                        @enderror

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="lbl">Serie</label>
                                <select wire:model="formSeries" class="inp mt-1 w-full text-sm">
                                    @foreach ($this->manualSeries as $s)
                                        <option value="{{ $s->code }}">{{ $s->code }} — {{ $s->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="lbl">Datum</label>
                                <input type="date"
                                       wire:model="formDate"
                                       class="inp mt-1 w-full text-sm" />
                                @error('formDate')
                                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label class="lbl">Beskrivning</label>
                                <input type="text"
                                       wire:model="formDescription"
                                       maxlength="500"
                                       class="inp mt-1 w-full text-sm" />
                                @error('formDescription')
                                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label class="lbl">
                                    Motkonto
                                    <span class="text-zinc-400 font-normal">
                                        ({{ $isIn ? 'kredit' : 'debet' }})
                                    </span>
                                </label>
                                <x-account-select
                                    :accounts="$this->accounts"
                                    model="otherAccountId"
                                    :live="true"
                                    class="mt-1"
                                />
                                @error('otherAccountId')
                                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label class="lbl">Kostnadsställe <span class="text-zinc-400 font-normal">(valfritt)</span></label>
                                <select wire:model="costCenterId" class="inp mt-1 w-full text-sm">
                                    <option value="">Inget</option>
                                    @foreach (App\Models\Accounting\CostCenter::where('is_active', true)->orderBy('code')->get() as $cc)
                                        <option value="{{ $cc->id }}">{{ $cc->code }} {{ $cc->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- Preview rows --}}
                        <div class="mt-4 rounded-lg border border-zinc-100 dark:border-zinc-800 overflow-hidden">
                            <table class="w-full text-xs">
                                <thead class="bg-zinc-50 dark:bg-zinc-800 text-zinc-400">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Konto</th>
                                        <th class="px-3 py-2 text-right w-28">Debet</th>
                                        <th class="px-3 py-2 text-right w-28">Kredit</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800">
                                    @php $amt = number_format(abs($amount) / 100, 2, ',', ' '); @endphp
                                    {{-- Bank row --}}
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-zinc-600 dark:text-zinc-300">
                                            {{ $this->bankLedgerAccount?->code }}
                                            <span class="text-zinc-400">{{ $this->bankLedgerAccount?->name }}</span>
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono {{ $isIn ? 'text-zinc-700 dark:text-zinc-200' : '' }}">
                                            {{ $isIn ? $amt : '' }}
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono {{ ! $isIn ? 'text-zinc-700 dark:text-zinc-200' : '' }}">
                                            {{ ! $isIn ? $amt : '' }}
                                        </td>
                                    </tr>
                                    {{-- Other account row --}}
                                    @php
                                        $other = $otherAccountId
                                            ? $this->accounts->firstWhere('id', (int) $otherAccountId)
                                            : null;
                                    @endphp
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-zinc-600 dark:text-zinc-300">
                                            @if ($other)
                                                {{ $other->code }}
                                                <span class="text-zinc-400">{{ $other->name }}</span>
                                            @else
                                                <span class="text-zinc-300 dark:text-zinc-600">Välj motkonto…</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono {{ ! $isIn ? 'text-zinc-700 dark:text-zinc-200' : 'text-zinc-300 dark:text-zinc-600' }}">
                                            {{ ! $isIn ? $amt : '' }}
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono {{ $isIn ? 'text-zinc-700 dark:text-zinc-200' : 'text-zinc-300 dark:text-zinc-600' }}">
                                            {{ $isIn ? $amt : '' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 flex justify-end">
                            <flux:button wire:click="book" variant="primary" size="sm">
                                Bokför verifikat
                            </flux:button>
                        </div>

                    {{-- ── INVOICE MATCH MODE ─────────────────────────────────── --}}
                    @elseif ($mode === 'invoice')
                        <p class="text-xs text-zinc-400 mb-3">
                            Sök en obetald faktura att koppla transaktionen till.
                            Matchningen markerar fakturan som betald och kassören aviseras.
                        </p>

                        <div class="flex gap-2 mb-3">
                            <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                                <input type="radio" wire:model.live="invoiceType"
                                       value="{{ Invoice::TYPE_RECEIVABLE }}"
                                       class="text-brand focus:ring-brand-saffron" />
                                <span class="text-zinc-600 dark:text-zinc-300">Utställda fakturor</span>
                            </label>
                            <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                                <input type="radio" wire:model.live="invoiceType"
                                       value="{{ Invoice::TYPE_PAYABLE }}"
                                       class="text-brand focus:ring-brand-saffron" />
                                <span class="text-zinc-600 dark:text-zinc-300">Leverantörsfakturor</span>
                            </label>
                        </div>

                        <input type="search"
                               wire:model.live.debounce.300ms="invoiceSearch"
                               placeholder="Sök fakturanummer, OCR, leverantör/kund…"
                               class="inp w-full text-sm mb-4" />

                        @error('invoice')
                            <p class="text-sm text-red-600 dark:text-red-400 mb-3">{{ $message }}</p>
                        @enderror

                        @if (strlen(trim($invoiceSearch)) >= 2)
                            @if ($this->invoiceResults->isEmpty())
                                <p class="text-sm text-zinc-400">Inga obetalda fakturor hittades.</p>
                            @else
                                <div class="space-y-1">
                                    @foreach ($this->invoiceResults as $inv)
                                        @php $amountMatch = $inv->total === abs($tx->amount); @endphp
                                        <div class="flex items-center justify-between gap-3 px-3 py-2 rounded-lg border
                                                    {{ $amountMatch ? 'border-violet-200 dark:border-violet-800/40 bg-violet-50/50 dark:bg-violet-900/10' : 'border-zinc-100 dark:border-zinc-800' }}">
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-baseline gap-2 text-sm">
                                                    <span class="font-semibold text-zinc-700 dark:text-zinc-200">
                                                        {{ $inv->invoice_number ?? "#{$inv->id}" }}
                                                    </span>
                                                    <span class="text-zinc-400 text-xs">
                                                        Förfall {{ $inv->due_date?->format('d/m Y') ?? '–' }}
                                                    </span>
                                                    @if ($amountMatch)
                                                        <span class="text-xs text-violet-600 dark:text-violet-400 font-medium">belopp stämmer</span>
                                                    @endif
                                                </div>
                                                <p class="text-xs text-zinc-500">
                                                    {{ $inv->vendor?->name ?? $inv->customer?->name ?? '–' }}
                                                    <span class="font-mono ml-2">{{ number_format($inv->total / 100, 2, ',', ' ') }} kr</span>
                                                    @if ($inv->ocr_number)
                                                        <span class="ml-2 text-zinc-400">OCR {{ $inv->ocr_number }}</span>
                                                    @endif
                                                </p>
                                            </div>
                                            <button wire:click="linkInvoice({{ $inv->id }})"
                                                    class="btn btn-sm shrink-0">
                                                Matcha
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @else
                            <p class="text-xs text-zinc-400">Ange minst 2 tecken för att söka.</p>
                        @endif

                    {{-- ── IGNORE MODE ────────────────────────────────────────── --}}
                    @elseif ($mode === 'ignore')
                        <p class="text-xs text-zinc-400 mb-3">
                            Markera transaktionen som ignorerad — den behöver ingen bokföring
                            (t.ex. en intern överföring som redan hanteras annorstans).
                        </p>
                        <div>
                            <label class="lbl">Anteckning <span class="text-zinc-400 font-normal">(valfritt)</span></label>
                            <input type="text"
                                   wire:model="ignoreNote"
                                   placeholder="Intern överföring, redan bokförd via…"
                                   maxlength="255"
                                   class="inp mt-1 w-full text-sm" />
                        </div>
                        <div class="mt-4 flex justify-end">
                            <flux:button wire:click="ignore" variant="ghost" size="sm"
                                         class="text-zinc-500">
                                Markera som ignorerad
                            </flux:button>
                        </div>

                    @else
                        <p class="text-sm text-zinc-400">Välj en åtgärd ovan.</p>
                    @endif

                </div>
            </div>

        {{-- ── Already ignored — show unignore option --}}
        @elseif ($tx->status === 'ignored')
            <div class="flex items-center justify-between px-4 py-3 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 text-sm">
                <span class="text-zinc-500">
                    <x-bootstrap-icon name="eye-slash" class="size-4 inline mr-1 text-zinc-400" />
                    Ignorerad
                    @if ($tx->notes) — {{ $tx->notes }} @endif
                </span>
                <button wire:click="unmatch" class="text-xs text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200">
                    Ångra — markera som omatchad
                </button>
            </div>
        @endif

    </div>
</div>
