<div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Bokningar</h1>
        <a href="{{ route('booking.create') }}" wire:navigate
           class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
            Ny bokning
        </a>
    </div>

    {{-- Filter tabs --}}
    <div class="flex gap-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 p-1 w-fit">
        @foreach (['upcoming' => 'Kommande', 'pending' => 'Väntar', 'past' => 'Historik', 'all' => 'Alla'] as $val => $label)
            <button wire:click="$set('filter', '{{ $val }}')"
                    class="px-4 py-1.5 rounded-md text-sm font-medium transition-colors
                           {{ $filter === $val
                               ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm'
                               : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Bulk actions --}}
    @if (count($selected) > 0)
        <div class="flex items-center gap-3 rounded-lg bg-brand/10 border border-brand/30 px-4 py-2.5">
            <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ count($selected) }} markerade</span>
            <div class="flex gap-2 ml-auto">
                <button wire:click="bulkApprove" wire:confirm="Bekräfta alla markerade väntande bokningar?"
                        class="rounded-md bg-green-100 dark:bg-green-900 px-3 py-1.5 text-xs font-medium text-green-800 dark:text-green-200 hover:bg-green-200 dark:hover:bg-green-800 transition-colors">
                    Bekräfta
                </button>
                <button wire:click="bulkReject" wire:confirm="Neka alla markerade väntande bokningar?"
                        class="rounded-md bg-amber-100 dark:bg-amber-900 px-3 py-1.5 text-xs font-medium text-amber-800 dark:text-amber-200 hover:bg-amber-200 dark:hover:bg-amber-800 transition-colors">
                    Neka
                </button>
                <button wire:click="bulkCancel" wire:confirm="Avboka alla markerade kommande bokningar?"
                        class="rounded-md bg-red-100 dark:bg-red-950 px-3 py-1.5 text-xs font-medium text-red-700 dark:text-red-300 hover:bg-red-200 dark:hover:bg-red-900 transition-colors">
                    Avboka
                </button>
                <button wire:click="$set('selected', [])"
                        class="px-3 py-1.5 text-xs font-medium text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 transition-colors">
                    Avmarkera
                </button>
            </div>
        </div>
    @endif

    {{-- Table --}}
    @if ($bookings->isEmpty())
        <p class="text-zinc-500 dark:text-zinc-400 text-sm py-6 text-center">Inga bokningar.</p>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                            <th class="px-4 py-3 w-8">
                                <input type="checkbox"
                                       class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron"
                                       @checked(count($selected) > 0 && count($selected) === $bookings->count())
                                       wire:click="$set('selected', {{ count($selected) === $bookings->count() ? '[]' : json_encode($bookings->pluck('id')->toArray()) }})">
                            </th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Datum &amp; tid</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Rum</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Titel</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Person</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($bookings as $booking)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40 {{ in_array($booking->id, $selected) ? 'bg-brand/5' : '' }}">
                                <td class="px-4 py-3">
                                    <input type="checkbox" value="{{ $booking->id }}" wire:model.live="selected"
                                           class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron">
                                </td>
                                <td class="px-4 py-3 tabular-nums text-zinc-700 dark:text-zinc-300 whitespace-nowrap">
                                    <span class="{{ $booking->starts_at->isToday() ? 'text-brand-orange font-semibold' : '' }}">
                                        {{ $booking->starts_at->locale('sv')->isoFormat('D MMM') }}
                                    </span>
                                    <span class="text-zinc-400 ml-1">
                                        {{ $booking->starts_at->format('H:i') }}–{{ $booking->ends_at->format('H:i') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">{{ $booking->resource->name }}</td>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-zinc-900 dark:text-white">{{ $booking->title }}</p>
                                    <p class="text-xs text-zinc-400">{{ $booking->activity }}</p>
                                </td>
                                <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                                    {{ $booking->bookerName() }}
                                    @if($booking->section_id)
                                        <span class="ml-1 text-xs text-zinc-400">({{ $booking->user->name }})</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @php
                                        [$cls, $lbl] = match($booking->status) {
                                            'confirmed' => ['bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-300', 'Bekräftad'],
                                            'pending'   => ['bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300', 'Väntar'],
                                            'cancelled' => ['bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-400', 'Avbokad'],
                                            'rejected'  => ['bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-400', 'Nekad'],
                                            default     => ['bg-zinc-100 dark:bg-zinc-700 text-zinc-600', $booking->status],
                                        };
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $cls }}">
                                        {{ $lbl }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-3 whitespace-nowrap">
                                        @if ($booking->status === 'pending')
                                            <button wire:click="approve({{ $booking->id }})"
                                                    class="text-xs text-green-700 dark:text-green-400 hover:underline">
                                                Godkänn
                                            </button>
                                            <button wire:click="reject({{ $booking->id }})" wire:confirm="Neka denna bokning?"
                                                    class="text-xs text-amber-700 dark:text-amber-400 hover:underline">
                                                Neka
                                            </button>
                                        @endif
                                        <a href="{{ route('booking.admin.bookings.edit', $booking) }}" wire:navigate
                                           class="text-xs text-complement dark:text-complement-light hover:underline">
                                            Redigera
                                        </a>
                                        @if ($booking->ends_at->isFuture())
                                            <button wire:click="delete({{ $booking->id }})"
                                                    wire:confirm="{{ $booking->starts_at->isPast() ? 'Bokningen pågår just nu. Sätt sluttid till nu?' : 'Ta bort bokningen permanent?' }}"
                                                    class="text-xs text-red-600 dark:text-red-400 hover:underline">
                                                {{ $booking->starts_at->isPast() ? 'Avsluta nu' : 'Ta bort' }}
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">{{ $bookings->links() }}</div>
    @endif

</div>
