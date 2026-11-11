<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Möten</h1>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.meetings.templates.index') }}" wire:navigate
               class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">Mallar</a>
            @can('meetings.manage')
            <a href="{{ route('admin.meetings.create') }}" wire:navigate
               class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                + Nytt möte
            </a>
            @endcan
        </div>
    </div>

    {{-- Filter tabs --}}
    <div class="flex gap-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 p-1 w-fit">
        @foreach (['upcoming' => 'Kommande', 'past' => 'Tidigare', 'all' => 'Alla'] as $val => $label)
            <button wire:click="$set('filter', '{{ $val }}')"
                    class="rounded-md px-4 py-1.5 text-sm font-medium transition-colors
                           {{ $filter === $val
                               ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm'
                               : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($meetings->isEmpty())
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-10 text-center">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Inga möten att visa.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Möte</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Datum</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Sektion</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Status</th>
                        <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-300">Deltagare</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                    @foreach ($meetings as $m)
                        @php
                            $statusColor = match($m->status) {
                                'draft'            => 'text-zinc-400',
                                'notice_sent'      => 'text-blue-600 dark:text-blue-400',
                                'ongoing'          => 'text-green-600 dark:text-green-400',
                                'pending_approval' => 'text-amber-600 dark:text-amber-400',
                                'approved'         => 'text-teal-600 dark:text-teal-400',
                                'published'        => 'text-zinc-600 dark:text-zinc-300',
                                'cancelled'        => 'text-red-500 dark:text-red-400',
                                default            => 'text-zinc-500',
                            };
                        @endphp
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $m->title }}</div>
                                <div class="text-xs text-zinc-400">{{ $m->typeLabel() }}{{ $m->location ? ' · ' . $m->location : '' }}</div>
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300 tabular-nums text-xs">
                                {{ $m->scheduled_at->isoFormat('D MMM YYYY, HH:mm') }}
                            </td>
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400 text-xs">
                                {{ $m->section?->name ?? '–' }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs font-medium {{ $statusColor }}">
                                    {{ $m->statusLabel() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center tabular-nums text-zinc-500 dark:text-zinc-400">
                                {{ $m->attendees_count }}
                            </td>
                            <td class="px-4 py-3 text-right space-x-3">
                                <a href="{{ route('admin.meetings.show', $m) }}" wire:navigate
                                   class="text-xs text-complement dark:text-complement-light hover:underline">Öppna</a>
                                @can('meetings.manage')
                                <a href="{{ route('admin.meetings.edit', $m) }}" wire:navigate
                                   class="text-xs text-zinc-500 dark:text-zinc-400 hover:underline">Redigera</a>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</div>
