<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\InvoiceLine;
use App\Models\Accounting\PaymentTerm;
use App\Models\Accounting\Vendor;
use App\Models\AuditLog;
use App\Services\Accounting\PostingRuleService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Ny leverantörsfaktura');

new class extends Component {

    public string $formVendorId      = '';
    public string $formInvoiceNumber = '';
    public string $formInvoiceDate   = '';
    public string $formDueDate       = '';
    public string $formPaymentTermId = '';
    public string $formPaymentMethod = '';
    public string $formNotes         = '';

    public array $lines = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $this->formInvoiceDate = today()->format('Y-m-d');

        // Default to 30 days unless a matching payment term exists
        $default = PaymentTerm::where('due_days', 30)->where('is_active', true)->first();
        if ($default) {
            $this->formPaymentTermId = (string) $default->id;
        }
        $this->formDueDate = today()->addDays(30)->format('Y-m-d');

        $this->lines = [
            ['description' => '', 'account_id' => '', 'cost_center_id' => '', 'amount_kr' => '', 'vat_rate' => '0'],
        ];
    }

    #[Computed]
    public function vendors()
    {
        return Vendor::active()->orderBy('name')
            ->get(['id', 'code', 'name', 'default_account_id', 'default_vat_rate', 'bankgiro', 'plusgiro', 'iban']);
    }

    #[Computed]
    public function paymentTerms()
    {
        return PaymentTerm::active()->orderBy('sort_order')->get(['id', 'code', 'name', 'due_days']);
    }

    #[Computed]
    public function selectedVendor(): ?Vendor
    {
        return $this->formVendorId ? Vendor::find($this->formVendorId) : null;
    }

    #[Computed]
    public function accounts()
    {
        return Account::where('is_active', true)
            ->whereIn('type', ['expense', 'asset', 'liability'])
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function costCenters()
    {
        return CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
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

            $exVat += $amountOre;
            $vat   += $vatOre;
        }

        return ['ex_vat' => $exVat, 'vat' => $vat, 'total' => $exVat + $vat];
    }

    public function updatedFormVendorId(string $value): void
    {
        if (! $value) return;

        $vendor = Vendor::find($value);
        if (! $vendor) return;

        // Check for a matching AP invoice posting rule — takes priority over vendor defaults
        $stub        = new Invoice(['vendor_id' => (int) $value, 'total' => 0, 'type' => Invoice::TYPE_PAYABLE]);
        $suggestion  = app(PostingRuleService::class)->suggestForInvoice($stub);

        if ($suggestion && ! empty($this->lines[0])) {
            $this->lines[0]['account_id']    = (string) ($suggestion->counter_account_id ?? '');
            $this->lines[0]['cost_center_id'] = (string) ($suggestion->cost_center_id ?? '');
        } elseif (! empty($this->lines[0])) {
            // Fall back to vendor defaults
            if ($vendor->default_account_id && empty($this->lines[0]['account_id'])) {
                $this->lines[0]['account_id'] = (string) $vendor->default_account_id;
            }
        }

        if (! empty($this->lines[0]) && $vendor->default_vat_rate > 0 && $this->lines[0]['vat_rate'] === '0') {
            $this->lines[0]['vat_rate'] = (string) $vendor->default_vat_rate;
        }

        // Auto-select payment method from vendor's available options
        if (empty($this->formPaymentMethod)) {
            if ($vendor->bankgiro)     { $this->formPaymentMethod = Invoice::PM_BANKGIRO; }
            elseif ($vendor->plusgiro) { $this->formPaymentMethod = Invoice::PM_PLUSGIRO; }
            elseif ($vendor->iban)     { $this->formPaymentMethod = Invoice::PM_BANK_TRANSFER; }
        }

        unset($this->selectedVendor);
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
        $this->doSave(Invoice::STATUS_DRAFT);
    }

    public function submitForApproval(): void
    {
        $this->doSave(Invoice::STATUS_PENDING_APPROVAL);
    }

    private function doSave(string $status): void
    {
        $this->validate([
            'formVendorId'      => 'required|exists:vendors,id',
            'formInvoiceDate'   => 'required|date',
            'formDueDate'       => 'nullable|date|after_or_equal:formInvoiceDate',
            'lines'             => 'required|array|min:1',
            'lines.*.account_id'=> 'required|exists:accounts,id',
            'lines.*.amount_kr' => 'required',
            'lines.*.vat_rate'  => 'required|integer|in:0,6,12,25',
        ], [
            'lines.*.account_id.required' => 'Välj konto för varje rad.',
            'lines.*.amount_kr.required'  => 'Ange belopp för varje rad.',
        ]);

        $totals = $this->totals;

        $invoice = Invoice::create([
            'type'             => Invoice::TYPE_PAYABLE,
            'vendor_id'        => $this->formVendorId,
            'invoice_number'   => $this->formInvoiceNumber ?: null,
            'invoice_date'     => $this->formInvoiceDate,
            'due_date'         => $this->formDueDate ?: null,
            'payment_term_id'  => $this->formPaymentTermId ?: null,
            'payment_method'   => $this->formPaymentMethod ?: null,
            'amount_ex_vat'    => $totals['ex_vat'],
            'vat_amount'       => $totals['vat'],
            'total'            => $totals['total'],
            'currency'         => 'SEK',
            'status'           => $status,
            'notes'            => $this->formNotes ?: null,
            'created_by'       => Auth::id(),
        ]);

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

        $action = $status === Invoice::STATUS_DRAFT ? 'invoice_draft_saved' : 'invoice_submitted_for_approval';
        AuditLog::record($action, 'accounting', "#{$invoice->id}", $invoice);

        $this->redirect(route('admin.accounting.invoices.payable.show', $invoice), navigate: true);
    }
};
?>

