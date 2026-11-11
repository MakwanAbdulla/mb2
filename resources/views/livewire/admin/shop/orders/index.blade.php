<?php

use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Butiksordrar');

new class extends Component {

    public string $search          = '';
    public string $filterStatus    = '';
    public string $filterFulfill   = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('shop.manage'), 403);
    }

    #[Computed]
    public function orders()
    {
        return Order::with(['user', 'lines.fulfillment'])
            ->whereHas('lines', fn ($q) => $q->where('category', OrderLine::CATEGORY_SHOP))
            ->when($this->search, function ($q) {
                $like = "%{$this->search}%";
                $q->where(fn ($q2) =>
                    $q2->where('payment_reference', 'like', $like)
                       ->orWhereHas('user', fn ($q3) => $q3->where('name', 'like', $like))
                );
            })
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->latest()
            ->paginate(30);
    }

}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Butiksordrar</h1>
    </div>

    <div class="mb-4 flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Sök referens eller kund…" class="w-64" />
        <flux:select wire:model.live="filterStatus" class="w-44">
            <option value="">Alla statusar</option>
            <option value="{{ Order::STATUS_PENDING }}">Väntar betalning</option>
            <option value="{{ Order::STATUS_PAID }}">Betald</option>
            <option value="{{ Order::STATUS_REFUNDED }}">Återbetald</option>
            <option value="{{ Order::STATUS_CANCELLED }}">Avbokad</option>
        </flux:select>
    </div>

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
            <thead class="bg-zinc-50 dark:bg-zinc-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Order</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Kund</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Datum</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">Belopp</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Betalning</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Leverans</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                @forelse ($this->orders as $order)
                @php
                    $shopLines    = $order->lines->where('category', 'shop');
                    $fulfillments = $shopLines->map(fn ($l) => $l->fulfillment)->filter();
                    $allShipped   = $fulfillments->count() > 0 && $fulfillments->every(fn ($f) => in_array($f->status, ['shipped', 'delivered', 'ready_for_pickup', 'picked_up']));
                    $anyPending   = $fulfillments->some(fn ($f) => $f->status === 'pending');
                @endphp
                <tr>
                    <td class="px-4 py-3">
                        <p class="font-mono text-sm text-zinc-900 dark:text-white">{{ $order->payment_reference }}</p>
                    </td>
                    <td class="px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">
                        {{ $order->user?->name ?? ($order->guest_name ?? '–') }}
                    </td>
                    <td class="px-4 py-3 text-sm text-zinc-500">
                        {{ $order->created_at->format('d/m Y') }}
                    </td>
                    <td class="px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-white">
                        {{ number_format($order->total_ore / 100, 2, ',', ' ') }} kr
                    </td>
                    <td class="px-4 py-3">
                        @if ($order->status === Order::STATUS_PAID)
                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                <x-bootstrap-icon name="check-circle" class="size-3" /> Betald
                            </span>
                        @elseif ($order->status === Order::STATUS_PENDING)
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                <x-bootstrap-icon name="clock" class="size-3" /> Väntar
                            </span>
                        @else
                            <span class="text-xs text-zinc-400">{{ $order->status }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if ($fulfillments->isEmpty())
                            <span class="text-xs text-zinc-400">–</span>
                        @elseif ($allShipped)
                            <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                                <x-bootstrap-icon name="truck" class="size-3" /> Skickad
                            </span>
                        @elseif ($anyPending)
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                <x-bootstrap-icon name="box-seam" class="size-3" /> Att skicka
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.shop.orders.show', $order) }}" wire:navigate class="btn btn-ghost text-xs">
                            Visa
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-400">
                        Inga ordrar hittades.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($this->orders->hasPages())
    <div class="mt-4">{{ $this->orders->links() }}</div>
    @endif
</div>
