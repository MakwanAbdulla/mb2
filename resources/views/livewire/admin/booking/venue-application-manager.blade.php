<div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center gap-3">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Lokalansökningar</h1>
        @if ($pendingCount > 0)
            <span class="rounded-full bg-red-100 dark:bg-red-900 px-2.5 py-0.5 text-xs font-semibold text-red-700 dark:text-red-300">
                {{ $pendingCount }} väntande
            </span>
        @endif
    </div>

    <div class="flex gap-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 p-1 w-fit">
        @foreach (['pending' => 'Väntande', 'approved' => 'Godkända', 'rejected' => 'Nekade', 'all' => 'Alla'] as $val => $label)
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
        <p class="text-zinc-500 dark:text-zinc-400 text-sm py-6 text-center">Inga ansökningar hittades.</p>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                            <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Inkommit</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Arrangör</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Period</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Deltagare</th>
                            @if ($filter === 'all')
                                <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Status</th>
                            @endif
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($applications as $application)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                <td class="px-4 py-3 text-zinc-400 text-xs whitespace-nowrap">
                                    {{ $application->created_at->locale('sv')->isoFormat('D MMM YYYY') }}
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-zinc-900 dark:text-white">{{ $application->event_name }}</p>
                                    <p class="text-xs text-zinc-400">{{ $application->responsible_name }}</p>
                                </td>
                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300 whitespace-nowrap text-xs tabular-nums">
                                    {{ $application->starts_at->locale('sv')->isoFormat('D MMM') }}
                                    {{ $application->starts_at->format('H:i') }}
                                    –
                                    {{ $application->ends_at->locale('sv')->isoFormat('D MMM') }}
                                    {{ $application->ends_at->format('H:i') }}
                                </td>
                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300 text-center">
                                    {{ $application->participants }}
                                </td>
                                @if ($filter === 'all')
                                    <td class="px-4 py-3">
                                        @php
                                            [$cls, $lbl] = match($application->status) {
                                                'pending'   => ['bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300', 'Väntande'],
                                                'approved'  => ['bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-300', 'Godkänd'],
                                                'rejected'  => ['bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-400', 'Nekad'],
                                                'cancelled' => ['bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-400', 'Avbruten'],
                                                default     => ['bg-zinc-100 dark:bg-zinc-700 text-zinc-500', $application->status],
                                            };
                                        @endphp
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $cls }}">
                                            {{ $lbl }}
                                        </span>
                                    </td>
                                @endif
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('booking.admin.venue-applications.show', $application) }}" wire:navigate
                                       class="text-xs text-complement dark:text-complement-light hover:underline">
                                        Granska
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">{{ $applications->links() }}</div>
    @endif

</div>
