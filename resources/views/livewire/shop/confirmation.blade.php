<?php

use App\Models\Shop\Order;
use App\Models\Shop\OrderFulfillment;
use App\Models\Shop\OrderLine;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Orderbekräftelse');

new class extends Component {

    public Order $order;

    public function mount(): void
    {
        // Allow access via session (guest) or user ownership
        $sessionOrderId = session('shop_order_id');
        $user           = auth()->user();

        $allowed = ($sessionOrderId && (int) $sessionOrderId === $this->order->id)
            || ($user && $this->order->user_id === $user->id);

        abort_unless($allowed, 403);

        // Only show paid orders on this page
        abort_unless($this->order->isPaid(), 404);

        // Clear the session key now that they've landed on confirmation
        if ($sessionOrderId && (int) $sessionOrderId === $this->order->id) {
            session()->forget('shop_order_id');
        }

        $this->order->load('lines.fulfillment');
    }

}; ?>

<x-slot:header>
    <x-page-header title="Tack för din beställning!" />
</x-slot:header>

<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6">

    {{-- Success banner --}}
    <div class="mb-8 flex items-center gap-4 rounded-xl border border-green-200 bg-green-50 p-5 dark:border-green-800 dark:bg-green-900/20">
        <x-bootstrap-icon name="check-circle-fill" class="size-8 shrink-0 text-green-600 dark:text-green-400" />
        <div>
            <p class="font-semibold text-green-800 dark:text-green-300">Betalning mottagen</p>
            <p class="text-sm text-green-700 dark:text-green-400">
                En orderbekräftelse skickas till
                {{ $order->user?->email ?? $order->guest_email ?? 'din e-postadress' }}.
            </p>
        </div>
    </div>

    {{-- Order details --}}
    <div class="mb-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                    Order {{ $order->payment_reference }}
                </h2>
                <span class="text-xs text-zinc-400 dark:text-zinc-500">
                    {{ $order->paid_at?->locale('sv')->isoFormat('D MMMM YYYY, HH:mm') }}
                </span>
            </div>
        </div>

        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach ($order->lines as $line)
            <div class="flex items-center justify-between px-5 py-3 text-sm">
                <div>
                    <span class="text-zinc-700 dark:text-zinc-300">
                        {{ $line->quantity > 1 ? $line->quantity . ' × ' : '' }}{{ $line->description }}
                    </span>
                    @if ($line->category === OrderLine::CATEGORY_SHOP && $line->fulfillment)
                    <p class="mt-0.5 text-xs text-zinc-400">
                        @if ($line->fulfillment->status === OrderFulfillment::STATUS_SHIPPED)
                            <x-bootstrap-icon name="truck" class="mr-1 inline size-3" />
                            Skickat
                            @if ($line->fulfillment->tracking_number)
                                · Spårning: {{ $line->fulfillment->tracking_number }}
                            @endif
                        @elseif ($line->fulfillment->status === OrderFulfillment::STATUS_DELIVERED)
                            <x-bootstrap-icon name="check2-circle" class="mr-1 inline size-3 text-green-500" />
                            Levererat
                        @else
                            Behandlas
                        @endif
                    </p>
                    @endif
                </div>
                <span class="font-medium text-zinc-900 dark:text-white">
                    {{ number_format($line->line_total_ore / 100, 2, ',', ' ') }} kr
                </span>
            </div>
            @endforeach

            <div class="flex items-center justify-between px-5 py-4">
                <span class="font-semibold text-zinc-900 dark:text-white">Totalt</span>
                <span class="text-xl font-bold text-zinc-900 dark:text-white">
                    {{ number_format($order->total_ore / 100, 2, ',', ' ') }} kr
                </span>
            </div>
        </div>
    </div>

    {{-- CTAs --}}
    <div class="flex flex-wrap gap-3">
        <a href="{{ route('shop.index') }}" wire:navigate class="btn btn-primary">
            Fortsätt handla
        </a>
        @auth
        <a href="{{ route('shop.my-orders') }}" wire:navigate class="btn btn-secondary">
            Mina beställningar
        </a>
        @endauth
    </div>

</div>
