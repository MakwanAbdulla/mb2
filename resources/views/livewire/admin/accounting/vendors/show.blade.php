<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\PostingRule;
use App\Models\Accounting\Vendor;
use App\Models\AuditLog;
use App\Models\Membership\Member;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title(fn () => $this->vendor->name);

new class extends Component {

    public Vendor $vendor;

    public bool   $editing             = false;
    public string $formName            = '';
    public string $formCode            = '';
    public string $formType            = 'external';
    public string $formMemberId        = '';
    public string $formOrgNumber       = '';
    public string $formBankgiro        = '';
    public string $formPlusgiro        = '';
    public string $formIban            = '';
    public string $formBankClearingNumber = '';
    public string $formEmail           = '';
    public string $formPhone           = '';
    public string $formDefaultAccountId = '';
    public string $formDefaultVatRate  = '0';
    public string $formNotes           = '';

    public function mount(Vendor $vendor): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        $this->vendor = $vendor->load(['defaultAccount', 'member']);
    }

    #[Computed]
    public function invoices()
    {
        return Invoice::where('vendor_id', $this->vendor->id)
            ->where('type', 'payable')
            ->with('journalEntry')
            ->orderByDesc('invoice_date')
            ->get();
    }

    #[Computed]
    public function stats(): array
    {
        $invoices = $this->invoices;
        $active   = $invoices->whereNotIn('status', [Invoice::STATUS_CANCELLED]);
        return [
            'total'       => $active->count(),
            'total_ore'   => $active->sum('total'),
            'paid_ore'    => $active->where('status', Invoice::STATUS_PAID)->sum('total'),
            'unpaid_ore'  => $active->whereNotIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_DRAFT])->whereIn('status', [Invoice::STATUS_APPROVED, Invoice::STATUS_POSTED])->sum('total'),
            'overdue'     => $active->filter(fn ($i) => $i->isOverdue())->count(),
        ];
    }

    #[Computed]
    public function postingRules()
    {
        return PostingRule::where('vendor_id', $this->vendor->id)
            ->with(['counterAccount'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function expenseAccounts()
    {
        return Account::where('is_active', true)
            ->whereIn('type', ['expense', 'liability', 'asset'])
            ->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function members()
    {
        return Member::orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
    }

    public function startEdit(): void
    {
        $v = $this->vendor;
        $this->formName               = $v->name;
        $this->formCode               = $v->code;
        $this->formType               = $v->type;
        $this->formMemberId           = (string) ($v->member_id ?? '');
        $this->formOrgNumber          = $v->org_number ?? '';
        $this->formBankgiro           = $v->bankgiro ?? '';
        $this->formPlusgiro           = $v->plusgiro ?? '';
        $this->formIban               = $v->iban ?? '';
        $this->formBankClearingNumber = $v->bank_clearing_number ?? '';
        $this->formEmail              = $v->email ?? '';
        $this->formPhone              = $v->phone ?? '';
        $this->formDefaultAccountId   = (string) ($v->default_account_id ?? '');
        $this->formDefaultVatRate     = (string) $v->default_vat_rate;
        $this->formNotes              = $v->notes ?? '';
        $this->editing = true;
    }

    public function save(): void
    {
        $this->validate([
            'formCode'    => "required|string|max:20|unique:vendors,code,{$this->vendor->id}",
            'formName'    => 'required|string|max:255',
            'formType'    => 'required|in:external,member',
            'formMemberId'=> 'nullable|exists:members,id',
            'formEmail'   => 'nullable|email|max:255',
            'formDefaultVatRate'   => 'required|integer|in:0,6,12,25',
            'formDefaultAccountId' => 'nullable|exists:accounts,id',
        ]);

        $this->vendor->update([
            'code'                 => strtoupper($this->formCode),
            'name'                 => $this->formName,
            'type'                 => $this->formType,
            'member_id'            => $this->formMemberId ?: null,
            'org_number'           => $this->formOrgNumber ?: null,
            'bankgiro'             => $this->formBankgiro ?: null,
            'plusgiro'             => $this->formPlusgiro ?: null,
            'iban'                 => $this->formIban ?: null,
            'bank_clearing_number' => $this->formBankClearingNumber ?: null,
            'email'                => $this->formEmail ?: null,
            'phone'                => $this->formPhone ?: null,
            'default_account_id'   => $this->formDefaultAccountId ?: null,
            'default_vat_rate'     => (int) $this->formDefaultVatRate,
            'notes'                => $this->formNotes ?: null,
        ]);

        $this->vendor->refresh();
        AuditLog::record('vendor_updated', 'accounting', $this->vendor->name, $this->vendor);
        $this->editing = false;
    }
};
?>

<div>

    {{-- Back + actions --}}
    <div class="flex items-center justify-between mb-6">
        <a href="{{ route('admin.accounting.vendors.index') }}" wire:navigate
           class="flex items-center gap-1.5 text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
            <x-bootstrap-icon name="arrow-left" class="size-3.5" />
            Leverantörer
        </a>

        @if (!$editing)
            <flux:button wire:click="startEdit" size="sm" variant="ghost">
                <x-bootstrap-icon name="pencil" class="size-3.5" />
                Redigera
            </flux:button>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Left: info card --}}
        <div class="lg:col-span-1 space-y-6">

            {{-- Info --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
                <div class="px-4 py-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Uppgifter</h2>
                    <span class="inline-flex items-center gap-1 text-xs {{ $vendor->is_active ? 'text-green-700 dark:text-green-400' : 'text-zinc-400' }}">
                        <x-bootstrap-icon name="{{ $vendor->is_active ? 'check-circle-fill' : 'dash-circle' }}" class="size-3" />
                        {{ $vendor->is_active ? 'Aktiv' : 'Inaktiv' }}
                    </span>
                </div>

                @if ($editing)
                <div class="px-4 py-4">
                    @include('livewire.admin.accounting.vendors._form')
                    <div class="flex gap-2 mt-4">
                        <flux:button wire:click="save" variant="primary" size="sm">Spara</flux:button>
                        <flux:button wire:click="$set('editing', false)" variant="ghost" size="sm">Avbryt</flux:button>
                    </div>
                </div>
                @else
                @php
                    $rows = array_filter([
                        ['Kod',         $vendor->code],
                        ['Org.nr',      $vendor->org_number],
                        ['E-post',      $vendor->email],
                        ['Telefon',     $vendor->phone],
                        ['Bankgiro',    $vendor->bankgiro],
                        ['Plusgiro',    $vendor->plusgiro],
                        ['IBAN',        $vendor->iban],
                        ['Std.konto',   $vendor->defaultAccount ? $vendor->defaultAccount->code . ' ' . $vendor->defaultAccount->name : null],
                        ['Std.moms',    $vendor->default_vat_rate > 0 ? $vendor->default_vat_rate . '%' : null],
                        ['Kopplad',     $vendor->member ? $vendor->member->first_name . ' ' . $vendor->member->last_name : null],
                    ], fn($r) => !empty($r[1]));
                @endphp
                @foreach ($rows as [$label, $value])
                <div class="px-4 py-2.5 flex items-start justify-between gap-4 text-sm">
                    <span class="text-zinc-500 shrink-0">{{ $label }}</span>
                    <span class="text-zinc-800 dark:text-zinc-200 text-right font-mono text-xs">{{ $value }}</span>
                </div>
                @endforeach
                @if ($vendor->notes)
                <div class="px-4 py-3 text-sm text-zinc-500 italic border-t border-zinc-100 dark:border-zinc-800">
                    {{ $vendor->notes }}
                </div>
                @endif
                @endif
            </div>

            {{-- Stats --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
                <div class="px-4 py-3">
                    <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Statistik</h2>
                </div>
                @php
                    $s = $this->stats;
                    $fmt = fn(int $ore) => number_format($ore / 100, 2, ',', ' ') . ' kr';
                @endphp
                <div class="px-4 py-2.5 flex justify-between text-sm">
                    <span class="text-zinc-500">Fakturor totalt</span>
                    <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $s['total'] }} st</span>
                </div>
                <div class="px-4 py-2.5 flex justify-between text-sm">
                    <span class="text-zinc-500">Totalt belopp</span>
                    <span class="font-mono text-zinc-800 dark:text-zinc-200">{{ $fmt($s['total_ore']) }}</span>
                </div>
                <div class="px-4 py-2.5 flex justify-between text-sm">
                    <span class="text-zinc-500">Betalt</span>
                    <span class="font-mono text-green-700 dark:text-green-400">{{ $fmt($s['paid_ore']) }}</span>
                </div>
                @if ($s['unpaid_ore'] > 0)
                <div class="px-4 py-2.5 flex justify-between text-sm">
                    <span class="text-zinc-500">Obetalt</span>
                    <span class="font-mono text-amber-600 dark:text-amber-400">{{ $fmt($s['unpaid_ore']) }}</span>
                </div>
                @endif
                @if ($s['overdue'] > 0)
                <div class="px-4 py-2.5 flex justify-between text-sm">
                    <span class="text-zinc-500">Förfallna</span>
                    <span class="font-medium text-red-600 dark:text-red-400">{{ $s['overdue'] }} st</span>
                </div>
                @endif
            </div>

        </div>

        {{-- Right: invoice list --}}
        <div class="lg:col-span-2">
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                <div class="px-4 py-3 bg-zinc-50 dark:bg-zinc-800 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Fakturor</h2>
                    <a href="{{ route('admin.accounting.invoices.payable.create') }}" wire:navigate>
                        <flux:button size="sm" variant="ghost">
                            <x-bootstrap-icon name="plus-lg" class="size-3.5" />
                            Ny faktura
                        </flux:button>
                    </a>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider border-t border-zinc-200 dark:border-zinc-700">
                        <tr>
                            <th class="px-4 py-2.5 w-32">Fakturanr</th>
                            <th class="px-4 py-2.5 w-24">Datum</th>
                            <th class="px-4 py-2.5 w-24">Förfaller</th>
                            <th class="px-4 py-2.5 text-right">Belopp</th>
                            <th class="px-4 py-2.5 w-28">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                        @forelse ($this->invoices as $inv)
                            @php
                                $statusCls = match($inv->status) {
                                    'paid'       => 'text-green-700 dark:text-green-400',
                                    'cancelled'  => 'text-zinc-400 line-through',
                                    'approved', 'posted' => $inv->isOverdue() ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400',
                                    default      => 'text-zinc-400',
                                };
                                $statusLabel = match($inv->status) {
                                    'draft'            => 'Utkast',
                                    'pending_approval' => 'Väntar',
                                    'approved'         => $inv->isOverdue() ? 'Förfallen' : 'Godkänd',
                                    'posted'           => $inv->isOverdue() ? 'Förfallen' : 'Bokförd',
                                    'paid'             => 'Betald',
                                    'cancelled'        => 'Makulerad',
                                    default            => $inv->status,
                                };
                            @endphp
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('admin.accounting.invoices.payable.show', $inv) }}" wire:navigate
                                       class="font-mono font-medium text-zinc-700 dark:text-zinc-300 hover:text-brand-dark dark:hover:text-brand">
                                        {{ $inv->invoice_number ?? '#' . $inv->id }}
                                    </a>
                                </td>
                                <td class="px-4 py-2.5 text-zinc-500 text-xs font-mono">
                                    {{ $inv->invoice_date?->format('d/m Y') ?? '–' }}
                                </td>
                                <td class="px-4 py-2.5 text-xs font-mono {{ $inv->isOverdue() ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-zinc-500' }}">
                                    {{ $inv->due_date?->format('d/m Y') ?? '–' }}
                                </td>
                                <td class="px-4 py-2.5 text-right font-mono text-xs tabular-nums text-zinc-700 dark:text-zinc-300">
                                    {{ number_format($inv->total / 100, 2, ',', ' ') }} kr
                                </td>
                                <td class="px-4 py-2.5 text-xs {{ $statusCls }}">
                                    {{ $statusLabel }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-sm text-zinc-400">
                                    Inga fakturor registrerade.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Posting rules --}}
        <div class="mt-6 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <div class="px-4 py-3 bg-zinc-50 dark:bg-zinc-800 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Bokföringsregler</h2>
                <a href="{{ route('admin.accounting.posting-rules.index') }}?from_vendor={{ $vendor->id }}" wire:navigate>
                    <flux:button size="sm" variant="ghost">
                        <x-bootstrap-icon name="plus-lg" class="size-3.5" />
                        Ny regel
                    </flux:button>
                </a>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider border-t border-zinc-200 dark:border-zinc-700">
                    <tr>
                        <th class="px-4 py-2.5">Namn</th>
                        <th class="px-4 py-2.5 w-32">Trigger</th>
                        <th class="px-4 py-2.5 w-40">Nyckelord</th>
                        <th class="px-4 py-2.5 w-40">Motkonto</th>
                        <th class="px-4 py-2.5 w-20 text-center">Status</th>
                        <th class="px-4 py-2.5 w-16"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                    @forelse ($this->postingRules as $rule)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ $rule->is_active ? '' : 'opacity-50' }}">
                            <td class="px-4 py-2.5 font-medium text-zinc-800 dark:text-zinc-200">
                                {{ $rule->name }}
                            </td>
                            <td class="px-4 py-2.5 text-xs text-zinc-500">
                                {{ match($rule->trigger) {
                                    'bank_transaction' => 'Banktransaktion',
                                    'invoice'          => 'Faktura',
                                    'inbox'            => 'Fakturainkorg',
                                    default            => $rule->trigger,
                                } }}
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-zinc-500">
                                {{ $rule->keyword ?? '–' }}
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs">
                                @if ($rule->counterAccount)
                                    <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $rule->counterAccount->code }}</span>
                                    <span class="text-zinc-400 ml-1">{{ $rule->counterAccount->name }}</span>
                                @else
                                    <span class="text-zinc-300 dark:text-zinc-600">–</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                @if ($rule->is_active)
                                    <span class="inline-flex items-center gap-1 text-xs text-green-700 dark:text-green-400">
                                        <x-bootstrap-icon name="check-circle-fill" class="size-3" /> Aktiv
                                    </span>
                                @else
                                    <span class="text-xs text-zinc-400">Inaktiv</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <a href="{{ route('admin.accounting.posting-rules.index') }}?from_vendor={{ $vendor->id }}&edit_rule={{ $rule->id }}"
                                   wire:navigate
                                   class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                    Redigera
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-zinc-400">
                                Inga bokföringsregler kopplade till denna leverantör.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        </div>

    </div>

</div>
