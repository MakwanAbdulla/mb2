<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\Customer;
use App\Models\Accounting\Invoice;
use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Models\Shop\Order;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title(fn () => $this->customer->name);

new class extends Component {

    public Customer $customer;

    public bool   $editing             = false;
    public string $formName            = '';
    public string $formOrgNumber       = '';
    public string $formEmail           = '';
    public string $formMemberId        = '';
    public string $formDefaultAccountId = '';

    public function mount(Customer $customer): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        $this->customer = $customer->load(['defaultAccount', 'member']);
    }

    #[Computed]
    public function invoices()
    {
        return Invoice::where('customer_id', $this->customer->id)
            ->where('type', 'receivable')
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
            'total'      => $active->count(),
            'total_ore'  => $active->sum('total'),
            'paid_ore'   => $active->where('status', Invoice::STATUS_PAID)->sum('total'),
            'unpaid_ore' => $active->whereIn('status', [Invoice::STATUS_APPROVED, Invoice::STATUS_POSTED])->sum('total'),
            'overdue'    => $active->filter(fn ($i) => $i->isOverdue())->count(),
        ];
    }

    #[Computed]
    public function orders()
    {
        return Order::where('customer_id', $this->customer->id)
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function incomeAccounts()
    {
        return Account::where('is_active', true)
            ->whereIn('type', ['income', 'asset'])
            ->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function members()
    {
        return Member::orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
    }

    public function startEdit(): void
    {
        $c = $this->customer;
        $this->formName             = $c->name;
        $this->formOrgNumber        = $c->org_number ?? '';
        $this->formEmail            = $c->email ?? '';
        $this->formMemberId         = (string) ($c->member_id ?? '');
        $this->formDefaultAccountId = (string) ($c->default_account_id ?? '');
        $this->editing = true;
    }

    public function save(): void
    {
        $this->validate([
            'formName'             => 'required|string|max:255',
            'formOrgNumber'        => 'nullable|string|max:20',
            'formEmail'            => 'nullable|email|max:255',
            'formMemberId'         => 'nullable|exists:members,id',
            'formDefaultAccountId' => 'nullable|exists:accounts,id',
        ]);

        $this->customer->update([
            'name'               => $this->formName,
            'org_number'         => $this->formOrgNumber ?: null,
            'email'              => $this->formEmail ?: null,
            'member_id'          => $this->formMemberId ?: null,
            'default_account_id' => $this->formDefaultAccountId ?: null,
        ]);

        $this->customer->refresh();

        AuditLog::record('customer_updated', 'accounting', $this->customer->name, $this->customer);

        $this->editing = false;
    }
};
?>

<div>

    {{-- Back + actions --}}
    <div class="flex items-center justify-between mb-6">
        <a href="{{ request()->routeIs('admin.shop.*') ? route('admin.shop.customers.index') : route('admin.accounting.customers.index') }}" wire:navigate
           class="flex items-center gap-1.5 text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
            <x-bootstrap-icon name="arrow-left" class="size-3.5" />
            Kunder
        </a>
        @if (!$editing)
            <flux:button wire:click="startEdit" size="sm" variant="ghost">
                <x-bootstrap-icon name="pencil" class="size-3.5" />
                Redigera
            </flux:button>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Left: info + stats --}}
        <div class="lg:col-span-1 space-y-6">

            {{-- Info --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
                <div class="px-4 py-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Uppgifter</h2>
                    <span class="inline-flex items-center gap-1 text-xs {{ $customer->is_active ? 'text-green-700 dark:text-green-400' : 'text-zinc-400' }}">
                        <x-bootstrap-icon name="{{ $customer->is_active ? 'check-circle-fill' : 'dash-circle' }}" class="size-3" />
                        {{ $customer->is_active ? 'Aktiv' : 'Inaktiv' }}
                    </span>
                </div>

                @if ($editing)
                <div class="px-4 py-4">
                    @include('livewire.admin.accounting.customers._form')
                    <div class="flex gap-2 mt-4">
                        <flux:button wire:click="save" variant="primary" size="sm">Spara</flux:button>
                        <flux:button wire:click="$set('editing', false)" variant="ghost" size="sm">Avbryt</flux:button>
                    </div>
                </div>
                @else
                @php
                    $rows = array_filter([
                        ['Org.nr',    $customer->org_number],
                        ['E-post',    $customer->email],
                        ['Std.konto', $customer->defaultAccount ? $customer->defaultAccount->code . ' ' . $customer->defaultAccount->name : null],
                        ['Kopplad',   $customer->member ? $customer->member->first_name . ' ' . $customer->member->last_name : null],
                    ], fn($r) => !empty($r[1]));
                @endphp
                @foreach ($rows as [$label, $value])
                <div class="px-4 py-2.5 flex items-start justify-between gap-4 text-sm">
                    <span class="text-zinc-500 shrink-0">{{ $label }}</span>
                    <span class="text-zinc-800 dark:text-zinc-200 text-right font-mono text-xs">{{ $value }}</span>
                </div>
                @endforeach
                @endif
            </div>

            {{-- Stats --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
                <div class="px-4 py-3">
                    <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Statistik</h2>
                </div>
                @php
                    $s   = $this->stats;
                    $fmt = fn(int $ore) => number_format($ore / 100, 2, ',', ' ') . ' kr';
                @endphp
                <div class="px-4 py-2.5 flex justify-between text-sm">
                    <span class="text-zinc-500">Fakturor totalt</span>
                    <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $s['total'] }} st</span>
                </div>
                <div class="px-4 py-2.5 flex justify-between text-sm">
                    <span class="text-zinc-500">Totalt fakturerat</span>
                    <span class="font-mono text-zinc-800 dark:text-zinc-200">{{ $fmt($s['total_ore']) }}</span>
                </div>
                <div class="px-4 py-2.5 flex justify-between text-sm">
                    <span class="text-zinc-500">Betalt</span>
                    <span class="font-mono text-green-700 dark:text-green-400">{{ $fmt($s['paid_ore']) }}</span>
                </div>
                @if ($s['unpaid_ore'] > 0)
                <div class="px-4 py-2.5 flex justify-between text-sm">
                    <span class="text-zinc-500">Utestående</span>
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
                    <a href="{{ route('admin.accounting.invoices.receivable.create') }}" wire:navigate>
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
                                    <a href="{{ route('admin.accounting.invoices.receivable.show', $inv) }}" wire:navigate
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

        {{-- Webshop orders --}}
        @if ($this->orders->isNotEmpty())
        <div class="mt-6 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <div class="px-4 py-3 bg-zinc-50 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Webshopp-ordrar</h2>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider border-t border-zinc-200 dark:border-zinc-700">
                    <tr>
                        <th class="px-4 py-2.5 w-20">Order</th>
                        <th class="px-4 py-2.5 w-28">Datum</th>
                        <th class="px-4 py-2.5 text-right">Belopp</th>
                        <th class="px-4 py-2.5 w-28">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                    @foreach ($this->orders as $order)
                        @php
                            $orderStatusCls = match($order->status) {
                                'paid'      => 'text-green-700 dark:text-green-400',
                                'refunded'  => 'text-blue-600 dark:text-blue-400',
                                'cancelled' => 'text-zinc-400',
                                default     => 'text-amber-600 dark:text-amber-400',
                            };
                            $orderStatusLabel = match($order->status) {
                                'paid'      => 'Betald',
                                'refunded'  => 'Återbetald',
                                'cancelled' => 'Avbruten',
                                default     => 'Väntar',
                            };
                        @endphp
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="px-4 py-2.5 font-mono text-xs text-zinc-500">#{{ $order->id }}</td>
                            <td class="px-4 py-2.5 text-xs font-mono text-zinc-500">
                                {{ $order->created_at->format('d/m Y') }}
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs tabular-nums text-zinc-700 dark:text-zinc-300">
                                {{ number_format($order->total_ore / 100, 2, ',', ' ') }} kr
                            </td>
                            <td class="px-4 py-2.5 text-xs {{ $orderStatusCls }}">
                                {{ $orderStatusLabel }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

    </div>

</div>
