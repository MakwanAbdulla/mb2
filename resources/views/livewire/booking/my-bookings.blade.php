<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center justify-between gap-4 flex-wrap">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Mina bokningar</h1>
        <div class="flex items-center gap-2 flex-wrap">
            @if (auth()->user()->ical_token)
                <a href="{{ route('booking.ical.user', ['token' => auth()->user()->ical_token]) }}"
                   title="Prenumerera på dina bokningar i din kalenderapp"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-1.5 text-sm text-zinc-600 dark:text-zinc-300 hover:border-brand hover:text-brand transition-colors">
                    <x-bootstrap-icon name="calendar3" class="size-4 shrink-0" />
                    iCal-prenumeration
                </a>
            @endif
            <button wire:click="toggleReminders"
                    title="Få ett mail dagen innan varje bokning startar"
                    class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors
                           {{ auth()->user()->reminder_emails
                               ? 'border-brand bg-brand text-brand-dark hover:bg-brand-saffron'
                               : 'border-zinc-300 dark:border-zinc-600 text-zinc-500 dark:text-zinc-400 hover:border-zinc-400' }}">
                <x-bootstrap-icon name="bell" class="size-4 shrink-0" />
                Påminnelse-epost
                @if (auth()->user()->reminder_emails)
                    <span class="text-xs">På</span>
                @endif
            </button>
        </div>
    </div>

    {{-- Venue application cancel block --}}
    @if ($venueApplicationId)
        <div class="rounded-xl border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/50 p-4">
            <p class="text-sm font-medium text-amber-800 dark:text-amber-300 mb-1">Bokningen tillhör en lokalansökan</p>
            <p class="text-sm text-amber-700 dark:text-amber-400 mb-3">
                Den här bokningen skapades när din ansökan godkändes och kan inte avbokas enskilt.
                Avboka hela ansökan om du vill ta tillbaka lokalen.
            </p>
            <div class="flex gap-3 flex-wrap">
                <button wire:click="cancelVenueApplication"
                        wire:confirm="Om du avbokar måste du skicka in en ny ansökan för att boka lokalen igen. Vill du fortsätta?"
                        class="rounded-lg bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 text-sm font-medium transition-colors">
                    Avboka hela ansökan
                </button>
                <button wire:click="dismissCancelBlock"
                        class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 transition-colors">
                    Avbryt
                </button>
            </div>
        </div>
    @endif

    {{-- Search --}}
    <div class="relative">
        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
            <x-bootstrap-icon name="search" class="size-4 text-zinc-400" />
        </div>
        <input wire:model.live.debounce.300ms="search" type="text"
               placeholder="Sök på rubrik eller rum…"
               class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 py-2 pl-9 pr-3 text-sm shadow-sm focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand-saffron">
    </div>

    {{-- Filter tabs --}}
    <div class="border-b border-zinc-200 dark:border-zinc-700">
        <nav class="-mb-px flex gap-6">
            @foreach (['upcoming' => 'Kommande', 'all' => 'Alla', 'past' => 'Historik'] as $val => $label)
                <button wire:click="$set('filter', '{{ $val }}')"
                        class="pb-3 text-sm font-medium border-b-2 transition-colors
                               {{ $filter === $val
                                   ? 'border-brand text-brand-orange dark:text-brand'
                                   : 'border-transparent text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200' }}">
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- List --}}
    @if ($bookings->isEmpty())
        <div class="text-center py-12">
            <p class="text-zinc-500 dark:text-zinc-400">Inga bokningar hittades.</p>
            <a href="{{ route('booking.create') }}" wire:navigate
               class="mt-3 inline-block text-sm text-complement dark:text-complement-light hover:underline">
                Boka ett rum
            </a>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($bookings as $booking)
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4
                            flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <div class="h-10 w-1 rounded-full flex-shrink-0"
                             style="background-color: {{ $booking->resource->color ?? '#6366f1' }}"></div>
                        <div class="min-w-0">
                            <p class="font-medium text-zinc-900 dark:text-white truncate">{{ $booking->title }}</p>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $booking->resource->name }}</p>
                            <p class="text-xs text-zinc-400 mt-0.5 tabular-nums">
                                {{ $booking->starts_at->locale('sv')->isoFormat('ddd D MMM, H:mm') }}
                                –
                                @if ($booking->ends_at->isSameDay($booking->starts_at))
                                    {{ $booking->ends_at->format('H:i') }}
                                @else
                                    {{ $booking->ends_at->locale('sv')->isoFormat('ddd D MMM, H:mm') }}
                                @endif
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 shrink-0 flex-wrap">
                        @php
                            [$cls, $lbl] = match($booking->status) {
                                'confirmed' => ['bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-300', 'Bekräftad'],
                                'pending'   => ['bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300', 'Väntar'],
                                'cancelled' => ['bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-400', 'Avbokad'],
                                'rejected'  => ['bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-400', 'Nekad'],
                                default     => ['bg-zinc-100 dark:bg-zinc-700 text-zinc-500', $booking->status],
                            };
                        @endphp
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $cls }}">
                            {{ $lbl }}
                        </span>
                        <a href="{{ route('booking.create') }}?rum={{ $booking->resource->slug }}&kopiera={{ $booking->id }}"
                           class="text-xs text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 hover:underline">
                            Kopiera
                        </a>
                        @if (! $booking->starts_at->isPast() && ! in_array($booking->status, ['cancelled', 'rejected']))
                            <a href="{{ route('booking.edit', $booking) }}" wire:navigate
                               class="text-xs text-complement dark:text-complement-light hover:underline">
                                Redigera
                            </a>
                            <button wire:click="cancel({{ $booking->id }})"
                                    wire:confirm="Är du säker på att du vill avboka?"
                                    class="text-xs text-red-600 dark:text-red-400 hover:underline">
                                Avboka
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $bookings->links() }}</div>
    @endif

</div>
