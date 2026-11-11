<?php

use App\Models\Accounting\Invoice;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Fakturor som väntar attest');

new class extends Component {

    public function mount(): void
    {
        abort_unless(
            Auth::user()->canAny(['accounting.approve', 'accounting.manage', 'accounting.view']),
            403
        );
    }

    #[Computed]
    public function pendingInvoices()
    {
        return Invoice::with(['vendor', 'createdBy', 'approvalLogs.approvedBy'])
            ->where('status', Invoice::STATUS_PENDING_APPROVAL)
            ->orderBy('created_at')
            ->get();
    }

    #[Computed]
    public function canApprove(): bool
    {
        return Auth::user()->can('accounting.approve');
    }
};
?>

<div class="space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Väntar attest</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Fakturor inskickade för attest.
            </p>
        </div>
        @if ($this->pendingInvoices->isNotEmpty())
            <span class="inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900/30 px-3 py-1 text-sm font-medium text-amber-700 dark:text-amber-300">
                {{ $this->pendingInvoices->count() }} väntar
            </span>
        @endif
    </div>

    @if ($this->pendingInvoices->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-200 dark:border-zinc-700 py-16 text-center">
            <x-bootstrap-icon name="check2-circle" class="size-8 text-green-400 mx-auto mb-2" />
            <p class="text-sm text-zinc-500">Inga fakturor väntar på attest.</p>
        </div>
    @else
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800 text-xs font-medium text-zinc-500 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Faktura</th>
                        <th class="px-4 py-2.5 text-left">Leverantör</th>
                        <th class="px-4 py-2.5 text-right">Belopp</th>
                        <th class="px-4 py-2.5 text-left">Förfaller</th>
                        <th class="px-4 py-2.5 text-left">Inskickad av</th>
                        <th class="px-4 py-2.5 text-left">Attest</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($this->pendingInvoices as $invoice)
                    @php
                        $total      = ($invoice->amount_ex_vat ?? 0) + ($invoice->vat_amount ?? 0);
                        $overdue    = $invoice->isOverdue();
                        $approvals  = $invoice->approvalLogs->where('action', 'approved')->count();
                    @endphp
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.accounting.invoices.payable.show', $invoice) }}"
                               wire:navigate
                               class="font-medium text-zinc-800 dark:text-zinc-200 hover:text-brand dark:hover:text-brand">
                                {{ $invoice->invoice_number ?: '#' . $invoice->id }}
                            </a>
                            @if ($invoice->description)
                                <p class="text-xs text-zinc-400 truncate max-w-48">{{ $invoice->description }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">
                            {{ $invoice->vendor?->name ?? '–' }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap">
                            {{ number_format($total / 100, 2, ',', ' ') }} kr
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-xs
                            {{ $overdue ? 'text-red-600 dark:text-red-400 font-medium' : 'text-zinc-500' }}">
                            @if ($invoice->due_date)
                                {{ $invoice->due_date->format('d/m Y') }}
                                @if ($overdue) <span class="ml-1">(förfallen)</span> @endif
                            @else
                                –
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-zinc-500">
                            {{ $invoice->createdBy?->name ?? '–' }}
                            <span class="block text-zinc-400">{{ $invoice->created_at->format('d/m Y') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @if ($approvals > 0)
                                <span class="text-xs text-green-600 dark:text-green-400">
                                    {{ $approvals }} godkänd{{ $approvals === 1 ? '' : 'e' }}
                                </span>
                            @else
                                <span class="text-xs text-zinc-400">Ingen ännu</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.accounting.invoices.payable.show', $invoice) }}"
                               wire:navigate
                               class="inline-flex items-center gap-1.5 text-xs font-medium
                                   {{ $this->canApprove
                                       ? 'text-brand hover:text-brand-dark dark:text-brand dark:hover:text-yellow-300'
                                       : 'text-zinc-400' }}">
                                @if ($this->canApprove)
                                    <x-bootstrap-icon name="check2-circle" class="size-4" />
                                    Attestera
                                @else
                                    <x-bootstrap-icon name="eye" class="size-4" />
                                    Visa
                                @endif
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</div>