<div>

    <div class="grid gap-6 lg:grid-cols-[1fr_300px]">

        {{-- Main form --}}
        <div class="space-y-6">

            {{-- Header section --}}
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5">
                <h2 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-4">Fakturahuvud</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <flux:label for="invVendor">Leverantör</flux:label>
                        <flux:select id="invVendor" wire:model.live="formVendorId">
                            <option value="">Välj leverantör…</option>
                            @foreach ($this->vendors as $v)
                                <option value="{{ $v->id }}">{{ $v->code }} – {{ $v->name }}</option>
                            @endforeach
                        </flux:select>
                        @error('formVendorId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:label for="invNumber">Fakturanummer</flux:label>
                        <flux:input id="invNumber" wire:model="formInvoiceNumber"
                            placeholder="Leverantörens fakturanr" class="font-mono" />
                    </div>
                    <div>
                        {{-- spacer --}}
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
                        <flux:label for="invPayMethod">Betalsätt</flux:label>
                        <flux:select id="invPayMethod" wire:model="formPaymentMethod">
                            <option value="">–</option>
                            @if ($this->selectedVendor?->bankgiro)
                                <option value="{{ Invoice::PM_BANKGIRO }}">Bankgiro {{ $this->selectedVendor->bankgiro }}</option>
                            @endif
                            @if ($this->selectedVendor?->plusgiro)
                                <option value="{{ Invoice::PM_PLUSGIRO }}">Plusgiro {{ $this->selectedVendor->plusgiro }}</option>
                            @endif
                            @if ($this->selectedVendor?->iban)
                                <option value="{{ Invoice::PM_BANK_TRANSFER }}">Banköverföring ({{ $this->selectedVendor->iban }})</option>
                            @endif
                            @if (! $this->selectedVendor)
                                <option value="{{ Invoice::PM_BANKGIRO }}">Bankgiro</option>
                                <option value="{{ Invoice::PM_PLUSGIRO }}">Plusgiro</option>
                                <option value="{{ Invoice::PM_BANK_TRANSFER }}">Banköverföring</option>
                            @endif
                        </flux:select>
                    </div>
                </div>
            </div>

            {{-- Lines --}}
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 overflow-hidden">
                <div class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800 px-5 py-3 flex items-center justify-between">
                    <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Fakturarader</span>
                </div>

                <div class="divide-y divide-zinc-50 dark:divide-zinc-800">
                    @foreach ($this->lines as $i => $line)
                        <div class="p-4 space-y-3">
                            <div class="flex items-start gap-3">
                                <div class="flex-1 min-w-0">
                                    <flux:label>Beskrivning</flux:label>
                                    <flux:input wire:model="lines.{{ $i }}.description"
                                        placeholder="t.ex. Hyra april 2027" />
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
                                    <flux:label>Konto</flux:label>
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

        {{-- Sidebar: summary + actions --}}
        <div class="space-y-4">

            {{-- Totalsummering --}}
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

            {{-- Actions --}}
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5 space-y-3">
                <flux:button wire:click="submitForApproval" variant="primary" class="w-full">
                    Skicka för attest
                </flux:button>
                <flux:button wire:click="saveDraft" variant="ghost" class="w-full">
                    Spara som utkast
                </flux:button>
            </div>

        </div>
    </div>
</div>
