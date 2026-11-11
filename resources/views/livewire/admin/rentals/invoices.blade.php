<?php

use App\Models\AuditLog;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Hyresfakturor');

new class extends Component {

    public string $filterStatus = 'pending';
    public string $search       = '';

    // Confirm mark-paid
    public ?int    $confirmOrderId     = null;
    public string  $confirmPayMethod   = Order::METHOD_MANUAL;

    public ?string $flash      = null;
    public bool    $flashError = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('rentals.manage'), 403);
    }

    #[Computed]
    public function orders()
    {
        return Order::with(['user', 'lines' => fn ($q) => $q->where('category', OrderLine::CATEGORY_RENTAL)])
            ->whereHas('lines', fn ($q) => $q->where('category', OrderLine::CATEGORY_RENTAL))
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->search, function ($q) {
                $like = "%{$this->search}%";
                $q->where(fn ($q2) =>
                    $q2->where('payment_reference', 'like', $like)
                       ->orWhereHas('user', fn ($q3) => $q3->where('name', 'like', $like))
                );
            })
            ->latest()
            ->paginate(40);
    }

    public function startMarkPaid(int $orderId): void
    {
        $this->confirmOrderId   = $orderId;
        $this->confirmPayMethod = Order::METHOD_MANUAL;
    }

    public function markPaid(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('rentals.manage'), 403);

        $order = Order::whereHas('lines', fn ($q) => $q->where('category', OrderLine::CATEGORY_RENTAL))
            ->findOrFail($this->confirmOrderId);

        $order->markPaid($this->confirmPayMethod);

        AuditLog::record(
            'rental_invoice_paid', 'rentals',
            "Order {$order->payment_reference} – {$order->user?->name}",
            $order,
            ['method' => $this->confirmPayMethod],
        );

        $this->confirmOrderId = null;
        $this->flash          = "Order {$order->payment_reference} markerad som betald.";
        $this->flashError     = false;
        unset($this->orders);
    }

    public function cancelMarkPaid(): void
    {
        $this->confirmOrderId = null;
    }
}; ?>

<div>
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">

        {{-- Flash --}}
        @if($flash)
            <div class="flex items-center gap-3 rounded-xl border px-5 py-3
                        {{ $flashError ? 'border-red-200 bg-red-50 dark:border-red-900/40 dark:bg-red-900/20' : 'border-green-200 bg-green-50 dark:border-green-900/40 dark:bg-green-900/20' }}"
                 x-data x-init="setTimeout(() => $wire.set('flash', null), 5000)">
                <x-bootstrap-icon name="{{ $flashError ? 'x-circle-fill' : 'check-circle-fill' }}"
                    class="size-4 shrink-0 {{ $flashError ? 'text-red-500' : 'text-green-600 dark:text-green-400' }}" />
                <span class="text-sm {{ $flashError ? 'text-red-800 dark:text-red-300' : 'text-green-800 dark:text-green-300' }}">
                    {{ $flash }}
                </span>
            </div>
        @endif

        {{-- Filters --}}
        <div class="flex flex-wrap gap-3">
            <input wire:model.live.debounce.300ms="search"
                   type="text"
                   placeholder="Sök referens eller namn…"
                   class="inp w-64" />

            <div class="flex rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden text-sm">
                @foreach(['pending' => 'Obetald', 'paid' => 'Betald', '' => 'Alla'] as $val => $label)
                    <button wire:click="$set('filterStatus', '{{ $val }}')"
                            @class([
                                'px-3 py-1.5 transition-colors',
                                'bg-brand text-brand-dark font-medium' => $filterStatus === $val,
                                'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800' => $filterStatus !== $val,
                            ])>
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Orders table --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Referens</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Medlem</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Hyra</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Datum</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">Belopp</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                    @forelse($this->orders as $order)
                        @php
                            $rentalLines = $order->lines->where('category', OrderLine::CATEGORY_RENTAL);
                            $totalOre    = $rentalLines->sum('line_total_ore');
                        @endphp
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="px-4 py-3 text-sm font-mono text-zinc-700 dark:text-zinc-300 whitespace-nowrap">
                                {{ $order->payment_reference }}
                            </td>
                            <td class="px-4 py-3 text-sm text-zinc-900 dark:text-white whitespace-nowrap">
                                {{ $order->user?->name ?? '–' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                                {{ $rentalLines->pluck('description')->join(', ') }}
                            </td>
                            <td class="px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                                {{ $order->created_at->format('d/m Y') }}
                            </td>
                            <td class="px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-white whitespace-nowrap">
                                {{ number_format($totalOre / 100, 2, ',', ' ') }} kr
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($order->status === Order::STATUS_PAID)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                        <x-bootstrap-icon name="check-circle-fill" class="size-3" />
                                        Betald {{ $order->paid_at?->format('d/m Y') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                        <x-bootstrap-icon name="clock" class="size-3" />
                                        Väntar betalning
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-2">
                                    @if($order->status === Order::STATUS_PENDING)
                                        <button wire:click="startMarkPaid({{ $order->id }})"
                                                class="btn btn-sm btn-primary">
                                            Markera betald
                                        </button>
                                    @endif
                                    <a href="{{ route('invoice.view', $order) }}"
                                       target="_blank"
                                       class="btn btn-sm">
                                        <x-bootstrap-icon name="file-earmark-text" class="size-3.5" />
                                        Faktura
                                    </a>
                                </div>
                            </td>
                        </tr>

                        {{-- Mark paid confirm row --}}
                        @if($confirmOrderId === $order->id)
                            <tr class="bg-brand/5 dark:bg-brand/10">
                                <td colspan="7" class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <span class="text-sm text-zinc-700 dark:text-zinc-300">
                                            Betalsätt:
                                        </span>
                                        <select wire:model="confirmPayMethod"
                                                class="inp py-1 text-sm w-44">
                                            <option value="{{ Order::METHOD_MANUAL }}">Manuell</option>
                                            <option value="{{ Order::METHOD_BANKGIRO }}">Bankgiro</option>
                                            <option value="{{ Order::METHOD_SWISH }}">Swish</option>
                                            <option value="{{ Order::METHOD_STRIPE }}">Stripe</option>
                                        </select>
                                        <button wire:click="markPaid" class="btn btn-sm btn-primary">Bekräfta</button>
                                        <button wire:click="cancelMarkPaid" class="btn btn-sm">Avbryt</button>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-sm text-zinc-400 dark:text-zinc-500">
                                Inga fakturor hittades.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $this->orders->links() }}

    </div>
</div>
