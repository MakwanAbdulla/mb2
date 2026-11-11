<?php

use App\Models\Shop\Order;
use App\Models\Shop\OrderFulfillment;
use App\Models\Shop\OrderLine;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Mina beställningar');

new class extends Component {

    #[Computed]
    public function orders()
    {
        return Order::where('user_id', Auth::id())
            ->with(['lines', 'lines.fulfillment', 'lines.discountUse.discountCode'])
            ->latest()
            ->get();
    }

    public function fulfillmentLabel(string $status): string
    {
        return match ($status) {
            OrderFulfillment::STATUS_PENDING    => 'Väntar',
            OrderFulfillment::STATUS_PROCESSING => 'Behandlas',
            OrderFulfillment::STATUS_SHIPPED    => 'Skickat',
            OrderFulfillment::STATUS_DELIVERED  => 'Levererat',
            OrderFulfillment::STATUS_CANCELLED  => 'Avbrutet',
            default                             => $status,
        };
    }

    public function fulfillmentClasses(string $status): string
    {
        return match ($status) {
            OrderFulfillment::STATUS_PENDING,
            OrderFulfillment::STATUS_PROCESSING => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
            OrderFulfillment::STATUS_SHIPPED    => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
            OrderFulfillment::STATUS_DELIVERED  => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            OrderFulfillment::STATUS_CANCELLED  => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
            default                             => 'bg-zinc-100 text-zinc-500',
        };
    }

}; ?>

<x-slot:header>
    <x-page-header title="Mina beställningar" subtitle="Fakturor och orderhistorik." />
</x-slot:header>

<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">

    @if ($this->orders->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <x-bootstrap-icon name="receipt" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
            <p class="text-zinc-500 dark:text-zinc-400">Inga ordrar ännu.</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($this->orders as $order)
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                {{-- Order header --}}
                <div class="flex items-center justify-between gap-4 border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                            {{ $order->payment_reference }}
                        </span>
                        <span class="text-xs text-zinc-400">
                            {{ $order->created_at->format('d/m Y') }}
                        </span>
                        @if ($order->isPaid())
                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-300">
                                <x-bootstrap-icon name="check-circle" class="size-3" />
                                Betald
                            </span>
                        @elseif ($order->status === Order::STATUS_PENDING)
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                                <x-bootstrap-icon name="clock" class="size-3" />
                                Väntar betalning
                            </span>
                        @elseif ($order->status === Order::STATUS_CANCELLED)
                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                <x-bootstrap-icon name="x-circle" class="size-3" />
                                Avbruten
                            </span>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">
                            {{ number_format($order->total_ore / 100, 2, ',', ' ') }} kr
                        </span>
                        <a href="{{ route('invoice.view', $order) }}" target="_blank"
                           class="inline-flex items-center gap-1 rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs font-medium text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-600">
                            <x-bootstrap-icon name="file-text" class="size-3" />
                            PDF
                        </a>
                        @if ($order->isPending())
                            <a href="{{ route('membership.payment') }}" wire:navigate
                               class="inline-flex items-center gap-1 rounded-md bg-complement px-2.5 py-1 text-xs font-medium text-white hover:bg-complement/90">
                                <x-bootstrap-icon name="credit-card" class="size-3" />
                                Betala
                            </a>
                        @endif
                    </div>
                </div>

                {{-- Lines --}}
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($order->lines as $line)
                    <div class="px-5 py-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-zinc-700 dark:text-zinc-300">
                                {{ $line->quantity > 1 ? $line->quantity . ' × ' : '' }}{{ $line->description }}
                            </p>

                            @if ($line->fulfillment)
                            <span class="mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $this->fulfillmentClasses($line->fulfillment->status) }}">
                                @if ($line->fulfillment->status === OrderFulfillment::STATUS_SHIPPED)
                                    <x-bootstrap-icon name="truck" class="size-3" />
                                @elseif ($line->fulfillment->status === OrderFulfillment::STATUS_DELIVERED)
                                    <x-bootstrap-icon name="check2-circle" class="size-3" />
                                @else
                                    <x-bootstrap-icon name="box-seam" class="size-3" />
                                @endif
                                {{ $this->fulfillmentLabel($line->fulfillment->status) }}
                                @if ($line->fulfillment->tracking_number && $line->fulfillment->status === OrderFulfillment::STATUS_SHIPPED)
                                    · {{ $line->fulfillment->tracking_number }}
                                @endif
                            </span>
                            @endif
                        </div>

                        <span class="text-sm font-medium text-zinc-900 dark:text-white">
                            {{ number_format($line->unit_price_ore * $line->quantity / 100, 2, ',', ' ') }} kr
                        </span>
                    </div>
                    @if($line->discount_ore > 0)
                    @php $discountCode = $line->discountUse?->discountCode?->code; @endphp
                    <div class="mt-1 flex justify-between text-sm">
                        <span class="text-green-700 dark:text-green-400">Rabatt{{ $discountCode ? ' ' . $discountCode : '' }}</span>
                        <span class="font-medium text-green-700 dark:text-green-400">−{{ number_format($line->discount_ore / 100, 2, ',', ' ') }} kr</span>
                    </div>
                    @endif
                    </div>
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>
    @endif

</div>
