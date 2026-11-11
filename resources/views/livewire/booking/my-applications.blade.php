<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Mina lokalansökningar</h1>
        <a href="{{ route('booking.venue-application.create') }}" wire:navigate
           class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
            + Ny ansökan
        </a>
    </div>

    {{-- Filterflikar --}}
    <div class="flex gap-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 p-1 w-fit">
        @foreach (['active' => 'Aktiva', 'all' => 'Alla'] as $val => $label)
            <button wire:click="$set('filter', '{{ $val }}')"
                    class="px-4 py-1.5 rounded-md text-sm font-medium transition-colors
                           {{ $filter === $val
                               ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm'
                               : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($applications->isEmpty())
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-10 text-center">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Inga ansökningar hittades.</p>
            <a href="{{ route('booking.venue-application.create') }}" wire:navigate
               class="mt-3 inline-block text-sm text-complement dark:text-complement-light hover:underline">
                Skicka din första ansökan
            </a>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($applications as $application)
                @php
                    [$border, $bg, $cls, $lbl] = match($application->status) {
                        'pending'   => ['border-amber-200 dark:border-amber-800', 'bg-amber-50 dark:bg-amber-950/50', 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300', 'Väntande'],
                        'approved'  => ['border-green-200 dark:border-green-800', 'bg-green-50 dark:bg-green-950/50', 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-300', 'Godkänd'],
                        'rejected'  => ['border-red-200 dark:border-red-800', 'bg-red-50 dark:bg-red-950/50', 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-400', 'Nekad'],
                        'cancelled' => ['border-zinc-200 dark:border-zinc-700', 'bg-zinc-50 dark:bg-zinc-800/50', 'bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-400', 'Avbruten'],
                        default     => ['border-zinc-200 dark:border-zinc-700', 'bg-white dark:bg-zinc-900', 'bg-zinc-100 dark:bg-zinc-700 text-zinc-500', $application->status],
                    };
                @endphp
                <div class="rounded-xl border {{ $border }} {{ $bg }} shadow-sm p-5">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="font-semibold text-zinc-900 dark:text-white">{{ $application->event_name }}</h3>
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $cls }}">{{ $lbl }}</span>
                            </div>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                Skickad {{ $application->created_at->locale('sv')->isoFormat('D MMMM YYYY') }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Period</p>
                            <p class="mt-0.5 text-zinc-700 dark:text-zinc-300 tabular-nums text-xs">
                                {{ $application->starts_at->locale('sv')->isoFormat('D MMM YYYY') }}, {{ $application->starts_at->format('H:i') }}
                                –
                                {{ $application->ends_at->locale('sv')->isoFormat('D MMM YYYY') }}, {{ $application->ends_at->format('H:i') }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Deltagare</p>
                            <p class="mt-0.5 text-zinc-700 dark:text-zinc-300">{{ $application->participants }} pers.</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Lokaler</p>
                            <div class="mt-0.5 flex flex-wrap gap-1">
                                @foreach ($application->resource_ids as $rid)
                                    <span class="rounded-full bg-zinc-100 dark:bg-zinc-700 px-2 py-0.5 text-xs text-zinc-600 dark:text-zinc-300">
                                        {{ $resourceNames->get($rid) ?? '#'.$rid }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    @if ($application->status === 'approved' && ! empty($application->booking_ids))
                        <p class="mt-3 text-xs text-green-700 dark:text-green-400">
                            ✓ {{ count($application->booking_ids) }} bokning(ar) skapade automatiskt.
                        </p>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $applications->links() }}</div>
    @endif

</div>
