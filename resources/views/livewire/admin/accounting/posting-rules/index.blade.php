<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\JournalSeries;
use App\Models\Accounting\PostingRule;
use App\Models\Accounting\Vendor;
use App\Models\AuditLog;
use App\Services\Accounting\PostingRuleService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Bokföringsregler');

new class extends Component {

    public bool $showCreateModal = false;
    public bool $showEditModal   = false;
    public ?int $editingId       = null;

    /** Vendor context — set when opened from a vendor page via ?from_vendor */
    public ?int    $fromVendorId   = null;
    public string  $fromVendorName = '';

    // Result of last "run rules" action
    public ?int   $lastPosted  = null;
    public ?int   $lastSkipped = null;
    public array  $lastErrors  = [];

    // Form fields
    public string  $formTrigger            = PostingRule::TRIGGER_BANK_TRANSACTION;
    public string  $formName              = '';
    public string  $formVendorId          = '';
    public string  $formFromEmailPattern  = '';
    public string  $formBankAccountId     = '';
    public string  $formTransactionType = 'any';
    public string  $formAmountMin       = '';
    public string  $formAmountMax       = '';
    public string  $formKeyword         = '';
    public string  $formValidFrom       = '';
    public string  $formValidTo         = '';
    public string  $formDayFrom         = '';
    public string  $formDayTo           = '';
    public string  $formCounterAccountId = '';
    public string  $formDebitAccountId  = '';
    public string  $formCreditAccountId = '';
    public string  $formSeries          = 'U';
    public string  $formCostCenterId    = '';
    public bool    $formIsActive         = true;
    public bool    $formRequiresReceipt = false;
    public string  $formSortOrder       = '0';

    /** Candidate bank transactions suggested when creating a rule from a vendor. */
    public array $vendorCandidates = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        if ($txId = request()->integer('from_tx')) {
            $tx = BankTransaction::with(['bankAccount', 'journalEntry.currentLines'])->find($txId);
            if ($tx) {
                $this->prefillFromTransaction($tx);
                $this->showCreateModal = true;
            }
        }

        if ($vendorId = request()->integer('from_vendor')) {
            $vendor = Vendor::find($vendorId);
            if ($vendor) {
                $this->fromVendorId   = $vendor->id;
                $this->fromVendorName = $vendor->name;

                if ($editRuleId = request()->integer('edit_rule')) {
                    $this->openEdit($editRuleId);
                } else {
                    $this->prefillFromVendor($vendor);
                    $this->showCreateModal = true;
                }
            }
        }
    }

    private function prefillFromVendor(Vendor $vendor): void
    {
        $this->formVendorId = (string) $vendor->id;
        $this->formName     = $vendor->name;
        if ($vendor->default_account_id) {
            $this->formCounterAccountId = (string) $vendor->default_account_id;
        }

        // Search for unmatched bank transactions that likely belong to this vendor
        $keyword = strtolower($vendor->name);
        $this->vendorCandidates = BankTransaction::whereNull('journal_entry_id')
            ->whereNull('corporate_card_id')
            ->where(fn ($q) => $q
                ->whereRaw('LOWER(description) LIKE ?', ["%{$keyword}%"])
                ->orWhereRaw('LOWER(reference) LIKE ?', ["%{$keyword}%"])
            )
            ->with('bankAccount')
            ->orderByDesc('booking_date')
            ->limit(5)
            ->get()
            ->map(fn ($tx) => [
                'id'           => $tx->id,
                'date'         => $tx->booking_date?->format('Y-m-d') ?? $tx->transaction_date?->format('Y-m-d'),
                'amount'       => $tx->amount,
                'description'  => $tx->description,
                'reference'    => $tx->reference,
                'bank_account' => $tx->bankAccount?->name,
            ])
            ->all();
    }

    public function useTransactionAsBase(int $txId): void
    {
        $tx = BankTransaction::with(['bankAccount', 'journalEntry.currentLines'])->findOrFail($txId);
        $this->prefillFromTransaction($tx);
        $this->vendorCandidates = [];
    }

    private function prefillFromTransaction(BankTransaction $tx): void
    {
        $this->formTrigger         = PostingRule::TRIGGER_BANK_TRANSACTION;
        $this->formBankAccountId   = (string) ($tx->bank_account_id ?? '');
        $this->formTransactionType = $tx->amount > 0 ? 'credit' : ($tx->amount < 0 ? 'debit' : 'any');
        $this->formKeyword = implode(' ', array_filter([$tx->reference, $tx->description]));

        $autoSeries = JournalSeries::where('default_for', 'auto')->first();
        if ($autoSeries) {
            $this->formSeries = $autoSeries->code;
        }

        // If already booked: pick up the counter account and series from the journal entry
        if ($tx->journalEntry) {
            $this->formSeries = $tx->journalEntry->series;
            $bankAccountId    = $tx->bankAccount?->account_id;
            $otherLine = $tx->journalEntry->currentLines
                ->first(fn($l) => $l->account_id !== $bankAccountId);
            if ($otherLine) {
                $this->formCounterAccountId = (string) $otherLine->account_id;
            }
        }
    }

    #[Computed]
    public function rules()
    {
        return PostingRule::with(['bankAccount', 'vendor', 'counterAccount', 'debitAccount', 'creditAccount', 'costCenter'])
            ->orderBy('trigger')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function vendors()
    {
        return Vendor::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function bankAccounts()
    {
        return BankAccount::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function accounts()
    {
        return Account::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function series()
    {
        return JournalSeries::orderBy('code')->get(['code', 'name']);
    }

    #[Computed]
    public function costCenters()
    {
        return CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showCreateModal = true;
    }

    public function openEdit(int $id): void
    {
        $rule = PostingRule::findOrFail($id);

        $this->editingId             = $id;
        $this->formTrigger           = $rule->trigger;
        $this->formName              = $rule->name;
        $this->formVendorId          = (string) ($rule->vendor_id ?? '');
        $this->formFromEmailPattern  = $rule->from_email_pattern ?? '';
        $this->formBankAccountId     = (string) ($rule->bank_account_id ?? '');
        $this->formTransactionType   = $rule->transaction_type ?? 'any';
        $this->formAmountMin         = $rule->amount_min_ore !== null ? (string) ($rule->amount_min_ore / 100) : '';
        $this->formAmountMax         = $rule->amount_max_ore !== null ? (string) ($rule->amount_max_ore / 100) : '';
        $this->formKeyword           = $rule->keyword ?? '';
        $this->formValidFrom         = $rule->valid_from?->toDateString() ?? '';
        $this->formValidTo           = $rule->valid_to?->toDateString() ?? '';
        $this->formDayFrom           = (string) ($rule->day_from ?? '');
        $this->formDayTo             = (string) ($rule->day_to ?? '');
        $this->formCounterAccountId  = (string) ($rule->counter_account_id ?? '');
        $this->formDebitAccountId    = (string) ($rule->debit_account_id ?? '');
        $this->formCreditAccountId   = (string) ($rule->credit_account_id ?? '');
        $this->formSeries            = $rule->series ?? 'U';
        $this->formCostCenterId      = (string) ($rule->cost_center_id ?? '');
        $this->formIsActive          = $rule->is_active;
        $this->formRequiresReceipt   = $rule->requires_receipt;
        $this->formSortOrder         = (string) ($rule->sort_order ?? 0);

        $this->showEditModal = true;
    }

    public function save(): void
    {
        $this->validate($this->validationRules());

        $rule = PostingRule::create($this->buildData() + ['is_active' => true]);
        AuditLog::record('posting_rule_created', 'accounting', $rule->name, $rule);

        $this->showCreateModal = false;
        $this->resetForm();
        unset($this->rules);
    }

    public function update(): void
    {
        $rule = PostingRule::findOrFail($this->editingId);
        $this->validate($this->validationRules());

        $rule->update($this->buildData() + ['is_active' => $this->formIsActive]);
        AuditLog::record('posting_rule_updated', 'accounting', $rule->name, $rule);

        $this->showEditModal = false;
        $this->resetForm();
        unset($this->rules);
    }

    public function activate(int $id): void
    {
        $rule = PostingRule::findOrFail($id);
        $rule->update(['is_active' => true]);
        AuditLog::record('posting_rule_activated', 'accounting', $rule->name, $rule);
        unset($this->rules);
    }

    public function deactivate(int $id): void
    {
        $rule = PostingRule::findOrFail($id);
        $rule->update(['is_active' => false]);
        AuditLog::record('posting_rule_deactivated', 'accounting', $rule->name, $rule);
        unset($this->rules);
    }

    public function delete(int $id): void
    {
        $rule = PostingRule::findOrFail($id);
        $name = $rule->name;
        $rule->delete();
        AuditLog::record('posting_rule_deleted', 'accounting', $name, null);
        unset($this->rules);
    }

    public function runRules(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $result = app(PostingRuleService::class)->applyAll();

        $this->lastPosted  = $result['posted'];
        $this->lastSkipped = $result['skipped'];
        $this->lastErrors  = $result['errors'];

        AuditLog::record('posting_rules_run', 'accounting', "posted={$result['posted']} skipped={$result['skipped']}", null);

        unset($this->rules);
    }

    private function buildData(): array
    {
        $isBankTrigger  = $this->formTrigger === PostingRule::TRIGGER_BANK_TRANSACTION;
        $isInboxTrigger = $this->formTrigger === PostingRule::TRIGGER_INBOX_EMAIL;

        return [
            'trigger'             => $this->formTrigger,
            'name'                => $this->formName,
            // vendor_id: for ap_invoice = matching filter; for bank_transaction = tagging action
            'vendor_id'           => in_array($this->formTrigger, [PostingRule::TRIGGER_AP_INVOICE, PostingRule::TRIGGER_BANK_TRANSACTION]) && $this->formVendorId ? (int) $this->formVendorId : null,
            'from_email_pattern'  => $isInboxTrigger && $this->formFromEmailPattern !== '' ? $this->formFromEmailPattern : null,
            'bank_account_id'     => $isBankTrigger && $this->formBankAccountId ? (int) $this->formBankAccountId : null,
            'transaction_type'    => $isBankTrigger ? $this->formTransactionType : null,
            'amount_min_ore'      => $this->formAmountMin !== '' ? (int) round((float) $this->formAmountMin * 100) : null,
            'amount_max_ore'      => $this->formAmountMax !== '' ? (int) round((float) $this->formAmountMax * 100) : null,
            'keyword'             => $this->formKeyword ?: null,
            'valid_from'          => $isBankTrigger && $this->formValidFrom ? $this->formValidFrom : null,
            'valid_to'            => $isBankTrigger && $this->formValidTo ? $this->formValidTo : null,
            'day_from'            => $isBankTrigger && $this->formDayFrom !== '' ? (int) $this->formDayFrom : null,
            'day_to'              => $isBankTrigger && $this->formDayTo !== '' ? (int) $this->formDayTo : null,
            // bank/ap: counter_account_id; inbox: debit + credit pair
            'counter_account_id'  => ! $isInboxTrigger && $this->formCounterAccountId ? (int) $this->formCounterAccountId : null,
            'debit_account_id'    => $isInboxTrigger && $this->formDebitAccountId ? (int) $this->formDebitAccountId : null,
            'credit_account_id'   => $isInboxTrigger && $this->formCreditAccountId ? (int) $this->formCreditAccountId : null,
            'series'              => ($isBankTrigger || $isInboxTrigger) ? $this->formSeries : null,
            'cost_center_id'      => $this->formCostCenterId ?: null,
            'requires_receipt'    => $this->formRequiresReceipt,
            'sort_order'          => (int) $this->formSortOrder,
        ];
    }

    private function validationRules(): array
    {
        $isBankTrigger  = $this->formTrigger === PostingRule::TRIGGER_BANK_TRANSACTION;
        $isInboxTrigger = $this->formTrigger === PostingRule::TRIGGER_INBOX_EMAIL;

        return [
            'formTrigger'          => 'required|in:bank_transaction,ap_invoice,inbox_email',
            'formName'             => 'required|string|max:100',
            'formVendorId'         => 'nullable|exists:vendors,id',
            'formFromEmailPattern' => 'nullable|string|max:255',
            'formBankAccountId'    => 'nullable|exists:bank_accounts,id',
            'formTransactionType'  => $isBankTrigger ? 'required|in:credit,debit,any' : 'nullable',
            'formAmountMin'        => 'nullable|numeric|min:0',
            'formAmountMax'        => 'nullable|numeric|min:0',
            'formKeyword'          => 'nullable|string|max:200',
            'formValidFrom'        => 'nullable|date',
            'formValidTo'          => 'nullable|date|after_or_equal:formValidFrom',
            'formDayFrom'          => 'nullable|integer|min:1|max:31',
            'formDayTo'            => 'nullable|integer|min:1|max:31',
            'formCounterAccountId' => $isInboxTrigger ? 'nullable' : 'required|exists:accounts,id',
            'formDebitAccountId'   => $isInboxTrigger ? 'required|exists:accounts,id' : 'nullable',
            'formCreditAccountId'  => $isInboxTrigger ? 'required|exists:accounts,id' : 'nullable',
            'formSeries'           => ($isBankTrigger || $isInboxTrigger) ? 'required|exists:journal_series,code' : 'nullable',
            'formCostCenterId'     => 'nullable|exists:cost_centers,id',
            'formSortOrder'        => 'nullable|integer|min:0',
        ];
    }

    private function resetForm(): void
    {
        $this->editingId             = null;
        $this->formTrigger           = PostingRule::TRIGGER_BANK_TRANSACTION;
        $this->formName              = '';
        $this->formVendorId          = '';
        $this->formFromEmailPattern  = '';
        $this->formBankAccountId     = '';
        $this->formTransactionType   = 'any';
        $this->formAmountMin         = '';
        $this->formAmountMax         = '';
        $this->formKeyword           = '';
        $this->formValidFrom         = '';
        $this->formValidTo           = '';
        $this->formDayFrom           = '';
        $this->formDayTo             = '';
        $this->formCounterAccountId  = '';
        $this->formDebitAccountId    = '';
        $this->formCreditAccountId   = '';
        $this->formSeries            = 'U';
        $this->formCostCenterId      = '';
        $this->formIsActive          = true;
        $this->formRequiresReceipt   = false;
        $this->formSortOrder         = '0';
        $this->resetValidation();
    }
};
?>

<div>

    {{-- Back to vendor --}}
    @if ($fromVendorId)
        <div class="mb-4">
            <a href="{{ route('admin.accounting.vendors.show', $fromVendorId) }}" wire:navigate
               class="flex items-center gap-1.5 text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                <x-bootstrap-icon name="arrow-left" class="size-3.5" />
                {{ $fromVendorName }}
            </a>
        </div>
    @endif

    {{-- Action bar --}}
    <div class="mb-4 flex items-center justify-between gap-3">
        <flux:button wire:click="runRules" variant="ghost" size="sm"
            wire:confirm="Kör alla aktiva regler mot omatchade transaktioner?">
            <x-bootstrap-icon name="play-circle" class="size-3.5" />
            Kör regler nu
        </flux:button>
        <flux:button wire:click="openCreate" variant="primary" size="sm">
            Ny regel
        </flux:button>
    </div>

    {{-- Result banner --}}
    @if ($lastPosted !== null)
        <div @class([
            'mb-4 rounded-lg border px-4 py-3 text-sm',
            'border-green-200 bg-green-50 text-green-800 dark:border-green-700/40 dark:bg-green-900/20 dark:text-green-300' => $lastPosted > 0,
            'border-zinc-200 bg-zinc-50 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-400' => $lastPosted === 0,
        ])>
            <div class="flex items-center gap-2">
                @if ($lastPosted > 0)
                    <x-bootstrap-icon name="check-circle-fill" class="size-4 shrink-0" />
                    {{ $lastPosted }} {{ $lastPosted === 1 ? 'transaktion bokförd' : 'transaktioner bokförda' }} automatiskt.
                @else
                    <x-bootstrap-icon name="info-circle" class="size-4 shrink-0" />
                    Inga nya transaktioner matchade någon regel.
                @endif
                @if ($lastSkipped > 0)
                    <span class="text-zinc-400 dark:text-zinc-500">
                        ({{ $lastSkipped }} omatchade transaktioner kvar)
                    </span>
                @endif
            </div>
            @if (count($lastErrors))
                <ul class="mt-2 list-disc pl-5 text-xs text-red-700 dark:text-red-400">
                    @foreach ($lastErrors as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- Rules table --}}
    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3">Regelnamn</th>
                    <th class="px-4 py-3 w-36">Trigger / Match</th>
                    <th class="px-4 py-3 w-36">Belopp (SEK)</th>
                    <th class="px-4 py-3 w-44">Nyckelord</th>
                    <th class="px-4 py-3 w-40">Konto</th>
                    <th class="px-4 py-3 w-24 text-center">Status</th>
                    <th class="px-4 py-3 w-32"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->rules as $rule)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ $rule->is_active ? '' : 'opacity-60' }}">
                        <td class="px-4 py-3">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ $rule->name }}</div>
                            <div class="text-xs text-zinc-400 mt-0.5">Prioritet {{ $rule->sort_order }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @if ($rule->trigger === 'bank_transaction')
                                <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                                    Banktransaktion
                                </span>
                                <div class="mt-1">
                                    <span @class([
                                        'inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium',
                                        'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' => $rule->transaction_type === 'credit',
                                        'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' => $rule->transaction_type === 'debit',
                                        'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300' => $rule->transaction_type === 'any' || ! $rule->transaction_type,
                                    ])>
                                        {{ $rule->transactionTypeLabel() }}
                                    </span>
                                </div>
                                @if ($rule->bankAccount)
                                    <div class="text-xs text-zinc-400 mt-0.5">{{ $rule->bankAccount->name }}</div>
                                @endif
                                @if ($rule->vendor)
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5 flex items-center gap-1">
                                        <x-bootstrap-icon name="building" class="size-3" />
                                        {{ $rule->vendor->name }}
                                    </div>
                                @endif
                            @elseif ($rule->trigger === 'inbox_email')
                                <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-400">
                                    Fakturainkorg
                                </span>
                                @if ($rule->from_email_pattern)
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5 font-mono">{{ $rule->from_email_pattern }}</div>
                                @endif
                                @if ($rule->keyword)
                                    <div class="text-xs text-zinc-400 mt-0.5 italic">"{{ $rule->keyword }}"</div>
                                @endif
                            @else
                                <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                    Lev.faktura
                                </span>
                                @if ($rule->vendor)
                                    <div class="text-xs text-zinc-600 dark:text-zinc-300 mt-0.5">{{ $rule->vendor->name }}</div>
                                @else
                                    <div class="text-xs text-zinc-400 mt-0.5">Alla leverantörer</div>
                                @endif
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-300">
                            @if ($rule->amount_min_ore !== null || $rule->amount_max_ore !== null)
                                {{ $rule->amount_min_ore !== null ? number_format($rule->amount_min_ore / 100, 0, ',', ' ') : '0' }}
                                –
                                {{ $rule->amount_max_ore !== null ? number_format($rule->amount_max_ore / 100, 0, ',', ' ') : '∞' }}
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600">Alla belopp</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300 max-w-[176px]">
                            @if ($rule->keyword)
                                <span class="font-mono bg-zinc-100 dark:bg-zinc-700 rounded px-1 py-0.5 truncate block">{{ $rule->keyword }}</span>
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600">–</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            @if ($rule->counterAccount)
                                <span class="font-semibold">{{ $rule->counterAccount->code }}</span>
                                <span class="text-zinc-400 block truncate max-w-[10rem]">{{ $rule->counterAccount->name }}</span>
                                @if ($rule->costCenter)
                                    <span class="text-zinc-300 dark:text-zinc-500 block">{{ $rule->costCenter->code }}</span>
                                @endif
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600">–</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($rule->is_active)
                                <span class="inline-flex items-center gap-1 text-xs text-green-700 dark:text-green-400">
                                    <x-bootstrap-icon name="check-circle-fill" class="size-3.5" /> Aktiv
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs text-zinc-400">
                                    <x-bootstrap-icon name="dash-circle" class="size-3.5" /> Inaktiv
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if ($rule->is_active)
                                    <button wire:click="deactivate({{ $rule->id }})"
                                        class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                        Inaktivera
                                    </button>
                                @else
                                    <button wire:click="activate({{ $rule->id }})"
                                        class="text-xs text-green-600 hover:text-green-700">
                                        Aktivera
                                    </button>
                                @endif
                                <button wire:click="openEdit({{ $rule->id }})"
                                    class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                    Redigera
                                </button>
                                <button wire:click="delete({{ $rule->id }})"
                                    wire:confirm="Ta bort regeln '{{ $rule->name }}'?"
                                    class="text-xs text-red-500 hover:text-red-700">
                                    Ta bort
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-sm text-zinc-400">
                            Inga bokföringsregler skapade ännu.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Explanatory note --}}
    <div class="mt-4 rounded-lg border border-zinc-100 dark:border-zinc-800 px-4 py-3 text-xs text-zinc-400 dark:text-zinc-500 space-y-2">
        <p class="font-medium text-zinc-500 dark:text-zinc-400">Hur reglerna fungerar</p>
        <p><strong class="text-zinc-500">Banktransaktion:</strong> Prövas mot omatchade banktransaktioner vid import eller via "Kör regler nu". Skapar ett verifikat automatiskt.</p>
        <p><strong class="text-zinc-500">Leverantörsfaktura:</strong> Prövas när en leverantör väljs på en ny faktura. Förfyller kontoraden automatiskt — du granskar och sparar sedan manuellt.</p>
        <p><strong class="text-zinc-500">Fakturainkorg:</strong> Prövas när ett mail importeras från inkorgen. Matchar på avsändaradress och/eller nyckelord i ämnesraden. Föreslår kontering vid promote.</p>
    </div>

    {{-- Create modal --}}
    <flux:modal wire:model="showCreateModal" class="max-w-2xl">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-5">Ny bokföringsregel</h2>

            {{-- Candidate bank transactions from vendor lookup --}}
            @if (count($vendorCandidates) > 0)
                <div class="mb-5 rounded-lg border border-brand/40 bg-brand/5 dark:bg-brand/10 p-3">
                    <p class="text-xs font-medium text-zinc-600 dark:text-zinc-300 mb-2 flex items-center gap-1.5">
                        <x-bootstrap-icon name="search" class="size-3.5" />
                        Troliga banktransaktioner — välj en som bas för regeln
                    </p>
                    <div class="space-y-1.5">
                        @foreach ($vendorCandidates as $c)
                            <button wire:click="useTransactionAsBase({{ $c['id'] }})"
                                    class="w-full text-left rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-700/60 px-3 py-2 text-xs transition-colors">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <span class="font-mono text-zinc-400">{{ $c['date'] }}</span>
                                        <span class="ml-2 text-zinc-700 dark:text-zinc-200 truncate">{{ $c['description'] }}</span>
                                        @if ($c['reference'])
                                            <span class="ml-1 text-zinc-400 font-mono">· {{ $c['reference'] }}</span>
                                        @endif
                                        <span class="ml-2 text-zinc-400 text-[11px]">{{ $c['bank_account'] }}</span>
                                    </div>
                                    <span class="shrink-0 font-mono font-semibold tabular-nums {{ $c['amount'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">
                                        {{ number_format(abs($c['amount']) / 100, 2, ',', ' ') }} kr
                                    </span>
                                </div>
                            </button>
                        @endforeach
                    </div>
                    <button wire:click="$set('vendorCandidates', [])"
                            class="mt-2 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                        Hoppa över, fyll i manuellt →
                    </button>
                </div>
            @endif

            @include('livewire.admin.accounting.posting-rules._form')
            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showCreateModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="save" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit modal --}}
    <flux:modal wire:model="showEditModal" class="max-w-2xl">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-5">Redigera regel</h2>
            @include('livewire.admin.accounting.posting-rules._form')
            <div class="mt-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" wire:model="formIsActive"
                           class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Aktiv</span>
                </label>
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showEditModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="update" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
