<?php

use App\Models\Accounting\Invoice;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Kundreskontra');

new class extends Component {

    public string $filterStatus = 'open';
    public string $search       = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['accounting.manage', 'accounting.view']), 403);
    }

    #[Computed]
    public function invoices()
    {
        return Invoice::receivable()
            ->when($this->filterStatus === 'open', fn ($q) =>
                $q->whereNotIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED])
            )
            ->when($this->filterStatus !== 'open' && $this->filterStatus !== 'all', fn ($q) =>
                $q->where('status', $this->filterStatus)
            )
            ->when($this->search, fn ($q) => $q->whereHas('customer', fn ($q) =>
                $q->where('name', 'like', "%{$this->search}%")
            )->orWhere('invoice_number', 'like', "%{$this->search}%"))
            ->with('customer')
            ->orderByDesc('invoice_date')
            ->get();
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'draft'     => 'Utkast',
            'posted'    => 'Bokförd',
            'paid'      => 'Betald',
            'cancelled' => 'Annullerad',
            default     => $status,
        };
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            'draft'     => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
            'posted'    => 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
            'paid'      => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
            'cancelled' => 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400',
            default     => 'bg-zinc-100 text-zinc-600',
        };
    }
};
?>

<div>

    <div class="mb-4 flex gap-3 flex-wrap">
        <flux:input wire:model.live="search" placeholder="Sök kund, fakturanr…" class="w-64" />
        <flux:select wire:model.live="filterStatus" class="w-44">
            <option value="open">Öppna (ej betald/annullerad)</option>
            <option value="draft">Utkast</option>
            <option value="posted">Bokförda</option>
            <option value="paid">Betalda</option>
            <option value="cancelled">Annullerade</option>
            <option value="all">Alla</option>
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3">Kund</th>
                    <th class="px-4 py-3 w-44">Fakturanr</th>
                    <th class="px-4 py-3 w-28">Fakturadatum</th>
                    <th class="px-4 py-3 w-28">Förfaller</th>
                    <th class="px-4 py-3 w-32 text-right">Belopp</th>
                    <th class="px-4 py-3 w-32 text-center">Status</th>
                    <th class="px-4 py-3 w-16"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->invoices as $inv)
                    @php
                        $overdue = $inv->due_date && $inv->due_date->isPast()
                            && ! in_array($inv->status, ['paid', 'cancelled']);
                    @endphp
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">
                            {{ $inv->customer?->name ?? '–' }}
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-300">
                            {{ $inv->invoice_number ?? '–' }}
                        </td>
                        <td class="px-4 py-3 text-xs text-zinc-500">
                            {{ $inv->invoice_date->format('d/m Y') }}
                        </td>
                        <td class="px-4 py-3 text-xs {{ $overdue ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-zinc-500' }}">
                            {{ $inv->due_date?->format('d/m Y') ?? '–' }}
                            @if ($overdue) <span class="block text-xs font-normal">Förfallen</span> @endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-sm font-medium">
                            {{ format_ore($inv->total) }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $this->statusColor($inv->status) }}">
                                {{ $this->statusLabel($inv->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.accounting.invoices.receivable.show', $inv) }}" wire:navigate
                               class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                Visa
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-400">
                            Inga kundfakturor hittades.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
