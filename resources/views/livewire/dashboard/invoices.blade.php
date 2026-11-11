<?php

use App\Models\Membership\MembershipPeriod;
use App\Models\Shop\Order;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Livewire\Volt\{layout};

layout('components.layouts.public');

new class extends Component {
    use WithPagination;

    public function orders()
    {
        $user = auth()->user();

        return Order::with('lines')
            ->where('user_id', $user->id)
            ->latest()
            ->paginate(20);
    }

    /**
     * If the current member belongs to a family and their membership period
     * is covered by another member's order, return info about the billing contact.
     * Returns ['name' => string, 'order' => Order] or null.
     */
    #[Computed]
    public function familyBilling(): ?array
    {
        $member = auth()->user()->member ?? null;
        if (! $member?->family_group_id) {
            return null;
        }

        $period = MembershipPeriod::where('member_id', $member->id)
            ->whereNotNull('order_id')
            ->whereHas('order', fn ($q) => $q->where('user_id', '!=', auth()->id()))
            ->with('order.user.member')
            ->orderByDesc('year')
            ->first();

        if (! $period?->order) {
            return null;
        }

        $billingMember = $period->order->user?->member;
        $name = $billingMember?->full_name ?? $period->order->user?->name ?? 'okänd';

        return ['name' => $name, 'order' => $period->order];
    }
}; ?>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">

    {{-- Family billing notice --}}
    @if($this->familyBilling)
        @php $fb = $this->familyBilling; @endphp
        <div class="mb-6 flex items-start gap-3 rounded-lg border border-complement/20 bg-complement/5 px-4 py-3 dark:border-complement-light/20 dark:bg-complement/10">
            <x-bootstrap-icon name="house-heart" class="mt-0.5 size-4 shrink-0 text-complement-light" />
            <div class="text-sm text-zinc-700 dark:text-zinc-300">
                Din årsavgift ingår i en familjefaktura betald av
                <span class="font-semibold">{{ $fb['name'] }}</span>.
                <a href="{{ route('invoice.view', $fb['order']) }}"
                   target="_blank"
                   class="ml-1 text-complement-light underline hover:no-underline">
                    Visa faktura
                </a>
            </div>
        </div>
    @endif

    @php
        $orders = $this->orders();
    @endphp

    @if($orders->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <x-bootstrap-icon name="receipt" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Inga fakturor ännu.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead>
                    <tr class="bg-zinc-50 dark:bg-zinc-800/80">
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Faktura</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Datum</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Belopp</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Status</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Åtgärder</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                    @foreach($orders as $order)
                        @php
                            // Load family members covered by this order (for family invoices)
                            $coveredMembers = \App\Models\Membership\MembershipPeriod::where('order_id', $order->id)
                                ->with('member')
                                ->get()
                                ->pluck('member')
                                ->filter();
                        @endphp
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/30">
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-800 dark:text-zinc-100">{{ $order->payment_reference }}</div>
                                @if($order->ocr_number)
                                    <div class="text-xs text-zinc-400 dark:text-zinc-500">OCR: {{ $order->ocr_number }}</div>
                                @endif
                                @if($coveredMembers->count() > 1)
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @foreach($coveredMembers as $cm)
                                            <span class="inline-flex items-center rounded-full bg-complement/10 px-2 py-0.5 text-xs text-complement-light dark:bg-complement/20">
                                                {{ $cm->first_name }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $order->created_at->format('d/m Y') }}
                                @if($order->isPaid() && $order->paid_at)
                                    <div class="text-green-600 dark:text-green-400">Betald {{ $order->paid_at->format('d/m Y') }}</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-zinc-800 dark:text-zinc-100">
                                {{ number_format($order->total_ore / 100, 2, ',', ' ') }} kr
                            </td>
                            <td class="px-4 py-3">
                                @if($order->isPaid())
                                    <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-300">
                                        <x-bootstrap-icon name="check-circle" class="size-3" />
                                        Betald
                                    </span>
                                @elseif($order->status === 'pending')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                                        <x-bootstrap-icon name="clock" class="size-3" />
                                        Väntar betalning
                                    </span>
                                @elseif($order->status === 'cancelled')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                        <x-bootstrap-icon name="x-circle" class="size-3" />
                                        Avbruten
                                    </span>
                                @elseif($order->status === 'refunded')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-300">
                                        <x-bootstrap-icon name="arrow-counterclockwise" class="size-3" />
                                        Återbetald
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('invoice.view', $order) }}"
                                       target="_blank"
                                       class="inline-flex items-center gap-1 rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-600">
                                        <x-bootstrap-icon name="eye" class="size-3" />
                                        Visa
                                    </a>
                                    <a href="{{ route('invoice.download', $order) }}"
                                       class="inline-flex items-center gap-1 rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-600">
                                        <x-bootstrap-icon name="download" class="size-3" />
                                        PDF
                                    </a>
                                    @if($order->isPending())
                                        <a href="{{ route('membership.payment') }}"
                                           wire:navigate
                                           class="inline-flex items-center gap-1 rounded-md bg-complement px-2.5 py-1.5 text-xs font-medium text-white hover:bg-complement/90">
                                            <x-bootstrap-icon name="credit-card" class="size-3" />
                                            Betala
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($orders->hasPages())
            <div class="mt-4">
                {{ $orders->links() }}
            </div>
        @endif
    @endif
</div>
