<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\Customer;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\InvoiceLine;
use App\Models\Accounting\PaymentTerm;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Ny kundfaktura');

new class extends Component {

    public string $formCustomerId    = '';
    public string $formInvoiceDate   = '';
    public string $formDueDate       = '';
    public string $formPaymentTermId = '';
    public string $formPaymentMethod = Invoice::PM_BANKGIRO;
    public string $formBankAccountId = '';
    public string $formNotes         = '';

    public array $lines = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $this->formInvoiceDate = today()->format('Y-m-d');

        $default = PaymentTerm::where('due_days', 30)->where('is_active', true)->first();
        if ($default) {
            $this->formPaymentTermId = (string) $default->id;
        }
        $this->formDueDate = today()->addDays(30)->format('Y-m-d');

        // Default bank account: prefer the one with bankgiro (Medlem)
        $ba = BankAccount::where('is_active', true)->whereNotNull('bankgiro')->first()
            ?? BankAccount::where('is_active', true)->first();
        if ($ba) {
            $this->formBankAccountId = (string) $ba->id;
            $this->formPaymentMethod = $ba->bankgiro ? Invoice::PM_BANKGIRO
                : ($ba->swish_number ? Invoice::PM_SWISH : Invoice::PM_BANK_TRANSFER);
        }

        $this->lines = [
            ['description' => '', 'account_id' => '', 'cost_center_id' => '', 'amount_kr' => '', 'vat_rate' => '0'],
        ];
    }

    #[Computed]
    public function customers()
    {
        return Customer::active()->orderBy('name')->get(['id', 'name', 'org_number']);
    }

    #[Computed]
    public function accounts()
    {
        return Account::where('is_active', true)
            ->whereIn('type', ['income', 'asset', 'liability'])
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function costCenters()
    {
        return CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function paymentTerms()
    {
        return PaymentTerm::active()->orderBy('sort_order')->get(['id', 'name', 'due_days']);
    }

    #[Computed]
    public function bankAccounts()
    {
        return BankAccount::where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'bankgiro', 'swish_number', 'iban']);
    }

    #[Computed]
    public function selectedBankAccount(): ?BankAccount
    {
        return $this->formBankAccountId ? BankAccount::find($this->formBankAccountId) : null;
    }

    #[Computed]
    public function totals(): array
    {
        $exVat = 0;
        $vat   = 0;

        foreach ($this->lines as $line) {
            $amountKr  = (float) str_replace(',', '.', $line['amount_kr'] ?? '0');
            $amountOre = (int) round($amountKr * 100);
            $vatRate   = (int) ($line['vat_rate'] ?? 0);
            $vatOre    = (int) round($amountOre * $vatRate / 100);
            $exVat    += $amountOre;
            $vat      += $vatOre;
        }

        return ['ex_vat' => $exVat, 'vat' => $vat, 'total' => $exVat + $vat];
    }

    public function updatedFormPaymentTermId(string $value): void
    {
        if (! $value || ! $this->formInvoiceDate) return;
        $term = PaymentTerm::find($value);
        if ($term) {
            $this->formDueDate = $term->dueDateFrom($this->formInvoiceDate)->format('Y-m-d');
        }
    }

    public function updatedFormInvoiceDate(string $value): void
    {
        if (! $value || ! $this->formPaymentTermId) return;
        $term = PaymentTerm::find($this->formPaymentTermId);
        if ($term) {
            $this->formDueDate = $term->dueDateFrom($value)->format('Y-m-d');
        }
    }

    public function updatedFormBankAccountId(string $value): void
    {
        $ba = $value ? BankAccount::find($value) : null;
        if ($ba) {
            // Auto-pick payment method based on what the account supports
            if ($ba->bankgiro)       $this->formPaymentMethod = Invoice::PM_BANKGIRO;
            elseif ($ba->swish_number) $this->formPaymentMethod = Invoice::PM_SWISH;
            else                       $this->formPaymentMethod = Invoice::PM_BANK_TRANSFER;
        }
        unset($this->selectedBankAccount);
    }

    public function addLine(): void
    {
        $this->lines[] = ['description' => '', 'account_id' => '', 'cost_center_id' => '', 'amount_kr' => '', 'vat_rate' => '0'];
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) <= 1) return;
        array_splice($this->lines, $index, 1);
    }

    public function saveDraft(): void
    {
        $this->validate([
            'formCustomerId'     => 'required|exists:customers,id',
            'formInvoiceDate'    => 'required|date',
            'formDueDate'        => 'nullable|date|after_or_equal:formInvoiceDate',
            'formBankAccountId'  => 'nullable|exists:bank_accounts,id',
            'lines'              => 'required|array|min:1',
            'lines.*.account_id' => 'required|exists:accounts,id',
            'lines.*.amount_kr'  => 'required',
            'lines.*.vat_rate'   => 'required|integer|in:0,6,12,25',
        ], [
            'formCustomerId.required'     => 'Välj kund.',
            'lines.*.account_id.required' => 'Välj intäktskonto för varje rad.',
            'lines.*.amount_kr.required'  => 'Ange belopp för varje rad.',
        ]);

        $totals  = $this->totals;

        $invoice = Invoice::create([
            'type'            => Invoice::TYPE_RECEIVABLE,
            'customer_id'     => $this->formCustomerId,
            'invoice_date'    => $this->formInvoiceDate,
            'due_date'        => $this->formDueDate ?: null,
            'payment_term_id' => $this->formPaymentTermId ?: null,
            'payment_method'  => $this->formPaymentMethod ?: null,
            'bank_account_id' => $this->formBankAccountId ?: null,
            'amount_ex_vat'   => $totals['ex_vat'],
            'vat_amount'      => $totals['vat'],
            'total'           => $totals['total'],
            'currency'        => 'SEK',
            'status'          => Invoice::STATUS_DRAFT,
            'notes'           => $this->formNotes ?: null,
            'created_by'      => Auth::id(),
        ]);

        // Generate OCR reference for bankgiro payment
        if ($this->formPaymentMethod === Invoice::PM_BANKGIRO) {
            $invoice->update(['ocr_number' => $invoice->generateOcr()]);
        }

        foreach ($this->lines as $i => $line) {
            $amountKr  = (float) str_replace(',', '.', $line['amount_kr'] ?? '0');
            $amountOre = (int) round($amountKr * 100);
            $vatRate   = (int) ($line['vat_rate'] ?? 0);
            $vatOre    = (int) round($amountOre * $vatRate / 100);

            InvoiceLine::create([
                'invoice_id'     => $invoice->id,
                'account_id'     => $line['account_id'],
                'cost_center_id' => $line['cost_center_id'] ?: null,
                'description'    => $line['description'] ?: '',
                'amount_ex_vat'  => $amountOre,
                'vat_rate'       => $vatRate,
                'vat_amount'     => $vatOre,
                'sort_order'     => $i,
            ]);
        }

        AuditLog::record('invoice_draft_saved', 'accounting', "#{$invoice->id}", $invoice);

        $this->redirect(route('admin.accounting.invoices.receivable.show', $invoice), navigate: true);
    }
};
?>

<div>

    <div class="grid gap-6 lg:grid-cols-[1fr_300px]">

        <div class="space-y-6">

            {{-- Header --}}
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5">
                <h2 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-4">Fakturahuvud</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <flux:label for="invCustomer">Kund</flux:label>
                        <flux:select id="invCustomer" wire:model="formCustomerId">
                            <option value="">Välj kund…</option>
                            @foreach ($this->customers as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}{{ $c->org_number ? ' (' . $c->org_number . ')' : '' }}</option>
                            @endforeach
                        </flux:select>
                        @error('formCustomerId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:label for="invDate">Fakturadatum</flux:label>
                        <flux:input id="invDate" type="date" wire:model.live="formInvoiceDate" />
                        @error('formInvoiceDate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:label for="invTerm">Betalningsvillkor</flux:label>
                        <flux:select id="invTerm" wire:model.live="formPaymentTermId">
                            <option value="">Välj villkor…</option>
                            @foreach ($this->paymentTerms as $term)
                                <option value="{{ $term->id }}">{{ $term->name }}</option>
                            @endforeach
                        </flux:select>
                    </div>
                    <div>
                        <flux:label for="invDue">Förfallodatum</flux:label>
                        <flux:input id="invDue" type="date" wire:model="formDueDate" />
                        @error('formDueDate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:label for="invBankAccount">Betalkonto (mottagare)</flux:label>
                        <flux:select id="invBankAccount" wire:model.live="formBankAccountId">
                            <option value="">–</option>
                            @foreach ($this->bankAccounts as $ba)
                                <option value="{{ $ba->id }}">{{ $ba->name }}</option>
                            @endforeach
                        </flux:select>
                    </div>
                    <div>
                        <flux:label for="invPayMethod">Betalsätt</flux:label>
                        <flux:select id="invPayMethod" wire:model="formPaymentMethod">
                            @if ($this->selectedBankAccount?->bankgiro)
                                <option value="{{ Invoice::PM_BANKGIRO }}">Bankgiro {{ $this->selectedBankAccount->bankgiro }}</option>
                            @endif
                            @if ($this->selectedBankAccount?->swish_number)
                                <option value="{{ Invoice::PM_SWISH }}">Swish {{ $this->selectedBankAccount->swish_number }}</option>
                            @endif
                            @if ($this->selectedBankAccount?->iban || ! $this->selectedBankAccount)
                                <option value="{{ Invoice::PM_BANK_TRANSFER }}">Banköverföring</option>
                            @endif
                        </flux:select>
                        @if ($formPaymentMethod === Invoice::PM_BANKGIRO)
                            <p class="mt-1 text-xs text-zinc-400">OCR-nummer genereras automatiskt vid sparande.</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Lines --}}
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 overflow-hidden">
                <div class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800 px-5 py-3">
                    <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Fakturarader</span>
                </div>

                <div class="divide-y divide-zinc-50 dark:divide-zinc-800">
                    @foreach ($this->lines as $i => $line)
                        <div class="p-4 space-y-3">
                            <div class="flex items-start gap-3">
                                <div class="flex-1 min-w-0">
                                    <flux:label>Beskrivning</flux:label>
                                    <flux:input wire:model="lines.{{ $i }}.description"
                                        placeholder="t.ex. Medlemsavgift 2027" />
                                </div>
                                @if (count($this->lines) > 1)
                                    <button wire:click="removeLine({{ $i }})" type="button"
                                        class="mt-6 text-zinc-400 hover:text-red-500 transition-colors">
                                        <x-bootstrap-icon name="trash3" class="size-4" />
                                    </button>
                                @endif
                            </div>
                            <div class="grid grid-cols-4 gap-3">
                                <div class="col-span-2">
                                    <flux:label>Intäktskonto</flux:label>
                                    <x-account-select
                                        :accounts="$this->accounts"
                                        model="lines.{{ $i }}.account_id"
                                    />
                                    @error("lines.{$i}.account_id") <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <flux:label>Kostnadsst.</flux:label>
                                    <flux:select wire:model="lines.{{ $i }}.cost_center_id">
                                        <option value="">–</option>
                                        @foreach ($this->costCenters as $cc)
                                            <option value="{{ $cc->id }}">{{ $cc->code }}</option>
                                        @endforeach
                                    </flux:select>
                                </div>
                                <div>
                                    <flux:label>Moms</flux:label>
                                    <flux:select wire:model.live="lines.{{ $i }}.vat_rate">
                                        <option value="0">0%</option>
                                        <option value="6">6%</option>
                                        <option value="12">12%</option>
                                        <option value="25">25%</option>
                                    </flux:select>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-3">
                                <div>
                                    <flux:label>Belopp ex. moms (kr)</flux:label>
                                    <flux:input wire:model.live="lines.{{ $i }}.amount_kr"
                                        placeholder="0,00" class="font-mono text-right" />
                                    @error("lines.{$i}.amount_kr") <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <flux:label>Moms (kr)</flux:label>
                                    <div class="inp mt-0 font-mono text-right text-zinc-400 bg-zinc-50 dark:bg-zinc-800 select-none">
                                        @php
                                            $amountKr = (float) str_replace(',', '.', $line['amount_kr'] ?? '0');
                                            $vat = $amountKr * (int)($line['vat_rate'] ?? 0) / 100;
                                        @endphp
                                        {{ number_format($vat, 2, ',', ' ') }}
                                    </div>
                                </div>
                                <div>
                                    <flux:label>Totalt inkl. moms</flux:label>
                                    <div class="inp mt-0 font-mono text-right font-semibold text-zinc-700 dark:text-zinc-200 bg-zinc-50 dark:bg-zinc-800 select-none">
                                        {{ number_format($amountKr + $vat, 2, ',', ' ') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="px-4 py-3 border-t border-zinc-100 dark:border-zinc-800">
                    <button wire:click="addLine" type="button"
                        class="text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 flex items-center gap-1">
                        <x-bootstrap-icon name="plus-circle" class="size-4" /> Lägg till rad
                    </button>
                </div>
            </div>

            {{-- Notes --}}
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5">
                <flux:label for="invNotes">Anteckningar (intern)</flux:label>
                <flux:textarea id="invNotes" wire:model="formNotes" rows="2" />
            </div>

        </div>

        {{-- Sidebar --}}
        <div class="space-y-4">
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5">
                <h3 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-3">Summering</h3>
                <dl class="space-y-2">
                    <div class="flex justify-between text-sm">
                        <dt class="text-zinc-500">Ex. moms</dt>
                        <dd class="font-mono font-medium">{{ format_ore($this->totals['ex_vat']) }}</dd>
                    </div>
                    <div class="flex justify-between text-sm">
                        <dt class="text-zinc-500">Moms</dt>
                        <dd class="font-mono">{{ format_ore($this->totals['vat']) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-zinc-100 dark:border-zinc-800 pt-2 text-base font-semibold">
                        <dt>Totalt</dt>
                        <dd class="font-mono">{{ format_ore($this->totals['total']) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5 space-y-3">
                <flux:button wire:click="saveDraft" variant="primary" class="w-full">
                    Spara som utkast
                </flux:button>
                <p class="text-xs text-zinc-400 text-center">Bokför och tilldela fakturanummer från fakturans sida.</p>
            </div>
        </div>
    </div>
</div>
